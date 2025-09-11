<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tymon\JWTAuth\Facades\JWTAuth;

class OtpVerificationController extends Controller
{
    public function verify(Request $request): JsonResponse
    {
        $request->validate([
            'otp' => 'required|string|size:6',
        ]);

        $otp = $request->otp;

        // Get user from authentication instead of request parameter
        $user = JWTAuth::parseToken()->authenticate();

        if (!$user) {
            return response()->json([
                'message' => 'User not authenticated.',
                'error' => 'unauthenticated'
            ], 401);
        }

        // Check if OTP exists and is valid
        if (!$user->otp_code || $user->otp_code !== $otp) {
            Log::warning('Invalid OTP attempt', [
                'user_id' => $user->id,
                'otp' => $otp,
            ]);
            return response()->json([
                'message' => 'Invalid OTP.',
                'error' => 'invalid_otp'
            ], 400);
        }

        // Check if OTP has expired
        if ($user->otp_expires_at && now()->isAfter($user->otp_expires_at)) {
            Log::warning('Expired OTP attempt', [
                'user_id' => $user->id,
            ]);
            return response()->json([
                'message' => 'OTP has expired. Please request a new one.',
                'error' => 'expired_otp'
            ], 400);
        }

        // Mark user as verified
        $user->email_verified_at = now();
        $user->otp_code = null;
        $user->otp_expires_at = null;
        $user->save();

        // Generate token
        $token = JWTAuth::fromUser($user);
        $ttl = config('jwt.ttl', 60);

        Log::info('User verified with OTP', [
            'user_id' => $user->id,
            'email' => $user->email,
        ]);

        return response()->json([
            'message' => 'Account verified successfully.',
            'user' => $user,
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => $ttl * 60,
        ], 200);
    }

    public function resend(Request $request): JsonResponse
    {
        // Get user from authentication instead of request parameter
        $user = JWTAuth::parseToken()->authenticate();

        if (!$user) {
            return response()->json([
                'message' => 'User not authenticated.',
                'error' => 'unauthenticated'
            ], 401);
        }

        if ($user->email_verified_at) {
            return response()->json([
                'message' => 'Account already verified.',
            ], 400);
        }

        // Generate new OTP
        $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $user->otp_code = $otp;
        $user->otp_expires_at = now()->addMinutes(10);
        $user->save();

        // Send OTP email
        Mail::to($user->email)->send(new \App\Mail\OtpMail($otp));

        Log::info('OTP resent for verification', [
            'user_id' => $user->id,
            'email' => $user->email,
        ]);

        return response()->json([
            'message' => 'OTP sent successfully. Please check your email.',
        ], 200);
    }

    public function ensureOtp(Request $request): JsonResponse
    {
        // Get user from authentication instead of request parameter
        $user = JWTAuth::parseToken()->authenticate();

        if (!$user) {
            return response()->json([
                'message' => 'User not authenticated.',
                'error' => 'unauthenticated'
            ], 401);
        }

        if ($user->email_verified_at) {
            return response()->json([
                'message' => 'Account already verified.',
            ], 400);
        }

        // Check if user has a valid OTP that hasn't expired
        if ($user->otp_code && $user->otp_expires_at && now()->isBefore($user->otp_expires_at)) {
            // OTP exists and is still valid, just resend the email
            Mail::to($user->email)->send(new \App\Mail\OtpMail($user->otp_code));

            Log::info('Existing OTP resent for verification', [
                'user_id' => $user->id,
                'email' => $user->email,
            ]);

            return response()->json([
                'message' => 'OTP sent successfully. Please check your email.',
                'otp_sent' => true,
            ], 200);
        }

        // Generate new OTP if none exists or expired
        $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $user->otp_code = $otp;
        $user->otp_expires_at = now()->addMinutes(10);
        $user->save();

        // Send OTP email
        Mail::to($user->email)->send(new \App\Mail\OtpMail($otp));

        Log::info('New OTP generated and sent for verification', [
            'user_id' => $user->id,
            'email' => $user->email,
        ]);

        return response()->json([
            'message' => 'OTP sent successfully. Please check your email.',
            'otp_sent' => true,
        ], 200);
    }
}
