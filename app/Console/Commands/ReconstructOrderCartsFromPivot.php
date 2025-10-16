<?php

namespace App\Console\Commands;

use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReconstructOrderCartsFromPivot extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'orders:reconstruct-carts-from-pivot {--dry-run : Show what would be done without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reconstruct cart data for orders with empty carts using pivot table data';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN MODE - No changes will be made');
        }

        // Get orders with empty or null carts that have pivot records
        $orders = Order::where(function($query) {
            $query->whereNull('cart')
                  ->orWhere('cart', '[]');
        })
        ->whereHas('giveaways') // Only orders that actually have pivot records
        ->with('giveaways') // Eager load the giveaways with pivot data
        ->get();

        $this->info("Found {$orders->count()} orders with empty carts that have pivot data");

        $processed = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($orders as $order) {
            try {
                $cartItems = [];

                foreach ($order->giveaways as $giveaway) {
                    // Reconstruct cart item from pivot data
                    $cartItem = [
                        'id' => $giveaway->id,
                        'amount' => $giveaway->pivot->amount ?? 1,
                        'numbers' => json_decode($giveaway->pivot->numbers ?? '[]', true) ?? []
                    ];

                    $cartItems[] = $cartItem;
                }

                if (empty($cartItems)) {
                    $this->warn("Order {$order->id}: No cart items could be reconstructed, skipping");
                    $skipped++;
                    continue;
                }

                $this->line("Order {$order->id}: Will reconstruct cart with " . count($cartItems) . " items");

                if (!$dryRun) {
                    // Update the order's cart field
                    $order->update(['cart' => $cartItems]);

                    Log::info('Reconstructed cart for order from pivot data', [
                        'order_id' => $order->id,
                        'cart_items' => $cartItems,
                        'giveaway_count' => count($cartItems)
                    ]);
                }

                $processed++;
                $this->info("Order {$order->id}: Successfully processed ({$processed}/{$orders->count()})");

            } catch (\Exception $e) {
                $this->error("Order {$order->id}: Error processing - {$e->getMessage()}");
                $errors++;
                Log::error('Error reconstructing cart for order', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage()
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
            $this->info("\nCart reconstruction completed successfully!");
        }

        return Command::SUCCESS;
    }
}