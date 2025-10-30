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
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Http\Requests\NovaRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use App\Mail\OrderCompleted;

class ReassignTicketNumbers extends Action
{
    use InteractsWithQueue, Queueable;

    /**
     * The displayable name of the action.
     *
     * @var string
     */
    public $name = 'Reassign Tickets';

    /**
     * The text to be used for the action's confirm button.
     *
     * @var string
     */
    public $confirmButtonText = 'Reassign Tickets';

    /**
     * The text to be used for the action's confirmation text.
     *
     * @var string
     */
    public $confirmText = 'Are you sure you want to reassign ticket numbers for this order? This will replace any existing ticket assignments.';

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
        return $model->giveaways()->count() > 0 || $model->status === 'completed';
    }

    /**
     * Perform the action on the given models.
     *
     * @return mixed
     */
    public function handle(ActionFields $fields, Collection $models)
    {
        $processedOrders = [];
        $reassignmentType = 'unknown';

        if ($fields->assign_requested) {
            $reassignmentType = 'originally requested numbers';
        } elseif ($fields->assign_random) {
            $reassignmentType = 'random numbers';
        } elseif ($fields->assign_custom) {
            $reassignmentType = 'custom numbers';
        }

        foreach ($models as $order) {
            try {
                $this->reassignTicketsForOrder($order, $fields, $reassignmentType);
                $processedOrders[] = "Order #{$order->id}";
            } catch (\Exception $e) {
                // Return error message to user
                return ActionResponse::danger($e->getMessage());
            }
        }

        $message = sprintf(
            'Successfully reassigned ticket numbers for %d order(s) using %s: %s',
            count($processedOrders),
            $reassignmentType,
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
        $giveawayDetails = [];
        $currentAssignments = [];
        $requestedNumbers = [];

        Log::info('ReassignTicketNumbers action fields requested', [
            'request' => $request->all()
        ]);
        // Get selected resources for display
        if (!empty($request->resourceId)) {
            $resourceId = $request->resourceId;
			$order = \App\Models\Order::with('giveaways')->find($resourceId);
			if ($order) {
				// Get giveaway details and current assignments
				foreach ($order->giveaways as $giveaway) {
					$pivot = $giveaway->pivot;
					$numbers = json_decode($pivot->numbers ?? '[]', true);
					$giveawayDetails[] = "Giveaway ID {$giveaway->id}: {$giveaway->title} - Amount: {$pivot->amount}";
					$currentAssignments[] = implode(', ', $numbers ?: []);
				}

				// Get requested numbers from cart
				if ($order->cart && is_array($order->cart)) {
					foreach ($order->cart as $item) {
						$numbers = $item['numbers'] ?? [];
						$requestedNumbers[] = implode(', ', $numbers ?: ['None requested']);
					}
				}
			}
        } else {
            $giveawayDetails[] = "Loading order details...";
            $currentAssignments[] = "Loading order details...";
            $requestedNumbers[] = "Loading order details...";
        }

        return [
            Textarea::make('Giveaway Order Details', 'giveaway_details')
                ->rows(3)
                ->readonly()
                ->default(implode("\n", $giveawayDetails))
                ->help('Details of giveaways in this order'),

            Textarea::make('Requested Numbers', 'requested_numbers')
                ->rows(3)
                ->readonly()
                ->default(implode("\n", $requestedNumbers))
                ->help('Numbers originally requested by the user'),

            Textarea::make('Currently Assigned Numbers', 'current_assignments')
                ->rows(3)
                ->readonly()
                ->default(implode("\n", $currentAssignments))
                ->help('Numbers currently assigned to this order'),

            Boolean::make('Assign Originally Requested Numbers', 'assign_requested')
                ->default(true)
                ->help('Assign the numbers that were originally requested in the cart'),

            Boolean::make('Assign Random Numbers', 'assign_random')
                ->default(false)
                ->help('Assign random available numbers'),

            Boolean::make('Assign Custom Numbers', 'assign_custom')
                ->default(false)
                ->help('Assign specific custom numbers'),

            Text::make('Custom Numbers', 'custom_numbers')
                ->dependsOn('assign_custom', true)
                ->rules(['required_if:assign_custom,true', function ($attribute, $value, $fail) {
                    if (request('assign_custom') && !empty($value)) {
                        // Get the selected order
                        $resources = request('resources', []);
                        if (is_array($resources) && count($resources) === 1) {
                            $order = \App\Models\Order::with('giveaways')->find($resources[0]);
                            if ($order && $order->giveaways->isNotEmpty()) {
                                $giveaway = $order->giveaways->first();
                                $customNumbers = array_map('intval', array_filter(explode(',', $value)));
                                $unavailableNumbers = $this->checkUnavailableNumbers($giveaway, $customNumbers, $order->id);

                                if (!empty($unavailableNumbers)) {
                                    $fail('The following custom numbers are not available: ' . implode(', ', $unavailableNumbers));
                                }

                                $invalidNumbers = array_filter($customNumbers, function($num) use ($giveaway) {
                                    return $num < 1 || $num > $giveaway->ticketsTotal;
                                });

                                if (!empty($invalidNumbers)) {
                                    $fail('The following numbers are invalid (out of range): ' . implode(', ', $invalidNumbers));
                                }
                            }
                        }
                    }
                }])
                ->help('Enter custom numbers separated by commas (e.g., 1,3,5). Must match the total amount of tickets for each giveaway.')
                ->placeholder('1,3,5,7,9'),

            Textarea::make('Custom Numbers Status', 'custom_numbers_status')
                ->dependsOn(['assign_custom', 'custom_numbers'], function ($field, $request, $formData) {
                    if (!isset($formData['assign_custom']) || !$formData['assign_custom']) {
                        return $field->default('');
                    }

                    if (empty($formData['custom_numbers'])) {
                        return $field->default('Enter custom numbers to check availability.');
                    }

                    // Get the selected order to determine giveaway
                    $resources = $request->resources ?? [];
                    if (empty($resources) || !is_array($resources) || count($resources) !== 1) {
                        return $field->default('Select a single order to check number availability.');
                    }

                    $order = \App\Models\Order::with('giveaways')->find($resources[0]);
                    if (!$order || $order->giveaways->isEmpty()) {
                        return $field->default('No giveaways found for this order.');
                    }

                    // For simplicity, check against the first giveaway
                    $giveaway = $order->giveaways->first();

                    // Parse custom numbers
                    $customNumbers = array_map('intval', array_filter(explode(',', $formData['custom_numbers'])));

                    if (empty($customNumbers)) {
                        return $field->default('Enter valid numbers to check availability.');
                    }

                    // Check availability using the existing API logic
                    $unavailableNumbers = $this->checkUnavailableNumbers($giveaway, $customNumbers, $order->id);

                    $availableNumbers = array_diff($customNumbers, $unavailableNumbers);
                    $invalidNumbers = array_filter($customNumbers, function($num) use ($giveaway) {
                        return $num < 1 || $num > $giveaway->ticketsTotal;
                    });

                    $status = [];
                    if (!empty($availableNumbers)) {
                        $status[] = "✅ Available: " . implode(', ', $availableNumbers);
                    }
                    if (!empty($unavailableNumbers)) {
                        $status[] = "❌ Unavailable: " . implode(', ', $unavailableNumbers);
                    }
                    if (!empty($invalidNumbers)) {
                        $status[] = "⚠️ Invalid (out of range): " . implode(', ', $invalidNumbers);
                    }

                    return $field->default(implode("\n", $status));
                })
                ->rows(3)
                ->readonly()
                ->dependsOn('assign_custom', true)
                ->help('Shows which of your custom numbers are available, unavailable, or invalid.'),
        ];
    }

    private function reassignTicketsForOrder($order, $fields, $reassignmentType)
    {
        $cart = $order->cart;
        if (!$cart || !is_array($cart)) {
            throw new \Exception("No cart data found for order {$order->id}");
        }

        $giveaways = \App\Models\Giveaway::whereIn('id', collect($cart)->pluck('id'))->get()->keyBy('id');
        $attachData = [];
        $errors = [];

        foreach ($cart as $item) {
            $giveawayId = $item['id'];
            $amount = $item['amount'];
            $requestedNumbers = $item['numbers'] ?? [];

            $giveaway = $giveaways->get($giveawayId);
            if (!$giveaway) {
                $errors[] = "Giveaway not found for ID {$giveawayId}";
                continue;
            }

            $numbersToAssign = [];

            if ($fields->assign_requested) {
                // Try to assign originally requested numbers
                Log::info('Attempting to assign requested numbers in reassign action', [
                    'order_id' => $order->id,
                    'requested_numbers' => $requestedNumbers,
                    'amount' => $amount
                ]);
                $numbersToAssign = $this->getAvailableNumbers($giveaway, $amount, $requestedNumbers, $order->id);
                if (count($numbersToAssign) < $amount) {
                    $errors[] = "Not all requested numbers are available for giveaway {$giveaway->title} (ID: {$giveawayId}). Some numbers may already be assigned to other orders.";
                }
            } elseif ($fields->assign_random) {
                // Assign random numbers
                $numbersToAssign = $this->getAvailableNumbers($giveaway, $amount, [], $order->id);
                if (count($numbersToAssign) < $amount) {
                    $errors[] = "Not enough available numbers for giveaway {$giveaway->title} (ID: {$giveawayId}). Only " . count($numbersToAssign) . " of {$amount} numbers could be assigned.";
                }
            } elseif ($fields->assign_custom && $fields->custom_numbers) {
                // Parse custom numbers
                $customNumbers = array_map('intval', array_filter(explode(',', $fields->custom_numbers)));
                if (count($customNumbers) !== $amount) {
                    $errors[] = "Custom numbers count (" . count($customNumbers) . ") doesn't match required amount ({$amount}) for giveaway {$giveaway->title} (ID: {$giveawayId})";
                    continue;
                }

                // Check if ALL custom numbers are available
                $unavailableNumbers = $this->checkUnavailableNumbers($giveaway, $customNumbers, $order->id);
                if (!empty($unavailableNumbers)) {
                    $errors[] = "Custom numbers " . implode(', ', $unavailableNumbers) . " are not available for giveaway {$giveaway->title} (ID: {$giveawayId}). They may already be assigned to other orders.";
                    continue;
                }

                $numbersToAssign = $customNumbers;
            }

            if (count($numbersToAssign) === $amount) {
                $attachData[$giveawayId] = [
                    'numbers' => json_encode($numbersToAssign),
                    'amount' => $amount
                ];
            } else {
                $errors[] = "Could not assign {$amount} numbers for giveaway {$giveaway->title} (ID: {$giveawayId})";
            }
        }

        // If there are errors, throw an exception to stop the action
        if (!empty($errors)) {
            throw new \Exception("Ticket reassignment failed:\n" . implode("\n", $errors));
        }

        if (!empty($attachData)) {
            try {
                $order->giveaways()->sync($attachData);
                Log::info('Tickets reassigned for order', [
                    'order_id' => $order->id,
                    'attach_data' => $attachData
                ]);

                // Send updated ticket email to user
                $this->sendTicketUpdateEmail($order, $reassignmentType);
            } catch (\Exception $e) {
                throw new \Exception('Failed to save ticket assignments: ' . $e->getMessage());
            }
        }
    }

    private function checkUnavailableNumbers($giveaway, $requestedNumbers, $excludeOrderId = null)
    {
        // Get all taken numbers for this giveaway from completed orders only
        $query = DB::table('giveaway_order')
            ->join('orders', 'giveaway_order.order_id', '=', 'orders.id')
            ->where('giveaway_order.giveaway_id', $giveaway->id)
            ->where('orders.status', 'completed');

        if ($excludeOrderId) {
            $query->where('giveaway_order.order_id', '!=', $excludeOrderId);
        }

        $takenNumbers = $query->orderBy('giveaway_order.id')
            ->pluck('giveaway_order.numbers')
            ->filter()
            ->flatMap(function ($jsonNumbers) {
                return json_decode($jsonNumbers, true) ?: [];
            })
            ->unique()
            ->values()
            ->toArray();

        $unavailable = [];
        foreach ($requestedNumbers as $number) {
            if (in_array($number, $takenNumbers)) {
                $unavailable[] = $number;
            }
            // Also check if number is within valid range
            if ($number < 1 || $number > $giveaway->ticketsTotal) {
                $unavailable[] = $number;
            }
        }

        return array_unique($unavailable);
    }

    private function getAvailableNumbers($giveaway, $amount, $requestedNumbers = [], $excludeOrderId = null)
    {
        // Get all taken numbers for this giveaway from completed orders only
        $query = DB::table('giveaway_order')
            ->join('orders', 'giveaway_order.order_id', '=', 'orders.id')
            ->where('giveaway_order.giveaway_id', $giveaway->id)
            ->where('orders.status', 'completed');

        if ($excludeOrderId) {
            $query->where('giveaway_order.order_id', '!=', $excludeOrderId);
        }

        $takenNumbers = $query->orderBy('giveaway_order.id')
            ->pluck('giveaway_order.numbers')
            ->filter()
            ->flatMap(function ($jsonNumbers) {
                return json_decode($jsonNumbers, true) ?: [];
            })
            ->unique()
            ->values()
            ->toArray();

        Log::info('Checking available numbers for giveaway in reassign action', [
            'giveaway_id' => $giveaway->id,
            'total_tickets' => $giveaway->ticketsTotal,
            'amount_requested' => $amount,
            'taken_numbers_count' => count($takenNumbers),
            'taken_numbers' => $takenNumbers,
            'requested_numbers' => $requestedNumbers,
            'exclude_order_id' => $excludeOrderId
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

        Log::info('Available numbers result in reassign action', [
            'giveaway_id' => $giveaway->id,
            'available_numbers' => $availableNumbers,
            'available_count' => count($availableNumbers),
            'needed_count' => $amount,
            'success' => count($availableNumbers) >= $amount
        ]);

        return $availableNumbers;
    }

    private function sendTicketUpdateEmail($order, $reassignmentType)
    {
        try {
            $email = $order->user?->email;
            if (!$email) {
                Log::warning('Cannot send ticket update email: user email missing', [
                    'order_id' => $order->id
                ]);
                return;
            }

            // Refresh order with giveaway data
            $order->load(['giveaways' => function($query) {
                $query->withPivot(['numbers', 'amount']);
            }]);

            // Log detailed giveaway information
            $ticketInfo = [];
            foreach ($order->giveaways as $giveaway) {
                $numbers = json_decode($giveaway->pivot->numbers ?? '[]', true);
                $ticketInfo[] = "Giveaway {$giveaway->id}: " . implode(', ', $numbers);
            }

            Log::info('Sending ticket update email after reassignment', [
                'order_id' => $order->id,
                'user_email' => $email,
                'reassignment_type' => $reassignmentType,
                'giveaways_count' => $order->giveaways->count(),
                'ticket_numbers' => $ticketInfo
            ]);

            Mail::to($email)->send(new OrderCompleted($order));

            Log::info('Ticket update email sent successfully after reassignment', [
                'order_id' => $order->id,
                'reassignment_type' => $reassignmentType
            ]);

        } catch (\Throwable $ex) {
            Log::error('Failed to send ticket update email after reassignment: ' . $ex->getMessage(), [
                'order_id' => $order->id,
                'reassignment_type' => $reassignmentType,
                'exception' => $ex,
            ]);
        }
    }
}