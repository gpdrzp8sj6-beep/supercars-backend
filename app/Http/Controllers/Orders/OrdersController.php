<?php

namespace App\Http\Controllers\Orders;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\Giveaway;
use App\Models\Order;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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

        if (count($availableNumbers) < $amount) {
            throw ValidationException::withMessages([
                'tickets' => ['Not enough tickets available to assign requested amount.'],
            ]);
        }

        shuffle($availableNumbers);
        return array_slice($availableNumbers, 0, $amount);
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

        $order = null;

        DB::transaction(function () use ($request, $user, $total, $giveaways, &$order) {
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
            ]);

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
                $seenRequested = [];

                foreach ($requestedNumbers as $num) {
                    if ($num < 1 || $num > $giveaway->ticketsTotal) {
                        // Skip out of range number silently (or throw if you want strict)
                        continue;
                    }
                    if (in_array($num, $seenRequested)) {
                        // Duplicate in requested, skip
                        continue;
                    }
                    $seenRequested[] = $num;
                    $validRequestedNumbers[] = $num;
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
        });

        return response()->json([
            'message' => 'Order created successfully',
            'order_id' => $order->id,
        ], 201);
    }
}
