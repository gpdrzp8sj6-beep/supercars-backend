<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Models\Giveaway;

class DrawGiveawayWinners extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'giveaway:draw-winners';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Draw winners for giveaways that have closed';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Checking for giveaways to draw winners...');

        // Get giveaways that should be drawn
        $giveaways = Giveaway::where('closes_at', '<=', Carbon::now())
            ->where('autoDraw', true)
            ->get();

        if ($giveaways->isEmpty()) {
            $this->info('No giveaways found that need drawing.');
            return;
        }

        foreach ($giveaways as $giveaway) {
            // Check if this giveaway already has winners
            $existingWinners = DB::table('giveaway_order')
                ->where('giveaway_id', $giveaway->id)
                ->where('is_winner', true)
                ->count();

            if ($existingWinners > 0) {
                $this->info("Giveaway ID {$giveaway->id} already has winners. Skipping.");
                continue;
            }

            $this->info("Processing giveaway: {$giveaway->title} (ID: {$giveaway->id})");

            try {
                $this->drawWinnersForGiveaway($giveaway);
                $this->info("Successfully drew winners for giveaway ID {$giveaway->id}");
            } catch (\Exception $e) {
                $this->error("Failed to draw winners for giveaway ID {$giveaway->id}: " . $e->getMessage());
            }
        }

        $this->info('Finished processing giveaways.');
    }

    /**
     * Draw winners for a specific giveaway
     */
    private function drawWinnersForGiveaway(Giveaway $giveaway)
    {
        // Get all completed orders for this giveaway with their ticket numbers
        $giveawayOrders = DB::table('giveaway_order')
            ->join('orders', 'giveaway_order.order_id', '=', 'orders.id')
            ->where('giveaway_order.giveaway_id', $giveaway->id)
            ->where('orders.status', 'completed')
            ->whereNotNull('giveaway_order.numbers')
            ->select('giveaway_order.id as pivot_id', 'giveaway_order.numbers')
            ->get();

        if ($giveawayOrders->isEmpty()) {
            $this->warn("No completed orders found for giveaway ID {$giveaway->id}");
            return;
        }

        // Collect all possible ticket numbers with their corresponding pivot IDs
        $allTickets = [];
        foreach ($giveawayOrders as $order) {
            $ticketNumbers = json_decode($order->numbers, true);
            if (is_array($ticketNumbers)) {
                foreach ($ticketNumbers as $ticketNumber) {
                    $allTickets[] = [
                        'pivot_id' => $order->pivot_id,
                        'ticket_number' => $ticketNumber
                    ];
                }
            }
        }

        if (empty($allTickets)) {
            $this->warn("No valid ticket numbers found for giveaway ID {$giveaway->id}");
            return;
        }

        $this->info("Total tickets available: " . count($allTickets));

        // Draw the specified number of winners
        $winnersCount = min($giveaway->manyWinners, count($allTickets));
        $drawnTickets = [];

        for ($i = 0; $i < $winnersCount; $i++) {
            // Remove already drawn tickets from the pool
            $availableTickets = array_filter($allTickets, function($ticket) use ($drawnTickets) {
                return !in_array($ticket['ticket_number'], $drawnTickets);
            });

            if (empty($availableTickets)) {
                $this->warn("No more tickets available to draw for giveaway ID {$giveaway->id}");
                break;
            }

            // Randomly select a winning ticket
            $randomIndex = array_rand($availableTickets);
            $winningTicket = $availableTickets[$randomIndex];

            // Mark this pivot row as winner
            DB::table('giveaway_order')
                ->where('id', $winningTicket['pivot_id'])
                ->update([
                    'is_winner' => true,
                    'winning_ticket' => $winningTicket['ticket_number']
                ]);

            $drawnTickets[] = $winningTicket['ticket_number'];

            $this->info("Winner #" . ($i + 1) . ": Ticket #{$winningTicket['ticket_number']} (Pivot ID: {$winningTicket['pivot_id']})");
        }

        $this->info("Drew {$winnersCount} winner(s) for giveaway ID {$giveaway->id}");
    }
}
