<?php

namespace App\Console\Commands;

use App\Models\Giveaway;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixOrdersWithTestGiveaway extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'orders:fix-test-giveaway {--dry-run : Show what would be done without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update orders using test giveaway to use the real giveaway with images';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN MODE - No changes will be made');
        }

        $testGiveawayId = 678435; // The test giveaway without images
        $realGiveawayId = 678434; // The real giveaway with images

        // Verify both giveaways exist
        $testGiveaway = Giveaway::find($testGiveawayId);
        $realGiveaway = Giveaway::find($realGiveawayId);

        if (!$testGiveaway) {
            $this->error("Test giveaway {$testGiveawayId} not found");
            return Command::FAILURE;
        }

        if (!$realGiveaway) {
            $this->error("Real giveaway {$realGiveawayId} not found");
            return Command::FAILURE;
        }

        $this->info("Test giveaway: {$testGiveaway->title} (ID: {$testGiveawayId}) - Images: " . (is_null($testGiveaway->images) ? 'None' : 'Has images'));
        $this->info("Real giveaway: {$realGiveaway->title} (ID: {$realGiveawayId}) - Images: " . (is_null($realGiveaway->images) ? 'None' : 'Has images'));

        // Get orders using the test giveaway
        $orders = DB::table('giveaway_order')
            ->where('giveaway_id', $testGiveawayId)
            ->join('orders', 'giveaway_order.order_id', '=', 'orders.id')
            ->select('giveaway_order.*', 'orders.status')
            ->get();

        $this->info("Found {$orders->count()} orders using the test giveaway");

        if ($orders->isEmpty()) {
            $this->info('No orders need to be updated.');
            return Command::SUCCESS;
        }

        $processed = 0;
        $skipped = 0;

        foreach ($orders as $pivot) {
            $this->line("Order {$pivot->order_id} (Status: {$pivot->status}): Will change giveaway from {$testGiveawayId} to {$realGiveawayId}");

            if (!$dryRun) {
                // Update the pivot table
                DB::table('giveaway_order')
                    ->where('order_id', $pivot->order_id)
                    ->where('giveaway_id', $testGiveawayId)
                    ->update(['giveaway_id' => $realGiveawayId]);

                $this->info("Order {$pivot->order_id}: Giveaway updated successfully");
            }

            $processed++;
        }

        $this->info("\nSummary:");
        $this->info("Processed: {$processed}");
        $this->info("Skipped: {$skipped}");

        if ($dryRun) {
            $this->info("\nRun without --dry-run to apply changes");
        } else {
            $this->info("\nGiveaway fixes completed successfully!");
        }

        return Command::SUCCESS;
    }
}