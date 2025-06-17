<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class EmailVerificationPromptController extends Controller
{
    /**
     * Show the email verification prompt (API).
     */
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'verified' => true,
                'message' => 'Email already verified.',
            ], 200);
        }

        return response()->json([
            'verified' => false,
            'message' => 'Email not verified. Please verify your email.',
        ], 401);
    }
}
