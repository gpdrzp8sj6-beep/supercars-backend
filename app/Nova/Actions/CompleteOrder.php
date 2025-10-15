<?php

namespace App\Nova\Actions;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Illuminate\Http\Request;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Actions\ActionResponse;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Http\Requests\NovaRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use App\Mail\OrderCompleted;

class CompleteOrder extends Action
{
    use InteractsWithQueue, Queueable;

    /**
     * The displayable name of the action.
     *
     * @var string
     */
    public $name = 'Complete Order & Assign Tickets';

    /**
     * The text to be used for the action's confirm button.
     *
     * @var string
     */
    public $confirmButtonText = 'Complete Order';

    /**
     * The text to be used for the action's confirmation text.
     *
     * @var string
     */
    public $confirmText = 'Are you sure you want to manually complete this order and assign tickets? This will mark the order as completed and assign ticket numbers.';

    /**
     * Determine if the action should be available for the given request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return bool
     */
    public function authorizedToSee(Request $request)
    {
        return true;
    }

    /**
     * Determine if the action should be available for the given request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return bool
     */
    public function authorizedToRun(Request $request, $model)
    {
        // Temporarily make it always available for debugging
        $isAuthorized = true; // $model->status !== 'completed' && $model->giveaways()->count() > 0;

        // Log authorization check for debugging
        \Illuminate\Support\Facades\Log::info('CompleteOrder authorization check', [
            'order_id' => $model->id,
            'order_status' => $model->status,
            'giveaways_count' => $model->giveaways()->count(),
            'giveaways_loaded' => $model->relationLoaded('giveaways'),
            'is_authorized' => $isAuthorized
        ]);

        return $isAuthorized;
    }

    /**
     * Perform the action on the given models.
     *
     * @return mixed
     */
    public function handle(ActionFields $fields, Collection $models)
    {
        $processedOrders = [];

        foreach ($models as $order) {
            try {
                $this->completeOrderAndAssignTickets($order);
                $processedOrders[] = "Order #{$order->id}";
            } catch (\Exception $e) {
                // Return error message to user
                return ActionResponse::danger($e->getMessage());
            }
        }

        $message = sprintf(
            'Successfully completed %d order(s) and assigned tickets: %s',
            count($processedOrders),
            implode(', ', $processedOrders)
        );

        return ActionResponse::message($message);
    }

    /**
     * Get the fields available on the action.
     *
     * @return array<int, \Laravel\Nova\Fields\Field>
     */
    public function fields(NovaRequest $request): array
    {
        return [];
    }

    private function completeOrderAndAssignTickets($order)
    {
        // Use database transaction to ensure atomicity
        DB::transaction(function () use ($order) {
            // Lock the order to prevent concurrent updates
            $lockedOrder = \App\Models\Order::where('id', $order->id)->lockForUpdate()->first();

            if (!$lockedOrder) {
                Log::warning("Order not found during manual completion", ['order_id' => $order->id]);
                return;
            }

            // Check if status is already completed
            if ($lockedOrder->status === 'completed') {
                Log::info("Order status already completed, skipping update", ['order_id' => $order->id]);
                return;
            }

            // Mark that we're processing manually BEFORE any updates
            app()->singleton('manual_completion', function () {
                return true;
            });

            // Assign tickets
            $this->assignTicketsForOrder($lockedOrder);
            Log::info('Tickets assigned for order via manual completion', ['order_id' => $order->id]);

            // Update order status to completed
            $lockedOrder->update(['status' => 'completed']);

            Log::info('Order status updated to completed via manual completion', [
                'order_id' => $order->id,
                'old_status' => $order->status,
                'new_status' => 'completed',
            ]);
        }, 5);

        // After transaction is committed, refresh the order and send email
        $order->refresh();
        $order->load(['giveaways' => function($query) {
            $query->withPivot(['numbers', 'amount']);
        }]);

        Log::info('Order refreshed after manual completion transaction', [
            'order_id' => $order->id,
            'giveaways_count' => $order->giveaways->count(),
            'first_giveaway_numbers' => $order->giveaways->first()?->pivot?->numbers
        ]);

        // Send completion email
        $this->sendOrderCompletionEmail($order);
    }

