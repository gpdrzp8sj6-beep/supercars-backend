<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\Giveaway;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BackfillOrderGiveawaysFromCart extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'orders:backfill-giveaways-from-cart {--dry-run : Show what would be done without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backfill missing giveaway attachments for orders based on their cart data';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN MODE - No changes will be made');
        }

        // Get orders without giveaways that have cart data
        $orders = Order::whereDoesntHave('giveaways')
            ->whereNotNull('cart')
            ->where('cart', '!=', '[]')
            ->get();

        $this->info("Found {$orders->count()} orders without giveaways that have cart data");

        $processed = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($orders as $order) {
            try {
                $cart = $order->cart;

                if (!is_array($cart) || empty($cart)) {
                    $this->warn("Order {$order->id}: Cart is empty or invalid, skipping");
                    $skipped++;
                    continue;
                }

                $giveawaysToAttach = [];

                foreach ($cart as $cartItem) {
                    if (!isset($cartItem['id']) || !isset($cartItem['amount'])) {
                        $this->warn("Order {$order->id}: Invalid cart item structure, skipping item");
                        continue;
                    }

                    $giveawayId = $cartItem['id'];
                    $amount = $cartItem['amount'];

                    // Verify giveaway exists
                    $giveaway = Giveaway::find($giveawayId);
                    if (!$giveaway) {
                        $this->error("Order {$order->id}: Giveaway {$giveawayId} does not exist, skipping");
                        $errors++;
                        continue;
                    }

                    // Prepare pivot data
                    $numbers = isset($cartItem['numbers']) && is_array($cartItem['numbers'])
                        ? $cartItem['numbers']
                        : [];

                    $giveawaysToAttach[$giveawayId] = [
                        'amount' => $amount,
                        'numbers' => json_encode($numbers),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];

                    $this->line("Order {$order->id}: Will attach giveaway {$giveawayId} with amount {$amount}");
                }

                if (empty($giveawaysToAttach)) {
                    $this->warn("Order {$order->id}: No valid giveaways to attach, skipping");
                    $skipped++;
                    continue;
                }

                if (!$dryRun) {
                    // Attach giveaways using syncWithoutDetaching to avoid duplicates
                    $order->giveaways()->syncWithoutDetaching($giveawaysToAttach);

                    Log::info('Backfilled giveaways for order from cart', [
                        'order_id' => $order->id,
                        'giveaways_attached' => array_keys($giveawaysToAttach),
                        'cart_data' => $cart
                    ]);
                }

                $processed++;
                $this->info("Order {$order->id}: Successfully processed ({$processed}/{$orders->count()})");

            } catch (\Exception $e) {
                $this->error("Order {$order->id}: Error processing - {$e->getMessage()}");
                $errors++;
                Log::error('Error backfilling giveaways for order', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                    'cart' => $order->cart
                ]);
            }
        }

        $this->info("\nSummary:");
        $this->info("Processed: {$processed}");
        $this->info("Skipped: {$skipped}");
        $this->info("Errors: {$errors}");

        if ($dryRun) {
            $this->info("\nRun without --dry-run to apply changes");
        } else {
            $this->info("\nBackfill completed successfully!");
        }

        return Command::SUCCESS;
    }
}