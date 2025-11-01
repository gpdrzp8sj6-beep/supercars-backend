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
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\BooleanGroup;
use Laravel\Nova\Fields\Textarea;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use App\Mail\OrderCompleted;
use App\Models\User;
use App\Models\Giveaway;
use App\Models\Order;
use App\Models\CreditTransaction;

class ManualOrderCreation extends Action
{
    use InteractsWithQueue, Queueable;

    /**
     * The displayable name of the action.
     *
     * @var string
     */
    public $name = 'Create Manual Order';

    /**
     * The text to be used for the action's confirm button.
     *
     * @var string
     */
    public $confirmButtonText = 'Create Order';

    /**
     * The text to be used for the action's confirmation text.
     *
     * @var string
     */
    public $confirmText = 'This will create a new order with the specified tickets and handle credit automatically.';

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
        return true;
    }

    /**
     * Perform the action on the given models.
     *
     * @return mixed
     */
    public function handle(ActionFields $fields, Collection $models)
    {
        // This action works on User models
        $user = $models->first();

        if (!$user) {
            return ActionResponse::danger('No user selected');
        }

        try {
            $order = $this->createManualOrder($fields, $user);
            if (!$order) {
                return ActionResponse::danger('Failed to create order');
            }
            return ActionResponse::message("Order #{$order->id} created successfully for user {$user->fullName}");
        } catch (\Exception $e) {
            Log::error('Manual order creation failed', [
                'error' => $e->getMessage(),
                'user_id' => $user->id,
                'fields' => $fields->toArray(),
                'trace' => $e->getTraceAsString()
            ]);
            return ActionResponse::danger('Failed to create order: ' . $e->getMessage());
        }
    }

    /**
     * Get the fields available on the action.
     *
     * @return array<int, \Laravel\Nova\Fields\Field>
     */
    public function fields(NovaRequest $request): array
    {
        return [
            Select::make('Giveaway', 'giveaway_id')
                ->options(function () {
                    return \App\Models\Giveaway::orderBy('title')
                        ->get()
                        ->mapWithKeys(function ($giveaway) {
                            return [$giveaway->id => $giveaway->title];
                        });
                })
                ->required()
                ->help('Select the giveaway to purchase tickets for'),

            Number::make('Number of Tickets', 'ticket_amount')
                ->min(1)
                ->required()
                ->help('How many tickets to purchase'),

            BooleanGroup::make('Ticket Assignment', 'ticket_assignment')
                ->options([
                    'random' => 'Random Numbers',
                    'specific' => 'Specific Numbers'
                ])
                ->default(['random'])
                ->help('Choose how to assign ticket numbers'),

            Textarea::make('Specific Numbers', 'specific_numbers')
                ->rows(3)
                ->help('Enter specific ticket numbers (comma-separated). Only used if "Specific Numbers" is selected above.')
                ->dependsOn('ticket_assignment', function ($field, NovaRequest $request, $formData) {
                    if (isset($formData['ticket_assignment']['specific'])) {
                        return $field;
                    }
                    return null;
                }),

            Select::make('Credit Handling', 'credit_handling')
                ->options([
                    'use_available' => 'Use available credit if any',
                    'add_if_needed' => 'Add credit if insufficient for tickets'
                ])
                ->default('use_available')
                ->help('How to handle user credit for this order'),
        ];
    }

    private function createManualOrder(ActionFields $fields, User $user)
    {
        $giveawayId = $fields->giveaway_id;
        if (!$giveawayId) {
            throw ValidationException::withMessages([
                'giveaway_id' => ['Please select a giveaway.']
            ]);
        }
        
        $giveaway = Giveaway::findOrFail($giveawayId);
        $ticketAmount = $fields->ticket_amount;
        $ticketAssignment = $fields->ticket_assignment ?? ['random'];
        $specificNumbers = $fields->specific_numbers;
        $creditHandling = $fields->credit_handling ?? 'use_available';

        // Validate ticket amount against giveaway limits
        if ($ticketAmount > $giveaway->ticketsPerUser) {
            throw ValidationException::withMessages([
                'ticket_amount' => ["Cannot purchase more than {$giveaway->ticketsPerUser} tickets per user for this giveaway."]
            ]);
        }

        // Check existing tickets for this user in this giveaway
        $existingTickets = DB::table('giveaway_order')
            ->join('orders', 'giveaway_order.order_id', '=', 'orders.id')
            ->where('orders.user_id', $user->id)
            ->where('orders.status', 'completed')
            ->where('giveaway_order.giveaway_id', $giveaway->id)
            ->pluck('giveaway_order.numbers')
            ->filter()
            ->flatMap(function ($jsonNumbers) {
                return json_decode($jsonNumbers, true) ?: [];
            })
            ->count();

        if (($existingTickets + $ticketAmount) > $giveaway->ticketsPerUser) {
            throw ValidationException::withMessages([
                'ticket_amount' => ["User already has {$existingTickets} tickets. Cannot exceed {$giveaway->ticketsPerUser} total tickets per user."]
            ]);
        }

        // Calculate total cost
        $totalCost = $giveaway->price * $ticketAmount;

        // Handle credit logic - always ensure user has enough credit
        $creditUsed = 0;
        $creditToAdd = 0;

        if ($creditHandling === 'use_available') {
            // Use available credit, but don't add more
            $creditUsed = min($user->credit, $totalCost);
        } elseif ($creditHandling === 'add_if_needed') {
            // Always ensure full credit coverage
            if ($user->credit < $totalCost) {
                $creditToAdd = $totalCost - $user->credit;
            }
            $creditUsed = $totalCost;
        }

        // For manual orders, always add credit to cover the full cost and mark as completed
        if ($user->credit < $totalCost) {
            $creditToAdd = $totalCost - $user->credit;
        }
        $creditUsed = $totalCost;
        $finalTotal = 0; // Always 0 since we're covering with credit

        // Parse specific numbers if provided
        $requestedNumbers = [];
        if (isset($ticketAssignment['specific']) && $specificNumbers) {
            // Clean up the input: trim, split by comma, filter out empty strings, convert to integers
            $requestedNumbers = array_values(array_filter(
                array_map('intval', 
                    array_filter(
                        array_map('trim', explode(',', $specificNumbers)),
                        function($val) { return $val !== ''; }
                    )
                ),
                function($val) { return $val > 0; }
            ));
            
            if (count($requestedNumbers) !== $ticketAmount) {
                throw ValidationException::withMessages([
                    'specific_numbers' => ["You requested {$ticketAmount} tickets but provided " . count($requestedNumbers) . " valid numbers. Please enter exactly {$ticketAmount} numbers separated by commas."]
                ]);
            }
        }

        // Check ticket availability
        $assignedNumbers = $this->getAssignedNumbersForGiveaway($giveaway->id);
        $currentlyAssignedCount = $assignedNumbers->count();

        if ($giveaway->ticketsTotal > 0 && $currentlyAssignedCount + $ticketAmount > $giveaway->ticketsTotal) {
            throw ValidationException::withMessages([
                'ticket_amount' => ["Not enough tickets available. Only " . ($giveaway->ticketsTotal - $currentlyAssignedCount) . " tickets left."]
            ]);
        }

        // Validate specific numbers if provided
        if (!empty($requestedNumbers)) {
            $invalidNumbers = [];
            foreach ($requestedNumbers as $num) {
                if ($num < 1 || $num > $giveaway->ticketsTotal) {
                    $invalidNumbers[] = $num;
                } elseif ($assignedNumbers->contains($num)) {
                    $invalidNumbers[] = $num;
                }
            }
            if (!empty($invalidNumbers)) {
                throw ValidationException::withMessages([
                    'specific_numbers' => ["Invalid or already assigned ticket numbers: " . implode(', ', $invalidNumbers)]
                ]);
            }
        }

        $order = null;

        DB::transaction(function () use (
            $user, $giveaway, $ticketAmount, $totalCost, $creditUsed, $creditToAdd,
            $finalTotal, $requestedNumbers, $assignedNumbers, &$order
        ) {
            // Add credit if needed
            if ($creditToAdd > 0) {
                $user->credit += $creditToAdd;
                $user->save();

                CreditTransaction::create([
                    'user_id' => $user->id,
                    'amount' => $creditToAdd,
                    'type' => 'add',
                    'description' => 'Credit added for manual order creation',
                ]);

                Log::info('Credit added for manual order', [
                    'user_id' => $user->id,
                    'amount_added' => $creditToAdd,
                    'new_credit_balance' => $user->credit
                ]);
            }

            // Deduct credit if used
            if ($creditUsed > 0) {
                $user->credit -= $creditUsed;
                $user->save();
            }

            // Create the order
            $order = Order::create([
                'user_id' => $user->id,
                'status' => $finalTotal == 0 ? 'completed' : 'created',
                'total' => $finalTotal,
                'forenames' => $user->forenames,
                'surname' => $user->surname,
                'phone' => $user->phone,
                'address_line_1' => 'Manual Order',
                'address_line_2' => null,
                'city' => 'Manual Order',
                'post_code' => 'Manual',
                'country' => 'Manual',
                'cart' => [
                    [
                        'id' => $giveaway->id,
                        'amount' => $ticketAmount,
                        'numbers' => $requestedNumbers
                    ]
                ],
                'credit_used' => $creditUsed,
            ]);

            // Create credit transaction for used credit
            if ($creditUsed > 0) {
                CreditTransaction::create([
                    'user_id' => $user->id,
                    'amount' => $creditUsed,
                    'type' => 'deduct',
                    'description' => 'Credit used for manual order ' . $order->id,
                    'order_id' => $order->id,
                ]);
            }

            // Always assign tickets immediately for manual orders
            $finalNumbers = $this->assignTicketNumbers(
                $assignedNumbers,
                $ticketAmount,
                $giveaway->ticketsTotal,
                $requestedNumbers
            );

            $order->giveaways()->attach([
                $giveaway->id => [
                    'numbers' => json_encode($finalNumbers),
                    'amount' => $ticketAmount,
                ]
            ]);

            Log::info('Tickets assigned for manual order', [
                'order_id' => $order->id,
                'giveaway_id' => $giveaway->id,
                'numbers' => $finalNumbers,
                'status' => $order->status
            ]);
        });

        // Send completion email for all completed orders
        if ($order && $order->status === 'completed') {
            try {
                $order->load(['giveaways' => function($query) {
                    $query->withPivot(['numbers', 'amount']);
                }]);

                Mail::to($order->user->email)->send(new OrderCompleted($order));

                Log::info('Order completion email sent for manual order', [
                    'order_id' => $order->id,
                    'user_email' => $order->user->email
                ]);
            } catch (\Throwable $ex) {
                Log::error('Failed to send completion email for manual order: ' . $ex->getMessage(), [
                    'order_id' => $order->id
                ]);
            }
        }

        Log::info('Manual order created successfully', [
            'order_id' => $order?->id,
            'user_id' => $user->id,
            'giveaway_id' => $giveaway->id,
            'ticket_amount' => $ticketAmount,
            'total_cost' => $totalCost,
            'credit_used' => $creditUsed,
            'credit_added' => $creditToAdd,
            'final_total' => $finalTotal,
            'status' => $order?->status
        ]);

        return $order;
    }

    protected function getAssignedNumbersForGiveaway(int $giveawayId): Collection
    {
        return DB::table('giveaway_order')
            ->join('orders', 'giveaway_order.order_id', '=', 'orders.id')
            ->where('giveaway_order.giveaway_id', $giveawayId)
            ->where('orders.status', 'completed')
            ->orderBy('giveaway_order.id')
            ->pluck('giveaway_order.numbers')
            ->filter()
            ->flatMap(function ($jsonNumbers) {
                return json_decode($jsonNumbers, true) ?: [];
            })
            ->unique()
            ->values();
    }

    protected function assignTicketNumbers(Collection $assignedNumbers, int $amount, int $ticketsTotal, array $requestedNumbers = []): array
    {
        $availableNumbers = [];

        for ($i = 1; $i <= $ticketsTotal; $i++) {
            if (!$assignedNumbers->contains($i)) {
                $availableNumbers[] = $i;
            }
        }

        if ($amount <= 0) {
            return [];
        }

        if (count($availableNumbers) < $amount) {
            throw ValidationException::withMessages([
                'tickets' => ['Not enough tickets available to assign requested amount.'],
            ]);
        }

        // Start with requested numbers if provided
        $finalNumbers = $requestedNumbers;

        // Fill remaining slots with random numbers
        $remainingCount = $amount - count($finalNumbers);
        if ($remainingCount > 0) {
            $remainingAvailable = array_diff($availableNumbers, $finalNumbers);
            shuffle($remainingAvailable);
            $randomNumbers = array_slice($remainingAvailable, 0, $remainingCount);
            $finalNumbers = array_merge($finalNumbers, $randomNumbers);
        }

        sort($finalNumbers);

        return $finalNumbers;
    }
}