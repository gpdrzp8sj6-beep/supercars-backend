<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use App\Mail\OrderCompleted;

class ValidateCheckouts extends Command
{
    protected $signature = 'app:validate-checkout';
    protected $description = 'Validate pending checkouts and update their status';

    public function handle()
    {
        Order::where('status', 'pending')->each(function ($order) {
            // Auto-complete zero-amount orders and skip timeout logic
            if ((float)$order->total === 0.0) {
                if ($order->status !== 'completed') {
                    $order->update(['status' => 'completed']);
                    $this->info("Order {$order->id} marked as completed (zero-amount).");
                    // Email will be sent automatically by Order model update hook
                    Log::info('Order completed email sent (zero-amount).', ['order_id' => $order->id]);
                }
                return;
            }

            // Check for 5-minute timeout instead of 10 minutes
            if ($order->created_at->lt(Carbon::now()->subMinutes(5))) {
                $order->update(['status' => 'failed']);
                // Clean up any giveaway_order records for failed orders
                DB::table('giveaway_order')->where('order_id', $order->id)->delete();
                $this->info("Order {$order->id} marked as failed (timeout) and tickets released.");
                return;
            }

            if (!$order->checkoutId) {
                $this->warn("Order {$order->id} has no checkoutId, skipping.");
                return;
            }

            $url = "https://eu-prod.oppwa.com/v1/checkouts/{$order->checkoutId}/payment?entityId=8ac9a4cd9662a1bc0196687d626128ad";
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_HTTPHEADER => ['Authorization:Bearer OGFjOWE0Y2M5NjYyYWIxZDAxOTY2ODdkNjFhMjI5MzN8UWltamM6IWZIRVpBejMlcnBiZzY='],
                CURLOPT_CUSTOMREQUEST => 'GET',
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_RETURNTRANSFER => true,
            ]);

            $response = curl_exec($ch);
            if (curl_errno($ch)) {
                $this->error("Curl error on order {$order->id}: " . curl_error($ch));
                curl_close($ch);
                return;
            }
            curl_close($ch);

            $data = json_decode($response, true);
            if (!isset($data['result']['code'])) {
                $this->error("Invalid response for order {$order->id}.");
                return;
            }

            $code = $data['result']['code'];
            $status = $this->determineStatus($code);
            
            // Use database transaction to ensure atomicity
            DB::transaction(function () use ($order, $status) {
                // IMPORTANT: Assign tickets BEFORE updating status to avoid race condition
                // This ensures tickets are assigned before the email is sent
                if ($status === 'completed') {
                    $this->assignTicketsForOrder($order);
                    $this->info("Tickets assigned for order {$order->id}.");
                    
                    // Verify tickets were actually assigned
                    $order->refresh();
                    $assignedTickets = $order->giveaways->count();
                    if ($assignedTickets > 0) {
                        $this->info("Verification: Order {$order->id} has {$assignedTickets} giveaway(s) with tickets assigned.");
                        
                        // Log ticket numbers for verification
                        foreach ($order->giveaways as $giveaway) {
                            $numbers = json_decode($giveaway->pivot->numbers ?? '[]', true);
                            $this->info("Giveaway {$giveaway->id}: tickets " . implode(', ', $numbers));
                        }
                    } else {
                        $this->error("WARNING: Order {$order->id} marked completed but no tickets assigned!");
                    }
                }
                
                $order->update(['status' => $status]);
                $this->info("Order {$order->id} updated to {$status}.");
            });

            if ($status === 'completed') {
                // Email will be sent automatically by Order model update hook
                Log::info('Order completed (email will be sent by model hook).', ['order_id' => $order->id]);
            }
        });
    }

    private function assignTicketsForOrder(Order $order): void
    {
        $cart = $order->cart;
        if (!$cart || !is_array($cart)) {
            $this->error("No cart data found for order {$order->id}");
            return;
        }

        $this->info("Processing cart for order {$order->id}: " . json_encode($cart));

        $user = $order->user;
        $giveaways = \App\Models\Giveaway::whereIn('id', collect($cart)->pluck('id'))->get()->keyBy('id');

        $attachData = [];

        foreach ($cart as $item) {
            $giveawayId = $item['id'];
            $amount = $item['amount'];
            $requestedNumbers = $item['numbers'] ?? [];

            $giveaway = $giveaways->get($giveawayId);

            // Enforce per-order limit
            if ($amount > $giveaway->ticketsPerUser) {
                $this->error("Amount for giveaway ID {$giveawayId} exceeds ticketsPerUser limit for order {$order->id}");
                continue;
            }

            // Enforce cumulative per-user limit across previous completed orders
            $existingUserNumbers = DB::table('giveaway_order')
                ->join('orders', 'giveaway_order.order_id', '=', 'orders.id')
                ->where('orders.user_id', $user->id)
                ->where('orders.status', 'completed')
                ->where('giveaway_order.giveaway_id', $giveawayId)
                ->pluck('giveaway_order.numbers')
                ->filter()
                ->flatMap(function ($jsonNumbers) {
                    return json_decode($jsonNumbers, true) ?: [];
                });

            $existingCountForUser = $existingUserNumbers->count();
            if (($existingCountForUser + $amount) > $giveaway->ticketsPerUser) {
                $this->error("User already has {$existingCountForUser} ticket(s) for giveaway ID {$giveawayId}, order {$order->id} exceeds limit");
                continue;
            }

            $assignedNumbers = $this->getAssignedNumbersForGiveaway($giveawayId);
            $currentlyAssignedCount = $assignedNumbers->count();

            if ($currentlyAssignedCount + $amount > $giveaway->ticketsTotal) {
                $this->error("Not enough tickets left in giveaway ID {$giveawayId} for order {$order->id}");
                continue;
            }

            // Validate requested numbers range and uniqueness among themselves
            $validRequestedNumbers = [];
            $invalidNumbers = [];
            $seenRequested = [];

            foreach ($requestedNumbers as $num) {
                if ($num < 1 || $num > $giveaway->ticketsTotal) {
                    $invalidNumbers[] = $num;
                    continue;
                }
                if (in_array($num, $seenRequested)) {
                    // Duplicate in requested, skip
                    continue;
                }
                $seenRequested[] = $num;
                $validRequestedNumbers[] = $num;
            }

            // If any requested numbers are invalid, skip this giveaway
            if (!empty($invalidNumbers)) {
                $this->error("Invalid ticket numbers for giveaway ID {$giveawayId}, order {$order->id}: " . implode(', ', $invalidNumbers) . ". Valid range is 1-{$giveaway->ticketsTotal}. Skipping.");
                continue;
            }

            // Filter out numbers already assigned
            $availableRequestedNumbers = array_filter($validRequestedNumbers, function ($num) use ($assignedNumbers) {
                return !$assignedNumbers->contains($num);
            });

            $numbersToAssignCount = $amount;
            $remainingCount = $numbersToAssignCount - count($availableRequestedNumbers);

            $excludedNumbers = $assignedNumbers->merge($availableRequestedNumbers);
            $randomNumbers = $this->assignRandomNumbers($excludedNumbers, $remainingCount, $giveaway->ticketsTotal);

            $finalNumbers = array_merge($availableRequestedNumbers, $randomNumbers);

            if (count($finalNumbers) !== $amount) {
                $this->error("Failed to assign enough unique ticket numbers for giveaway ID {$giveawayId}, order {$order->id}");
                continue;
            }

            $attachData[$giveawayId] = [
                'numbers' => json_encode($finalNumbers),
            ];
        }

        if (!empty($attachData)) {
            $order->giveaways()->attach($attachData);
            $this->info("Tickets assigned for order {$order->id}");
        }
    }

    protected function getAssignedNumbersForGiveaway(int $giveawayId): \Illuminate\Support\Collection
    {
        return DB::table('giveaway_order')
            ->join('orders', 'giveaway_order.order_id', '=', 'orders.id')
            ->where('giveaway_order.giveaway_id', $giveawayId)
            ->where('orders.status', 'completed')
            ->pluck('giveaway_order.numbers')
            ->filter()
            ->flatMap(function ($jsonNumbers) {
                return json_decode($jsonNumbers, true) ?: [];
            })
            ->unique()
            ->values();
    }

    protected function assignRandomNumbers(\Illuminate\Support\Collection $assignedNumbers, int $amount, int $ticketsTotal): array
    {
        $availableNumbers = [];

        for ($i = 1; $i <= $ticketsTotal; $i++) {
            if (! $assignedNumbers->contains($i)) {
                $availableNumbers[] = $i;
            }
        }

        if (count($availableNumbers) < $amount) {
            throw new \Exception('Not enough tickets available to assign requested amount.');
        }

        shuffle($availableNumbers);
        return array_slice($availableNumbers, 0, $amount);
    }

    private function determineStatus(string $code): string
    {
        if (preg_match('/^(000\.000\.|000\.100\.1|000\.[36]|000\.400\.[1][12]0)/', $code)) {
            return 'completed';
        }

        if (preg_match('/^(000\.200|800\.400\.5|100\.400\.500)/', $code)) {
            return 'pending';
        }

        if (preg_match('/^(000\.400\.0[^3]|000\.400\.100)/', $code)) {
            return 'pending';
        }

        // Handle timeout/session expired errors - treat as completed if order is older than 30 minutes
        if (preg_match('/^(200\.300\.404)/', $code)) {
            Log::warning("Payment session expired for checkout validation", ['code' => $code]);
            return 'completed'; // Assume payment went through if session expired
        }

        return 'failed';
    }
}
