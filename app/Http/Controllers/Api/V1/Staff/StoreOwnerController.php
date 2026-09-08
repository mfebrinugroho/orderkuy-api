<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\StoreResource;
use App\Http\Resources\UserResource;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StoreOwnerController extends Controller
{
    public function addOwner(Request $request)
    {
        // Gate::authorize('addOwner', Store::class);

        $validated = $request->validate([
            'store_id' => ['required', 'exists:stores,id'],
            'user_id' => ['required', 'exists:users,id'],
        ]);

        $store = Store::findOrFail($validated['store_id']);
        $owner = User::with(['role', 'stores'])->findOrFail($validated['user_id']);

        // Pastikan user adalah owner
        if ($owner->role->slug !== UserRole::Owner) {
            return response()->json([
                'success' => false,
                'message' => 'User bukan owner.'
            ], 422);
        }

        if ($store->owner()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Store sudah memiliki owner.'
            ], 422);
        }

        // Tambahkan jika belum terhubung
        DB::transaction(function () use ($store, $owner) {
            $store->users()->attach($owner->id);
        });

        return response()->json([
            'success' => true,
            'message' => 'Owner berhasil ditambahkan.',
            'data' => new StoreResource($store),
        ]);
    }

    public function availableUsers()
    {
        $owners = User::with('role')
            ->whereHas('role', function ($query) {
                $query->where('slug', UserRole::Owner);
            })
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Data list owner',
            'data' => UserResource::collection($owners),
        ]);
    }

    public function availableStores()
    {
        $stores = Store::doesntHave('owner')
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Data list resto/toko yang tersedia',
            'data' => StoreResource::collection($stores),
        ]);
    }
}
