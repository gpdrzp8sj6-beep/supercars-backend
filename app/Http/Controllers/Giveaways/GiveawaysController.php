<?php

namespace App\Http\Controllers\Giveaways;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Giveaway;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use App\Models\Winner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

    public function getOrders(Request $request, $id): JsonResponse
    {
        try {
            $giveaway = Giveaway::findOrFail($id);
            
            $searchTerm = $request->query('search');
            
            Log::info('Search request for giveaway ' . $id . ':', ['search_term' => $searchTerm]);
            
            // Try direct DB query instead of relationship
            $query = DB::table('giveaway_order')
                ->join('orders', 'giveaway_order.order_id', '=', 'orders.id')
                ->join('users', 'orders.user_id', '=', 'users.id')
                ->where('giveaway_order.giveaway_id', $id)
                ->select([
                    'orders.id',
                    'users.forenames',
                    'users.surname', 
                    'users.email',
                    'giveaway_order.numbers',
                    'giveaway_order.amount',
                    'orders.created_at',
                    'orders.status'
                ]);
            
            // Apply search filter if provided
            if ($searchTerm) {
                $query->where(function ($q) use ($searchTerm) {
                    // Search in user name (forenames + surname) - trim and case insensitive
                    $q->whereRaw("LOWER(TRIM(CONCAT(COALESCE(users.forenames, ''), ' ', COALESCE(users.surname, '')))) LIKE LOWER(?)", ['%' . $searchTerm . '%'])
                      // Search in order ID
                      ->orWhere('orders.id', 'LIKE', '%' . $searchTerm . '%')
                      // Search in ticket numbers (JSON array as string)
                      ->orWhere('giveaway_order.numbers', 'LIKE', '%' . $searchTerm . '%');
                });
            }
            
            $ordersData = $query->orderBy('orders.created_at', 'desc')
                ->get()
                ->map(function ($row) {
                    $fullName = trim(($row->forenames ?? '') . ' ' . ($row->surname ?? ''));
                    if (empty($fullName)) {
                        $fullName = 'Unknown';
                    }
                    
                    $numbers = json_decode($row->numbers, true) ?? [];
                    
                    return [
                        'id' => $row->id,
                        'fullName' => $fullName,
                        'email' => $row->email ?? '',
                        'ticket_numbers' => $numbers,
                        'amount' => $row->amount,
                        'created_at' => $row->created_at,
                        'status' => $row->status,
                    ];
                });

            Log::info('Orders from DB query for giveaway ' . $id . ':', [
                'count' => $ordersData->count(),
                'search_term' => $searchTerm
            ]);

            return response()->json($ordersData);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'error' => 'Giveaway not found',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error fetching orders for giveaway ' . $id . ': ' . $e->getMessage());
            return response()->json([
                'error' => 'Failed to fetch orders',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function getUserEntries(Request $request, $id): JsonResponse
    {
        try {
            $giveaway = Giveaway::findOrFail($id);
            $user = auth()->user();

            if (!$user) {
                return response()->json([
                    'error' => 'Unauthorized'
                ], 401);
            }

            // Get user's orders for this giveaway
            $userOrdersData = DB::table('giveaway_order')
                ->join('orders', 'giveaway_order.order_id', '=', 'orders.id')
                ->join('users', 'orders.user_id', '=', 'users.id')
                ->where('giveaway_order.giveaway_id', $id)
                ->where('orders.user_id', $user->id)
                ->select([
                    'orders.id',
                    'users.forenames',
                    'users.surname', 
                    'users.email',
                    'giveaway_order.numbers',
                    'giveaway_order.amount',
                    'orders.created_at',
                    'orders.status'
                ])
                ->orderBy('orders.created_at', 'desc')
                ->get()
                ->map(function ($row) {
                    $fullName = trim(($row->forenames ?? '') . ' ' . ($row->surname ?? ''));
                    if (empty($fullName)) {
                        $fullName = 'Unknown';
                    }
                    
                    $numbers = json_decode($row->numbers, true) ?? [];
                    
                    return [
                        'id' => $row->id,
                        'fullName' => $fullName,
                        'email' => $row->email ?? '',
                        'ticket_numbers' => $numbers,
                        'amount' => $row->amount,
                        'created_at' => $row->created_at,
                        'status' => $row->status,
                    ];
                });

            Log::info('User orders from DB query for giveaway ' . $id . ' and user ' . $user->id . ':', [
                'count' => $userOrdersData->count()
            ]);

            return response()->json($userOrdersData);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'error' => 'Giveaway not found',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error fetching user orders for giveaway ' . $id . ': ' . $e->getMessage());
            return response()->json([
                'error' => 'Failed to fetch user orders',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
