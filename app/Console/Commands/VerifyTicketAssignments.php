<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class VerifyTicketAssignments extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tickets:verify {--detailed : Show detailed information about problematic orders}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verify ticket assignment status and identify any remaining issues';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $detailed = $this->option('detailed');

        $this->info('🔍 TICKET ASSIGNMENT VERIFICATION');
        $this->newLine();

        try {
            // Check for orders with empty giveaway records
            $emptyGiveawayCount = DB::select('SELECT COUNT(*) as count FROM orders o INNER JOIN giveaway_order go ON o.id = go.order_id WHERE o.status = "completed" AND JSON_LENGTH(go.numbers) = 0')[0]->count;

            // Check for orders with no giveaway records
            $noGiveawayCount = DB::select('SELECT COUNT(*) as count FROM orders o LEFT JOIN giveaway_order go ON o.id = go.order_id WHERE o.status = "completed" AND go.order_id IS NULL AND o.cart IS NOT NULL AND JSON_LENGTH(o.cart) > 0')[0]->count;

            // Check total completed orders with giveaways
            $totalCompletedWithGiveaways = DB::select('SELECT COUNT(DISTINCT o.id) as count FROM orders o INNER JOIN giveaway_order go ON o.id = go.order_id WHERE o.status = "completed" AND JSON_LENGTH(go.numbers) > 0')[0]->count;

            // Get total completed orders
            $totalCompletedOrders = DB::select('SELECT COUNT(*) as count FROM orders WHERE status = "completed"')[0]->count;

            $this->info('📊 Current Status:');
            $this->line("  • Total completed orders: <comment>{$totalCompletedOrders}</comment>");
            $this->line("  • Orders with properly assigned tickets: <info>{$totalCompletedWithGiveaways}</info>");
            $this->line("  • Orders with empty giveaway records: <error>{$emptyGiveawayCount}</error>");
            $this->line("  • Orders with no giveaway records: <error>{$noGiveawayCount}</error>");
            $this->line("  • Total problematic orders: <error>" . ($emptyGiveawayCount + $noGiveawayCount) . "</error>");
            $this->newLine();

            $totalProblematic = $emptyGiveawayCount + $noGiveawayCount;

            if ($totalProblematic > 0) {
                $this->error("❌ ISSUES FOUND - Run the fix commands:");
                $this->line("  1. <comment>php artisan tickets:fix-empty-numbers</comment> (for orders with empty records)");
                $this->line("  2. <comment>php artisan tickets:fix-missing-records</comment> (for orders with no records)");
                $this->line("  3. <comment>php artisan tickets:verify</comment> (to verify fixes)");
                $this->newLine();

                if ($detailed) {
                    $this->showDetailedIssues($emptyGiveawayCount, $noGiveawayCount);
                }
            } else {
                $this->info("✅ ALL ISSUES FIXED - No problematic orders found!");
                $this->newLine();

                if ($detailed) {
                    $this->showRecentSuccessfulOrders();
                }
            }

            return $totalProblematic > 0 ? Command::FAILURE : Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error("ERROR: " . $e->getMessage());
            return Command::FAILURE;
        }
    }

    private function showDetailedIssues($emptyCount, $noGiveawayCount)
    {
        $this->info('📋 Detailed Problematic Orders:');
        $this->newLine();

        if ($emptyCount > 0) {
            $this->warn("Orders with empty giveaway records:");
            $emptyOrders = DB::select('
                SELECT o.id, o.created_at, go.giveaway_id
                FROM orders o
                INNER JOIN giveaway_order go ON o.id = go.order_id
                WHERE o.status = "completed" AND JSON_LENGTH(go.numbers) = 0
                ORDER BY o.created_at DESC
                LIMIT 10
            ');

            foreach ($emptyOrders as $order) {
                $this->line("  • Order <comment>{$order->id}</comment> (giveaway {$order->giveaway_id}) - <dim>{$order->created_at}</dim>");
            }
            $this->newLine();
        }

        if ($noGiveawayCount > 0) {
            $this->warn("Orders with no giveaway records:");
            $noGiveawayOrders = DB::select('
                SELECT o.id, o.created_at, o.cart
                FROM orders o
                LEFT JOIN giveaway_order go ON o.id = go.order_id
                WHERE o.status = "completed"
                AND go.order_id IS NULL
                AND o.cart IS NOT NULL
                AND JSON_LENGTH(o.cart) > 0
                ORDER BY o.created_at DESC
                LIMIT 10
            ');

            foreach ($noGiveawayOrders as $order) {
                $cart = json_decode($order->cart, true);
                $giveawayIds = collect($cart)->pluck('id')->join(', ');
                $this->line("  • Order <comment>{$order->id}</comment> (should have giveaways: {$giveawayIds}) - <dim>{$order->created_at}</dim>");
            }
            $this->newLine();
        }
    }

    private function showRecentSuccessfulOrders()
    {
        $this->info('🎫 Recent orders with properly assigned tickets:');
        $recentOrders = DB::select('
            SELECT
                o.id as order_id,
                o.created_at,
                go.giveaway_id,
                go.numbers,
                JSON_LENGTH(go.numbers) as ticket_count
            FROM orders o
            INNER JOIN giveaway_order go ON o.id = go.order_id
            WHERE o.status = "completed"
            AND JSON_LENGTH(go.numbers) > 0
            ORDER BY o.created_at DESC
            LIMIT 5
        ');

        foreach ($recentOrders as $order) {
            $tickets = json_decode($order->numbers, true);
            $ticketList = implode(', ', $tickets);
            $this->line("  • Order <comment>{$order->order_id}</comment>: <info>{$ticketList}</info> (giveaway {$order->giveaway_id}) - <dim>{$order->created_at}</dim>");
        }
        $this->newLine();
    }
}