<?php

namespace App\Http\Controllers\Orders;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\Giveaway;
use App\Models\Order;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use App\Mail\OrderCompleted;
use Illuminate\Validation\ValidationException;
use App\Models\CreditTransaction;

class OrdersController extends Controller
{
    public function index(Request $request): JsonResponse
    {
       $user = $request->user();
       $orders = $user->orders()->with('giveaways')->orderBy('id', 'desc')->get();
       return response()->json($orders, 200);
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

    protected function assignRandomNumbers(Collection $assignedNumbers, int $amount, int $ticketsTotal): array
    {
        $availableNumbers = [];

        for ($i = 1; $i <= $ticketsTotal; $i++) {
            if (! $assignedNumbers->contains($i)) {
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

        // Shuffle and select random numbers
        shuffle($availableNumbers);
        $selectedNumbers = array_slice($availableNumbers, 0, $amount);

        // Sort the numbers for consistency (optional, but makes them appear in order)
        sort($selectedNumbers);

        return $selectedNumbers;
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'forenames' => 'required|string|max:255',
            'surname' => 'required|string|max:255',
            'phone' => 'required|string|max:255',
            'addressLine1' => 'required|string|max:255',
            'addressLine2' => 'nullable|string|max:255',
            'city' => 'required|string|max:255',
            'postCode' => 'required|string|max:255',
            'country' => 'required|string|max:255',
            'cart' => 'required|array|min:1',
            'cart.*.id' => 'required|integer|exists:giveaways,id',
            'cart.*.amount' => 'required|integer|min:1',
            'cart.*.numbers' => 'nullable|array',
            'cart.*.numbers.*' => 'integer',
        ]);

        $user = $request->user();
        $total = 0;

        $giveaways = Giveaway::whereIn('id', collect($request->cart)->pluck('id'))->get()->keyBy('id');

        foreach ($request->cart as $item) {
            $giveaway = $giveaways->get($item['id']);
            $total += $giveaway->price * $item['amount'];
        }

        // Handle credit deduction
        $creditUsed = 0;
        if ($user->credit > 0 && $total > 0) {
            $creditUsed = min($user->credit, $total);
            $total -= $creditUsed;
        }

        $order = null;

        DB::transaction(function () use ($request, $user, $total, $giveaways, &$order, $creditUsed) {
            // Deduct credit from user if any
            if ($creditUsed > 0) {
                $user->credit = $user->credit - $creditUsed;
                $user->save();
            }

            $order = Order::create([
                'user_id' => $user->id,
                'status' => $total == 0 ? 'completed' : 'pending',
                'total' => $total,
                'forenames' => $request->forenames,
                'surname' => $request->surname,
                'phone' => $request->phone,
                'address_line_1' => $request->addressLine1,
                'address_line_2' => $request->addressLine2,
                'city' => $request->city,
                'post_code' => $request->postCode,
                'country' => $request->country,
                'cart' => $request->cart,
                'credit_used' => $creditUsed,
            ]);

            // Create credit transaction if credit was used
            if ($creditUsed > 0) {
                CreditTransaction::create([
                    'user_id' => $user->id,
                    'amount' => $creditUsed,
                    'type' => 'deduct',
                    'description' => 'Used credit for order',
                    'order_id' => $order->id,
                ]);
            }

            // Only assign tickets immediately for free giveaways (total = 0)
            // For paid orders, tickets will be assigned when payment is confirmed
            if ($total == 0) {
                $attachData = [];

                foreach ($request->cart as $item) {
                    $giveawayId = $item['id'];
                    $amount = $item['amount'];
                    $requestedNumbers = $item['numbers'] ?? [];

                    $giveaway = $giveaways->get($giveawayId);

                    // Enforce per-order limit
                    if ($amount > $giveaway->ticketsPerUser) {
                        throw ValidationException::withMessages([
                            'cart' => ["Amount for giveaway ID {$giveawayId} exceeds ticketsPerUser limit."],
                        ]);
                    }

                    // Enforce cumulative per-user limit across previous completed orders
                    $existingUserNumbers = DB::table('giveaway_order')
                        ->join('orders', 'giveaway_order.order_id', '=', 'orders.id')
                        ->where('orders.user_id', $user->id)
                        ->where('orders.status', 'completed')
                        ->where('giveaway_order.giveaway_id', $giveawayId)
                        ->orderBy('giveaway_order.id')
                        ->pluck('giveaway_order.numbers')
                        ->filter()
                        ->flatMap(function ($jsonNumbers) {
                            return json_decode($jsonNumbers, true) ?: [];
                        });

                    $existingCountForUser = $existingUserNumbers->count();
                    if (($existingCountForUser + $amount) > $giveaway->ticketsPerUser) {
                        throw ValidationException::withMessages([
                            'cart' => [
                                "You already have {$existingCountForUser} ticket(s) for giveaway ID {$giveawayId}. " .
                                "Purchasing {$amount} more exceeds the per-user limit of {$giveaway->ticketsPerUser}."
                            ],
                        ]);
                    }

                    $assignedNumbers = $this->getAssignedNumbersForGiveaway($giveawayId);
                    $currentlyAssignedCount = $assignedNumbers->count();

                    if ($currentlyAssignedCount + $amount > $giveaway->ticketsTotal) {
                        throw ValidationException::withMessages([
                            'cart' => ["Not enough tickets left in giveaway ID {$giveawayId} to fulfill the request."],
                        ]);
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

                    // If any requested numbers are invalid, throw an error
                    if (!empty($invalidNumbers)) {
                        throw ValidationException::withMessages([
                            'cart' => ["Invalid ticket numbers for giveaway ID {$giveawayId}: " . implode(', ', $invalidNumbers) . ". Valid range is 1-{$giveaway->ticketsTotal}."],
                        ]);
                    }

                    // Filter out numbers already assigned, we will replace them later
                    $availableRequestedNumbers = array_filter($validRequestedNumbers, function ($num) use ($assignedNumbers) {
                        return !$assignedNumbers->contains($num);
                    });

                    $numbersToAssignCount = $amount;

                    // Now assign random numbers for:
                    // 1. The tickets user bought minus the valid requested and available numbers
                    $remainingCount = $numbersToAssignCount - count($availableRequestedNumbers);

                    // Merge assigned numbers with user valid requested numbers to exclude them when generating randoms
                    $excludedNumbers = $assignedNumbers->merge($availableRequestedNumbers);

                    $randomNumbers = $this->assignRandomNumbers($excludedNumbers, $remainingCount, $giveaway->ticketsTotal);

                    $finalNumbers = array_merge($availableRequestedNumbers, $randomNumbers);

                    // Double-check we have exact amount
                    if (count($finalNumbers) !== $amount) {
                        throw ValidationException::withMessages([
                            'cart' => ["Failed to assign enough unique ticket numbers for giveaway ID {$giveawayId}."],
                        ]);
                    }

                    $attachData[$giveawayId] = [
                        'numbers' => json_encode($finalNumbers),
                    ];
                }

                $order->giveaways()->attach($attachData);
            }
        }, 3);

        // Safety: ensure order was created
        if (!$order) {
            Log::error('Order creation failed: $order is null after transaction.');
            return response()->json([
                'message' => 'Failed to create order',
            ], 500);
        }

        // Send OrderCompleted email for credit-only payments (since they start as completed)
        if ($order->status === 'completed' && $order->total == 0 && $order->credit_used > 0) {
            try {
                $email = $order->user?->email;
                if ($email) {
                    // Ensure giveaways relationship is loaded with pivot data for email
                    $order->load(['giveaways' => function($query) {
                        $query->withPivot(['numbers', 'amount']);
                    }]);

                    Mail::to($email)->send(new OrderCompleted($order));
                    Log::info('Order completed email sent for credit-only payment.', [
                        'order_id' => $order->id,
                        'user_email' => $email,
                        'credit_used' => $order->credit_used
                    ]);
                } else {
                    Log::warning('Credit-only order completed but user email missing.', [
                        'order_id' => $order->id
                    ]);
                }
            } catch (\Throwable $ex) {
                Log::error('Failed to send order completed email for credit payment: ' . $ex->getMessage(), [
                    'order_id' => $order->id,
                    'exception' => $ex,
                ]);
            }
        }

        // Auto-save address to user's address book if it's new
        $this->saveAddressIfNew($user, $request);

        return response()->json([
            'message' => 'Order created successfully',
            'order_id' => $order->id,
            'status' => $order->status,
            'total' => $order->total,
            'credit_used' => $order->credit_used,
            'requires_payment' => $order->total > 0,
        ], 201);
    }

    /**
     * Save the address to user's address book if it doesn't already exist
     */
    private function saveAddressIfNew($user, $request)
    {
        // Check if this address already exists for the user
        $existingAddress = $user->addresses()->where([
            'address_line_1' => $request->addressLine1,
            'city' => $request->city,
            'post_code' => $request->postCode,
            'country' => $request->country,
        ])->first();

        // If address doesn't exist, create it
        if (!$existingAddress) {
            // If user has no addresses, make this the default
            $isDefault = $user->addresses()->count() === 0;

            // If making this the default, clear other defaults
            if ($isDefault) {
                $user->addresses()->update(['is_default' => false]);
            }

            $user->addresses()->create([
                'address_line_1' => $request->addressLine1,
                'address_line_2' => $request->addressLine2,
                'city' => $request->city,
                'post_code' => $request->postCode,
                'country' => $request->country,
                'label' => 'Billing Address',
                'is_default' => $isDefault,
            ]);
        }
    }
}
