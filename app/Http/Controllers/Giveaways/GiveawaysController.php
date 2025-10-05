<?php

namespace App\Http\Controllers\Giveaways;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Giveaway;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use App\Models\Winner;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class GiveawaysController extends Controller
{
    public function index(Request $request, $id): JsonResponse
    {
        try {
            $giveaway = Giveaway::with('winningOrders.user')->findOrFail($id);
            $giveaway->setHidden(['winningOrders']);
            $result = array_merge(
                $giveaway->toArray(),
                [
                 'winners' => $giveaway->winningOrders->map(function ($order) {
                     return [
                         'fullName' => $order->user->fullName,
                         'winning_ticket' => $order->pivot->winning_ticket,
                     ];
                 }),
                ]
            );

             return response()->json($result);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'error' => 'Not found',
            ], 404);
        }
    }

 public function getWinners(Request $request): JsonResponse
    {
        try {
            $winners = Winner::all();
            return response()->json($winners, 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'error' => 'Not found',
            ], 404);
        }
    }

    public function getDrawingSoon(Request $request): JsonResponse
    {
        $limit = $request->query('limit', 6);
        if($limit > 10) {
            $limit = 6;
        }

        return response()->json(Giveaway::closestToClosing($limit), 200);
    }

    public function getJustLaunched(Request $request): JsonResponse
    {
        $limit = $request->query('limit', 6);
        if($limit > 10) {
            $limit = 6;
        }

        return response()->json(Giveaway::justLaunched($limit), 200);
    }

    public function checkTicketAvailability(Request $request, $id): JsonResponse
    {
        $request->validate([
            'numbers' => 'required|array|min:1',
            'numbers.*' => 'integer|min:1',
        ]);

        try {
            $giveaway = Giveaway::findOrFail($id);
            $requestedNumbers = $request->numbers;

            // Get all assigned numbers for this giveaway (from completed orders)
            $assignedNumbers = DB::table('giveaway_order')
                ->join('orders', 'giveaway_order.order_id', '=', 'orders.id')
                ->where('giveaway_order.giveaway_id', $id)
                ->where('orders.status', 'completed')
                ->pluck('giveaway_order.numbers')
                ->filter()
                ->flatMap(function ($jsonNumbers) {
                    return json_decode($jsonNumbers, true) ?: [];
                })
                ->unique()
                ->values();

            $availableNumbers = [];
            $unavailableNumbers = [];
            $invalidNumbers = [];

            foreach ($requestedNumbers as $num) {
                if ($num < 1 || $num > $giveaway->ticketsTotal) {
                    $invalidNumbers[] = $num;
                } elseif ($assignedNumbers->contains($num)) {
                    $unavailableNumbers[] = $num;
                } else {
                    $availableNumbers[] = $num;
                }
            }

            return response()->json([
                'available' => $availableNumbers,
                'unavailable' => $unavailableNumbers,
                'invalid' => $invalidNumbers,
                'all_available' => empty($unavailableNumbers) && empty($invalidNumbers),
                'stats' => [
                    'total_requested' => count($requestedNumbers),
                    'available_count' => count($availableNumbers),
                    'unavailable_count' => count($unavailableNumbers),
                    'invalid_count' => count($invalidNumbers),
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to check ticket availability',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
