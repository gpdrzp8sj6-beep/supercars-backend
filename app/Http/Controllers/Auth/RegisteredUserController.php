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
            'email' => 'required|confirmed|string|email|max:255|unique:users,email',
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'accept_tos' => ['required', 'boolean', 'accepted'],
            'accept_privacy' => ['required', 'boolean', 'accepted'],
            'captcha' => ['required', 'string'],
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
