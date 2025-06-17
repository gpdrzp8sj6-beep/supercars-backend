<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class PasswordController extends Controller
{
    /**
     * Update the user's password.
     */
 public function update(Request $request)
 {
     $validated = $request->validate([
         'current_password' => ['required'],
         'password' => ['required', Password::defaults(), 'confirmed'],
         'phone' => ['sometimes', 'string'],
     ]);

     $user = $request->user();

     if (!Hash::check($validated['current_password'], $user->password)) {
         throw ValidationException::withMessages([
             'current_password' => ['The current password is incorrect.'],
         ]);
     }

     $updateData = [
         'password' => Hash::make($validated['password']),
     ];

     if (isset($validated['phone'])) {
         $updateData['phone'] = $validated['phone'];
     }

     $user->update($updateData);

     return response()->json([
         'message' => 'Profile updated successfully.',
     ]);
 }
}