    private function assignTicketsForOrder($order)
    {
        $cart = $order->cart;
        if (!$cart || !is_array($cart)) {
            Log::error("No cart data found for order {$order->id}");
            return;
        }

        $user = $order->user;
        $giveaways = \App\Models\Giveaway::whereIn('id', collect($cart)->pluck('id'))->get()->keyBy('id');

        Log::info('Starting ticket assignment via manual completion', [
            'order_id' => $order->id,
            'cart_items' => count($cart),
            'existing_giveaways' => $order->giveaways()->count()
        ]);

        $attachData = [];

        foreach ($cart as $item) {
            $giveawayId = $item['id'];
            $amount = $item['amount'];
            $requestedNumbers = $item['numbers'] ?? [];

            $giveaway = $giveaways->get($giveawayId);

            if (!$giveaway) {
                Log::error("Giveaway not found for ID {$giveawayId} in order {$order->id}");
                continue;
            }

            // Enforce per-order limit
            if ($amount > $giveaway->ticketsPerUser) {
                Log::error("Amount for giveaway ID {$giveawayId} exceeds ticketsPerUser limit for order {$order->id}");
                continue;
            }

            // Check if this giveaway is already attached to this order
            $existingAttachment = $order->giveaways()->where('giveaway_id', $giveawayId)->first();
            if ($existingAttachment && !empty($existingAttachment->pivot->numbers)) {
                Log::info("Giveaway {$giveawayId} already has ticket numbers for order {$order->id}, skipping");
                continue;
            }

            // Get available numbers for this giveaway
            $availableNumbers = $this->getAvailableNumbers($giveaway, $amount, $requestedNumbers);

            if (count($availableNumbers) < $amount) {
                Log::error("Not enough available numbers for giveaway ID {$giveawayId}, order {$order->id}");
                continue;
            }

            $attachData[$giveawayId] = [
                'numbers' => json_encode($availableNumbers),
                'amount' => $amount
            ];
        }

        // Sync the giveaways to the order (this will replace any existing attachments)
        try {
            $order->giveaways()->sync($attachData);
            Log::info('Tickets assigned for order via manual completion', [
                'order_id' => $order->id,
                'attach_data' => $attachData,
                'total_giveaways' => count($attachData)
            ]);
        } catch (\Exception $e) {
            throw new \Exception('Failed to save ticket assignments: ' . $e->getMessage());
        }
    }

    private function getAvailableNumbers($giveaway, $amount, $requestedNumbers = [])
    {
        // Get all taken numbers for this giveaway
        $takenNumbers = DB::table('giveaway_order')
            ->where('giveaway_id', $giveaway->id)
            ->orderBy('id')
            ->pluck('numbers')
            ->filter()
            ->flatMap(function ($jsonNumbers) {
                return json_decode($jsonNumbers, true) ?: [];
            })
            ->unique()
            ->values()
            ->toArray();

        Log::info('Checking available numbers for giveaway in manual completion', [
            'giveaway_id' => $giveaway->id,
            'total_tickets' => $giveaway->ticketsTotal,
            'amount_requested' => $amount,
            'taken_numbers_count' => count($takenNumbers),
            'requested_numbers' => $requestedNumbers
        ]);

        $availableNumbers = [];

        // First, try to assign requested numbers if available
        if (!empty($requestedNumbers)) {
            foreach ($requestedNumbers as $number) {
                if (!in_array($number, $takenNumbers) && count($availableNumbers) < $amount) {
                    $availableNumbers[] = $number;
                }
            }
        }

        // Fill remaining slots with any available numbers
        $maxNumber = $giveaway->ticketsTotal;
        $allAvailable = [];
        for ($i = 1; $i <= $maxNumber; $i++) {
            if (!in_array($i, $takenNumbers) && !in_array($i, $availableNumbers)) {
                $allAvailable[] = $i;
            }
        }
        // Shuffle to randomize the order
        shuffle($allAvailable);
        // Take the required amount
        $remainingNeeded = $amount - count($availableNumbers);
        $availableNumbers = array_merge($availableNumbers, array_slice($allAvailable, 0, $remainingNeeded));

        Log::info('Available numbers result in manual completion', [
            'giveaway_id' => $giveaway->id,
            'available_numbers' => $availableNumbers,
            'available_count' => count($availableNumbers),
            'needed_count' => $amount,
            'success' => count($availableNumbers) >= $amount
        ]);

        return $availableNumbers;
    }

    private function sendOrderCompletionEmail($order)
    {
        try {
            $email = $order->user?->email;
            if (!$email) {
                Log::warning('Cannot send order completion email: user email missing', [
                    'order_id' => $order->id
                ]);
                return;
            }

            // Log detailed giveaway information
            $ticketInfo = [];
            foreach ($order->giveaways as $giveaway) {
                $numbers = json_decode($giveaway->pivot->numbers ?? '[]', true);
                $ticketInfo[] = "Giveaway {$giveaway->id}: " . implode(', ', $numbers);
            }

            Log::info('Sending order completion email after manual completion', [
                'order_id' => $order->id,
                'user_email' => $email,
                'giveaways_count' => $order->giveaways->count(),
                'ticket_numbers' => $ticketInfo,
                'has_pivot_numbers' => $order->giveaways->first()?->pivot?->numbers ? 'yes' : 'no'
            ]);

            Mail::to($email)->send(new OrderCompleted($order));

            Log::info('Order completion email sent successfully after manual completion', [
                'order_id' => $order->id
            ]);

        } catch (\Throwable $ex) {
            Log::error('Failed to send order completion email after manual completion: ' . $ex->getMessage(), [
                'order_id' => $order->id,
                'exception' => $ex,
            ]);
        }
    }
}