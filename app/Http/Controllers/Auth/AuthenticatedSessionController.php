<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;
use App\Models\User;
class AuthenticatedSessionController extends Controller
{
    /**
     * Handle an incoming authentication request (login).
     */
    public function store(LoginRequest $request): JsonResponse
    {
        $credentials = $request->only('email', 'password');

        try {
            if (! $token = JWTAuth::attempt($credentials)) {
                return response()->json(['error' => 'Invalid credentials'], 401);
            }
        } catch (JWTException $e) {
            return response()->json(['error' => 'Could not create token'], 500);
        }

        $user = auth()->user();

        if (!$user->email_verified_at) {
            return response()->json([
                'error' => 'Account not verified',
                'message' => 'Please verify your email address to continue.',
                'requires_verification' => true,
                'user_id' => $user->id,
                'redirect_to' => '/auth/verify',
                'access_token' => $token,
                'token_type' => 'bearer',
                'expires_in' => auth('api')->factory()->getTTL() * 60,
            ], 403);
        }

        // Return JWT token in response
        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => auth('api')->factory()->getTTL() * 60,
            'user' => $user,
        ]);
    }

    /**
     * Destroy an authenticated session (logout).
     */
    public function destroy(Request $request): JsonResponse
    {
        try {
            JWTAuth::invalidate(JWTAuth::getToken());
            return response()->json(['message' => 'Successfully logged out']);
        } catch (JWTException $e) {
            return response()->json(['error' => 'Failed to logout, token invalid'], 500);
        }
    }

    /**
     * Return authenticated user details (optional).
     */
    public function me(): JsonResponse
    {
        $userId = auth()->user()->id;
        $user = User::with(['addresses', 'defaultAddress'])->findOrFail($userId);
        $user->tickets_bought = $user->ticketsBought();
        return response()->json($user);
    }
}
