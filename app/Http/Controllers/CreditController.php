<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\CreditTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class CreditController extends Controller
{
    public function manageCredit(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'amount' => 'required|numeric|min:0.01',
            'type' => 'required|in:add,deduct',
            'description' => 'required|string|max:255',
        ]);

        $user = User::findOrFail($request->user_id);

        DB::transaction(function () use ($user, $request) {
            if ($request->type === 'add') {
                $user->credit = $user->credit + $request->amount;
            } else {
                // For deduct, ensure user has enough credit
                if ($user->credit < $request->amount) {
                    throw new \Exception('Insufficient credit balance');
                }
                $user->credit = $user->credit - $request->amount;
            }
            $user->save();

            CreditTransaction::create([
                'user_id' => $user->id,
                'amount' => $request->amount,
                'type' => $request->type,
                'description' => $request->description,
            ]);
        });

        return response()->json([
            'message' => 'Credit ' . ($request->type === 'add' ? 'added' : 'deducted') . ' successfully',
            'user' => $user->fresh(),
        ]);
    }
}
