<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\Address;

class AddressesController extends Controller
{
    public function index(): JsonResponse
    {
        $user = auth()->user();
        $addresses = $user->addresses()->orderByDesc('is_default')->latest()->get();
        return response()->json($addresses);
    }

    public function store(Request $request): JsonResponse
    {
        $user = auth()->user();
        $data = $request->validate([
            'address_line_1' => 'required|string|max:255',
            'address_line_2' => 'nullable|string|max:255',
            'city' => 'required|string|max:255',
            'post_code' => 'required|string|max:255',
            'country' => 'required|string|max:255',
            'label' => 'nullable|string|max:100',
            'is_default' => 'sometimes|boolean',
        ]);

        if (!empty($data['is_default'])) {
            $user->addresses()->update(['is_default' => false]);
        }

        $address = $user->addresses()->create(array_merge($data, [
            'is_default' => (bool) ($data['is_default'] ?? false),
        ]));

        return response()->json($address, 201);
    }

    public function update(Request $request, Address $address): JsonResponse
    {
        $user = auth()->user();
        abort_unless($address->user_id === $user->id, 403);

        $data = $request->validate([
            'address_line_1' => 'sometimes|required|string|max:255',
            'address_line_2' => 'nullable|string|max:255',
            'city' => 'sometimes|required|string|max:255',
            'post_code' => 'sometimes|required|string|max:255',
            'country' => 'sometimes|required|string|max:255',
            'label' => 'nullable|string|max:100',
            'is_default' => 'sometimes|boolean',
        ]);

        if (array_key_exists('is_default', $data) && $data['is_default']) {
            $user->addresses()->update(['is_default' => false]);
        }

        $address->update($data);

        return response()->json($address);
    }

    public function destroy(Address $address): JsonResponse
    {
        $user = auth()->user();
        abort_unless($address->user_id === $user->id, 403);
        $address->delete();
        return response()->json(['message' => 'Deleted']);
    }

    public function setDefault(Address $address): JsonResponse
    {
        $user = auth()->user();
        abort_unless($address->user_id === $user->id, 403);

        $user->addresses()->update(['is_default' => false]);
        $address->update(['is_default' => true]);

        return response()->json($address->fresh());
    }
}
