<?php

namespace App\Http\Controllers\Giveaways;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Giveaway;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use App\Models\Winner;

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
}
