<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use App\Rules\FlexiblePassword;

class UserController extends Controller
{
    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => ['required', 'string', new FlexiblePassword()],
            'role' => [
                'required',
                'string',
                Rule::exists('roles', 'name'),
            ],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
        ]);

        $user->assignRole($validated['role']);

        // <-- TAMBAHKAN BARIS INI UNTUK RESET CACHE PERMISSION
        app()->make(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        Cache::put('user.' . $user->id, $user, 3600);
        Cache::put('user.' . md5($user->email), $user, 3600);
        
        return response()->json([
            'message' => 'User berhasil dibuat.',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->roles->pluck('name')
            ],
        ], 201);
    }
}