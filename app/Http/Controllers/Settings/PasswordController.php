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
         'default_address' => ['sometimes', 'array'],
         'default_address.address_line_1' => ['sometimes', 'string'],
         'default_address.address_line_2' => ['sometimes', 'nullable', 'string'],
         'default_address.city' => ['sometimes', 'string'],
         'default_address.post_code' => ['sometimes', 'string'],
         'default_address.country' => ['sometimes', 'string'],
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

     // Handle address update
     if (isset($validated['default_address'])) {
         $addressData = [
             'address_line_1' => $validated['default_address']['address_line_1'] ?? '',
             'address_line_2' => $validated['default_address']['address_line_2'] ?? null,
             'city' => $validated['default_address']['city'] ?? '',
             'post_code' => $validated['default_address']['post_code'] ?? '',
             'country' => $validated['default_address']['country'] ?? '',
         ];

         $defaultAddress = $user->defaultAddress;
         if ($defaultAddress) {
             $defaultAddress->update($addressData);
         } else {
             $addressData['is_default'] = true;
             $user->addresses()->create($addressData);
         }
     }

     return response()->json([
         'message' => 'Profile updated successfully.',
     ]);
 }
}
