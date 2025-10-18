<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FixEmptyTicketNumbers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tickets:fix-empty-numbers {--dry-run : Show what would be fixed without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fix orders that have giveaway records but empty ticket numbers';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('🔍 DRY RUN MODE - No changes will be made');
        }

        $this->info('🔧 Starting ticket assignment fix for orders with empty giveaway numbers...');

        try {
            // Find all completed orders that have giveaway_order records but with empty numbers
            $problemOrders = DB::select('
                SELECT
                    o.id as order_id,
                    o.cart,
                    go.id as giveaway_order_id,
                    go.giveaway_id,
                    go.numbers,
                    JSON_LENGTH(go.numbers) as numbers_length
                FROM orders o
                INNER JOIN giveaway_order go ON o.id = go.order_id
                WHERE o.status = "completed"
                AND (JSON_LENGTH(go.numbers) = 0 OR go.numbers IS NULL OR go.numbers = "[]")
                ORDER BY o.id ASC
            ');

            $this->info("📊 Found " . count($problemOrders) . " orders with empty giveaway numbers to fix.");
            $this->newLine();

            if (count($problemOrders) === 0) {
                $this->info('✅ No orders need fixing!');
                return Command::SUCCESS;
            }

            $fixedCount = 0;
            $errorCount = 0;

            $progressBar = $this->output->createProgressBar(count($problemOrders));
            $progressBar->start();

            foreach ($problemOrders as $problemOrder) {
                try {
                    // Parse cart data
                    $cart = json_decode($problemOrder->cart, true);
                    if (!$cart || !is_array($cart)) {
                        $this->error("Invalid cart data for order {$problemOrder->order_id}");
                        $errorCount++;
                        $progressBar->advance();
                        continue;
                    }

                    // Find the cart item for this giveaway
                    // First try to match by the recorded giveaway_id, but if that fails,
                    // it means there's a mismatch and we should use the cart data as source of truth
                    $cartItem = null;
                    foreach ($cart as $item) {
                        if (isset($item['id']) && $item['id'] == $problemOrder->giveaway_id) {
                            $cartItem = $item;
                            break;
                        }
                    }

                    // If no match found, check if there's only one item in cart and use that
                    // This handles cases where the giveaway_order record has wrong giveaway_id
                    if (!$cartItem && count($cart) === 1) {
                        $cartItem = $cart[0];
                        $this->warn("Giveaway ID mismatch for order {$problemOrder->order_id}: recorded {$problemOrder->giveaway_id}, cart {$cartItem['id']}. Using cart data as source of truth.");

                        // Update the giveaway_order record to match the cart
                        if (!$dryRun) {
                            DB::table('giveaway_order')
                                ->where('id', $problemOrder->giveaway_order_id)
                                ->update(['giveaway_id' => $cartItem['id']]);
                        }

                        $problemOrder->giveaway_id = $cartItem['id']; // Update for the rest of the logic
                    }

                    if (!$cartItem) {
                        $this->error("Cart item not found for giveaway {$problemOrder->giveaway_id} in order {$problemOrder->order_id}");
                        $errorCount++;
                        $progressBar->advance();
                        continue;
                    }

                    $amount = $cartItem['amount'] ?? 1;

                    // Get available numbers for this giveaway
                    $giveaway = DB::table('giveaways')->where('id', $problemOrder->giveaway_id)->first();
                    if (!$giveaway) {
                        $this->error("Giveaway {$problemOrder->giveaway_id} not found");
                        $errorCount++;
                        $progressBar->advance();
                        continue;
                    }

                    // Get all taken numbers for this giveaway
                    $takenNumbers = DB::table('giveaway_order')
                        ->where('giveaway_id', $problemOrder->giveaway_id)
                        ->whereRaw('JSON_LENGTH(numbers) > 0')
                        ->pluck('numbers')
                        ->flatMap(function ($jsonNumbers) {
                            return json_decode($jsonNumbers, true) ?: [];
                        })
                        ->unique()
                        ->sort()
                        ->values()
                        ->toArray();

                    // Find available numbers
                    $availableNumbers = [];
                    for ($i = 1; $i <= $giveaway->ticketsTotal && count($availableNumbers) < $amount; $i++) {
                        if (!in_array($i, $takenNumbers)) {
                            $availableNumbers[] = $i;
                        }
                    }

                    if (count($availableNumbers) < $amount) {
                        $this->error("Not enough available numbers for giveaway {$problemOrder->giveaway_id}, order {$problemOrder->order_id}");
                        $errorCount++;
                        $progressBar->advance();
                        continue;
                    }

                    if (!$dryRun) {
                        // Update the giveaway_order record
                        DB::table('giveaway_order')
                            ->where('id', $problemOrder->giveaway_order_id)
                            ->update([
                                'numbers' => json_encode($availableNumbers),
                                'amount' => $amount,
                                'updated_at' => now()
                            ]);
                    }

                    $this->line("  ✅ Order {$problemOrder->order_id}: Assigned tickets " . implode(', ', $availableNumbers));
                    $fixedCount++;

                } catch (\Exception $e) {
                    $this->error("Exception processing order {$problemOrder->order_id}: " . $e->getMessage());
                    $errorCount++;
                }

                $progressBar->advance();
            }

            $progressBar->finish();
            $this->newLine(2);

            $this->info("🎉 Fix completed!");
            $this->info("✅ Successfully " . ($dryRun ? 'would fix' : 'fixed') . ": {$fixedCount} orders");
            $this->info("❌ Errors: {$errorCount} orders");

            if ($dryRun) {
                $this->warn("🔄 This was a dry run. Run without --dry-run to apply changes.");
            }

            Log::info('FixEmptyTicketNumbers command completed', [
                'dry_run' => $dryRun,
                'fixed_count' => $fixedCount,
                'error_count' => $errorCount
            ]);

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error("CRITICAL ERROR: " . $e->getMessage());
            Log::error('FixEmptyTicketNumbers command failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return Command::FAILURE;
        }
    }
}