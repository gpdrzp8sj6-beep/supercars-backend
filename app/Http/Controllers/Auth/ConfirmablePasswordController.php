<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class ConfirmablePasswordController extends Controller
{
    /**
     * Show confirm password is not needed for API, so just respond with info.
     */
    public function show(): JsonResponse
    {
        return response()->json(['message' => 'Send POST request with password to confirm'], 200);
    }

    /**
     * Confirm the user's password (API).
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'password' => ['required', 'string'],
        ]);

        $user = Auth::guard('api')->user();

        if (! Auth::guard('api')->validate([
            'email' => $user->email,
            'password' => $request->password,
        ])) {
            throw ValidationException::withMessages([
                'password' => __('auth.password'),
            ]);
        }

        // Since JWT API is stateless, you can't store password_confirmed_at in session.
        // You can optionally return success response or issue a fresh token if needed.

        return response()->json([
            'message' => 'Password confirmed successfully',
        ]);
    }
}
