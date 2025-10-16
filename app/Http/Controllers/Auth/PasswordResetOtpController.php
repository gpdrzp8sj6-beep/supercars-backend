<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Mail\PasswordResetOtpMail;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;

class PasswordResetOtpController extends Controller
{
    /**
     * Send OTP for password reset
     */
    public function sendOtp(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $email = $request->email;
        $user = User::where('email', $email)->first();

        if (!$user) {
            throw ValidationException::withMessages([
                'email' => ['The email does not exist.'],
            ]);
        }

        // Check rate limiting
        $ip = $request->ip();
        $key = "password_reset_attempts_{$ip}";
        $attempts = Cache::get($key, 0);

        if ($attempts >= 3) {
            Log::warning('Too many password reset attempts', [
                'ip' => $ip,
                'email' => $email,
            ]);
            return response()->json([
                'message' => 'Too many attempts. Please try again later.',
                'error' => 'rate_limit_exceeded'
            ], 429);
        }

        // Increment attempts
        Cache::put($key, $attempts + 1, 3600); // 1 hour

        // Generate OTP
        $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $user->otp_code = $otp;
        $user->otp_expires_at = now()->addMinutes(10);
        $user->save();

                        // Send OTP email
        try {
            Mail::to($user->email)->send(new PasswordResetOtpMail($otp));
        } catch (\Throwable $e) {
            Log::error('Failed to send password reset OTP: ' . $e->getMessage(), [
                'user_id' => $user->id,
                'email' => $user->email,
            ]);
            return response()->json([
                'message' => 'Failed to send OTP. Please try again.',
                'error' => 'email_send_failed'
            ], 500);
        }

        Log::info('Password reset OTP sent', [
            'user_id' => $user->id,
            'email' => $user->email,
        ]);

        return response()->json([
            'message' => 'Password reset OTP sent to your email.',
        ], 200);
    }

    /**
     * Verify OTP for password reset
     */
    public function verifyOtp(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email|exists:users,email',
            'otp' => 'required|string|size:6',
        ]);

        $email = $request->email;
        $otp = $request->otp;
        $user = User::where('email', $email)->first();

        // Check if OTP exists and matches
        if (!$user->otp_code || $user->otp_code !== $otp) {
            Log::warning('Invalid password reset OTP', [
                'user_id' => $user->id,
                'email' => $email,
                'otp' => $otp,
            ]);
            return response()->json([
                'message' => 'Invalid OTP.',
                'error' => 'invalid_otp'
            ], 400);
        }

        // Check if OTP has expired
        if ($user->otp_expires_at && now()->isAfter($user->otp_expires_at)) {
            Log::warning('Expired password reset OTP', [
                'user_id' => $user->id,
                'email' => $email,
            ]);
            return response()->json([
                'message' => 'OTP has expired. Please request a new one.',
                'error' => 'expired_otp'
            ], 400);
        }

        // Mark OTP as verified by setting a temporary flag
        $user->otp_verified_at = now();
        $user->save();

        Log::info('Password reset OTP verified', [
            'user_id' => $user->id,
            'email' => $user->email,
        ]);

        return response()->json([
            'message' => 'OTP verified successfully.',
        ], 200);
    }

    /**
     * Reset password with verified OTP
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email|exists:users,email',
            'otp' => 'required|string|size:6',
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $email = $request->email;
        $otp = $request->otp;
        $user = User::where('email', $email)->first();

        // Verify OTP again for security
        if (!$user->otp_code || $user->otp_code !== $otp) {
            return response()->json([
                'message' => 'Invalid OTP.',
                'error' => 'invalid_otp'
            ], 400);
        }

        if ($user->otp_expires_at && now()->isAfter($user->otp_expires_at)) {
            return response()->json([
                'message' => 'OTP has expired. Please request a new one.',
                'error' => 'expired_otp'
            ], 400);
        }

        // Update password
        $user->password = Hash::make($request->password);
        $user->otp_code = null;
        $user->otp_expires_at = null;
        $user->otp_verified_at = null;
        $user->save();

        // Clear rate limiting
        $ip = $request->ip();
        Cache::forget("password_reset_attempts_{$ip}");

        Log::info('Password reset successfully', [
            'user_id' => $user->id,
            'email' => $user->email,
        ]);

        return response()->json([
            'message' => 'Password reset successfully.',
        ], 200);
    }
}