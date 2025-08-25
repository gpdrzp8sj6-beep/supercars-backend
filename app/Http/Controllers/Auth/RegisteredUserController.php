<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Tymon\JWTAuth\Facades\JWTAuth;
use Coderflex\LaravelTurnstile\Facades\LaravelTurnstile;
use App\Models\Address;

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
        $request->validate([
            'forenames' => 'required|string|max:255',
            'surname' => 'required|string|max:255',
            'date_of_birth' => 'required|string|max:255',
            'phone' => 'required|string|max:255|unique:users,phone',
            'email' => 'required|string|email|max:255|unique:users,email',
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'accept_tos' => ['required', 'boolean', 'accepted'],
            'accept_privacy' => ['required', 'boolean', 'accepted'],
            'captcha' => ['required', 'string'],
            // Address fields (optional for normal signup; required on checkout UI side)
            'address_line_1' => 'nullable|string|max:255',
            'address_line_2' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:255',
            'post_code' => 'nullable|string|max:255',
            'country' => 'nullable|string|max:255',
        ]);

        $cfRes = LaravelTurnstile::validate(
                $request->get('captcha')
            );

        if (! $cfRes['success']) {
            return response()->json([
                        'message' => 'The CAPTCHA thinks you are a robot! Please refresh and try again.'
                    ], 401);
        }

        $user = User::create([
            'forenames' => $request->forenames,
            'surname' => $request->surname,
            'date_of_birth' => $request->date_of_birth,
            'phone' => $request->phone,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        // If address provided, create as a default address entry for the user
        if ($request->filled('address_line_1') || $request->filled('city') || $request->filled('post_code') || $request->filled('country')) {
            // Ensure only one default: unset existing defaults (none at this point, but safe)
            $user->addresses()->update(['is_default' => false]);

            $user->addresses()->create([
                'address_line_1' => $request->address_line_1,
                'address_line_2' => $request->address_line_2,
                'city' => $request->city,
                'post_code' => $request->post_code,
                'country' => $request->country,
                'label' => $request->input('address_label', 'Default'),
                'is_default' => true,
            ]);
        }

        event(new Registered($user));

        $token = JWTAuth::fromUser($user);

        return response()->json([
            'message' => 'User registered successfully',
            'user' => $user,
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => auth('api')->factory()->getTTL() * 60,
        ], 201);
    }
}
