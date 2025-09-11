<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use App\Mail\WelcomeUser;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rules;
use Tymon\JWTAuth\Facades\JWTAuth;
use Coderflex\LaravelTurnstile\Facades\LaravelTurnstile;
use Illuminate\Validation\ValidationException;

class RegisteredUserController extends Controller
{
    /**
     * Registration page is irrelevant for API, so no UI rendering here.
     * If you want, you can remove or keep an empty method.
     */
    public function create(): JsonResponse
    {
        return response()->json(['message' => 'Golang404'], 404);
    }

    /**
     * Handle an incoming registration request (API).
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): JsonResponse
    {
        try {

            $ip = $request->ip();
            $email = $request->input('email');
            $phone = $request->input('phone');

            // Check for suspicious rapid attempts from same IP
            $recentAttempts = Cache::get("registration_attempts_{$ip}", 0);
            if ($recentAttempts >= 2) {
                Log::warning('Suspicious registration activity detected', [
                    'ip' => $ip,
                    'email' => $email,
                    'phone' => $phone,
                    'attempts' => $recentAttempts + 1
                ]);
                return response()->json([
                    'message' => 'Too many registration attempts. Please try again later.',
                    'error' => 'rate_limit_exceeded'
                ], 429);
            }

            // Increment the attempt counter
            Cache::put("registration_attempts_{$ip}", $recentAttempts + 1, 600); // 10 minutes
            // Check for suspicious email patterns (similar emails from same IP)
            $emailPattern = preg_replace('/\d+/', '*', $email); // Replace numbers with *
            $emailKey = "email_pattern_{$ip}_{$emailPattern}";
            $similarEmails = Cache::get($emailKey, 0);
            if ($similarEmails >= 1) {
                Log::warning('Multiple similar emails from same IP detected', [
                    'ip' => $ip,
                    'email_pattern' => $emailPattern,
                    'current_email' => $email,
                    'similar_count' => $similarEmails + 1
                ]);
                return response()->json([
                    'message' => 'Suspicious registration pattern detected. Please try again later.',
                    'error' => 'suspicious_pattern'
                ], 429);
            }
            Cache::put($emailKey, $similarEmails + 1, 3600); // 1 hour

            Log::info('Registration attempt started', [
                'ip' => $request->ip(),
                'email' => $request->input('email'),
                'phone' => $request->input('phone'),
                'request' => $request->except(['password', 'password_confirmation', 'captcha'])
            ]);
            $request->validate([
                'forenames' => 'required|string|max:255',
                'surname' => 'required|string|max:255',
                'date_of_birth' => 'required|string|max:255',
                'phone' => 'required|string|max:255|unique:users,phone',
                'email' => 'required|string|email|max:255|unique:users,email',
                'password' => ['required', 'confirmed', Rules\Password::defaults()],
                'accept_tos' => ['required', 'boolean', 'accepted'],
                'accept_privacy' => ['required', 'boolean', 'accepted'],
                'captcha' => 'nullable|string',
                // Address fields (optional for normal signup; required on checkout UI side)
                'address_line_1' => 'nullable|string|max:255',
                'address_line_2' => 'nullable|string|max:255',
                'city' => 'nullable|string|max:255',
                'post_code' => 'nullable|string|max:255',
                'country' => 'nullable|string|max:255',
            ]);

            // Skip captcha validation if secret key not set or captcha not provided (for testing)
            $turnstileSecret = config('turnstile.turnstile_secret_key');
            if (!empty($turnstileSecret) && $request->filled('captcha')) {
                try {
                    $cfRes = LaravelTurnstile::validate($request->get('captcha'));
                    if (!($cfRes['success'] ?? false)) {
                        Log::warning('Registration failed CAPTCHA', [
                            'ip' => $request->ip(),
                            'email' => $request->input('email'),
                            'phone' => $request->input('phone'),
                            'cf_response' => $cfRes,
                        ]);
                        return response()->json([
                            'message' => 'The CAPTCHA thinks you are a robot! Please refresh and try again.'
                        ], 401);
                    }
                } catch (\Throwable $cfEx) {
                    // Gracefully handle missing/invalid secret or API errors
                    Log::error('Turnstile validation error: ' . $cfEx->getMessage(), [
                        'ip' => $request->ip(),
                        'email' => $request->input('email'),
                        'exception' => $cfEx,
                    ]);
                    // Do not block registration due to CAPTCHA infra error in non-prod/dev setups
                }
            }

            $user = User::create([
                'forenames' => $request->forenames,
                'surname' => $request->surname,
                'date_of_birth' => $request->date_of_birth,
                'phone' => $request->phone,
                'email' => $request->email,
                'password' => Hash::make($request->password),
            ]);
            Log::info('User created during registration', [
                'user_id' => $user->id,
                'email' => $user->email,
                'phone' => $user->phone,
            ]);

            // If address provided, create as a default address entry for the user
            if ($request->filled('address_line_1') || $request->filled('city') || $request->filled('post_code') || $request->filled('country')) {
                // Ensure only one default: unset existing defaults (none at this point, but safe)
                $user->addresses()->update(['is_default' => false]);

                $address = $user->addresses()->create([
                    'address_line_1' => $request->address_line_1,
                    'address_line_2' => $request->address_line_2,
                    'city' => $request->city,
                    'post_code' => $request->post_code,
                    'country' => $request->country,
                    'label' => $request->input('address_label', 'Default'),
                    'is_default' => true,
                ]);
                Log::info('Address created during registration', [
                    'user_id' => $user->id,
                    'address_id' => $address->id,
                ]);
            }

            event(new Registered($user));
            Log::info('Registration event fired', [
                'user_id' => $user->id,
            ]);

            // Send welcome email (do not fail registration if email fails)
            try {
                Log::info('Mail configuration before sending welcome email', [
                    'mail_default' => config('mail.default'),
                    'smtp_host' => config('mail.mailers.smtp.host'),
                    'smtp_port' => config('mail.mailers.smtp.port'),
                    'from_address' => config('mail.from.address'),
                ]);
                Mail::to($user->email)->send(new WelcomeUser($user));
                Log::info('Welcome email sent', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                ]);
            } catch (\Throwable $mailEx) {
                Log::error('Failed to send welcome email: ' . $mailEx->getMessage(), [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'exception' => $mailEx,
                ]);
            }

            $token = JWTAuth::fromUser($user);
            $ttl = config('jwt.ttl', 60); // fallback to 60 if not set

            Log::info('Registration successful', [
                'user_id' => $user->id,
                'email' => $user->email,
            ]);

            return response()->json([
                'message' => 'User registered successfully',
                'user' => $user,
                'access_token' => $token,
                'token_type' => 'bearer',
                'expires_in' => $ttl * 60,
            ], 201);
        } catch (ValidationException $e) {
            Log::error('Registration validation error: ' . $e->getMessage(), [
                'exception' => $e,
                'request' => $request->all()
            ]);

            // Return specific validation errors to the user
            return response()->json([
                'message' => 'Registration failed due to validation errors.',
                'error' => 'validation_failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Registration error: ' . $e->getMessage(), [
                'exception' => $e,
                'request' => $request->all()
            ]);
            return response()->json([
                'message' => 'Unexpected error during signup. Please try again later.',
                'error' => 'server_error'
            ], 500);
        }
    }
}
