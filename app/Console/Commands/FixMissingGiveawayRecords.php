<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FixMissingGiveawayRecords extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tickets:fix-missing-records {--dry-run : Show what would be fixed without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fix orders that have no giveaway records but should have them based on cart data';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('🔍 DRY RUN MODE - No changes will be made');
        }

        $this->info('🔧 Starting fix for orders with missing giveaway records...');

        try {
            // Find completed orders that have NO giveaway records but have cart data
            $missingGiveawayOrders = DB::select('
                SELECT
                    o.id as order_id,
                    o.cart,
                    o.user_id,
                    o.created_at
                FROM orders o
                LEFT JOIN giveaway_order go ON o.id = go.order_id
                WHERE o.status = "completed"
                AND go.order_id IS NULL
                AND o.cart IS NOT NULL
                AND JSON_LENGTH(o.cart) > 0
                ORDER BY o.id ASC
            ');

            $this->info("📊 Found " . count($missingGiveawayOrders) . " orders with missing giveaway records to fix.");
            $this->newLine();

            if (count($missingGiveawayOrders) === 0) {
                $this->info('✅ No orders need fixing!');
                return Command::SUCCESS;
            }

            $fixedCount = 0;
            $errorCount = 0;

            $progressBar = $this->output->createProgressBar(count($missingGiveawayOrders));
            $progressBar->start();

            foreach ($missingGiveawayOrders as $order) {
                try {
                    // Parse cart data
                    $cart = json_decode($order->cart, true);
                    if (!$cart || !is_array($cart)) {
                        $this->error("Invalid cart data for order {$order->order_id}");
                        $errorCount++;
                        $progressBar->advance();
                        continue;
                    }

                    $giveawayRecords = [];

                    foreach ($cart as $cartItem) {
                        if (!isset($cartItem['id']) || !isset($cartItem['amount'])) {
                            $this->error("Invalid cart item data in order {$order->order_id}");
                            $errorCount++;
                            continue;
                        }

                        $giveawayId = $cartItem['id'];
                        $amount = $cartItem['amount'];

                        // Check if giveaway exists
                        $giveaway = DB::table('giveaways')->where('id', $giveawayId)->first();
                        if (!$giveaway) {
                            $this->error("Giveaway {$giveawayId} not found for order {$order->order_id}");
                            $errorCount++;
                            continue;
                        }

                        // Get all taken numbers for this giveaway
                        $takenNumbers = DB::table('giveaway_order')
                            ->where('giveaway_id', $giveawayId)
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
                            $this->error("Not enough available numbers for giveaway {$giveawayId}, order {$order->order_id}");
                            $errorCount++;
                            continue;
                        }

                        $giveawayRecords[] = [
                            'order_id' => $order->order_id,
                            'giveaway_id' => $giveawayId,
                            'numbers' => json_encode($availableNumbers),
                            'amount' => $amount,
                            'created_at' => $order->created_at,
                            'updated_at' => now()
                        ];

                        $this->line("  📝 Order {$order->order_id}: Will assign tickets " . implode(', ', $availableNumbers) . " for giveaway {$giveawayId}");
                    }

                    if (!$dryRun && !empty($giveawayRecords)) {
                        // Insert all giveaway records for this order
                        DB::table('giveaway_order')->insert($giveawayRecords);
                    }

                    if (!empty($giveawayRecords)) {
                        $this->line("  ✅ Order {$order->order_id}: " . ($dryRun ? 'Would create' : 'Created') . " " . count($giveawayRecords) . " giveaway records");
                        $fixedCount++;
                    } else {
                        $this->error("No valid giveaway records to create for order {$order->order_id}");
                        $errorCount++;
                    }

                } catch (\Exception $e) {
                    $this->error("Exception processing order {$order->order_id}: " . $e->getMessage());
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

            Log::info('FixMissingGiveawayRecords command completed', [
                'dry_run' => $dryRun,
                'fixed_count' => $fixedCount,
                'error_count' => $errorCount
            ]);

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error("CRITICAL ERROR: " . $e->getMessage());
            Log::error('FixMissingGiveawayRecords command failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return Command::FAILURE;
        }
    }
}