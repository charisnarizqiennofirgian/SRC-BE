<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use App\Rules\FlexiblePassword;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    /**
     * Display a listing of users
     */
    public function index()
    {
        $users = User::with('roles')->get();

        return response()->json([
            'success' => true,
            'message' => 'Data users berhasil diambil.',
            'data' => $users,
        ]);
    }

    /**
     * Store a newly created user
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

        // Reset cache permission
        app()->make(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        Cache::put('user.' . $user->id, $user, 3600);
        Cache::put('user.' . md5($user->email), $user, 3600);

        return response()->json([
            'success' => true,
            'message' => 'User berhasil dibuat.',
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->roles->pluck('name')
            ],
        ], 201);
    }

    /**
     * Display the specified user
     */
    public function show($id)
    {
        $user = User::with('roles.permissions')->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $user,
        ]);
    }

    /**
     * Update the specified user
     */
    public function update(Request $request, $id)
    {
        $user = User::findOrFail($id);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,' . $id,
            'password' => ['nullable', new FlexiblePassword()],
            'role' => [
                'required',
                'string',
                Rule::exists('roles', 'name'),
            ],
        ]);

        $user->update([
            'name' => $validated['name'],
            'email' => $validated['email'],
        ]);

        // Update password jika diisi
        if (!empty($validated['password'])) {
            $user->update([
                'password' => Hash::make($validated['password']),
            ]);
        }

        // Update role
        $user->syncRoles([$validated['role']]);

        // Reset cache
        app()->make(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        Cache::forget('user.' . $user->id);
        Cache::forget('user.' . md5($user->email));
        Cache::put('user.' . $user->id, $user->fresh(), 3600);
        Cache::put('user.' . md5($user->email), $user->fresh(), 3600);

        return response()->json([
            'success' => true,
            'message' => 'User berhasil diupdate.',
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->roles->pluck('name')
            ],
        ]);
    }

    /**
     * Remove the specified user
     */
    public function destroy($id)
    {
        $user = User::findOrFail($id);

        // Prevent deleting yourself
        if ($user->id === auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'Tidak bisa menghapus user sendiri.',
            ], 422);
        }

        // Clear cache
        Cache::forget('user.' . $user->id);
        Cache::forget('user.' . md5($user->email));

        $user->delete();

        return response()->json([
            'success' => true,
            'message' => 'User berhasil dihapus.',
        ]);
    }

    /**
     * Reset user password
     */
    public function resetPassword(Request $request, $id)
    {
        $user = User::findOrFail($id);

        $validated = $request->validate([
            'password' => ['required', new FlexiblePassword(), 'confirmed'],
        ]);

        $user->update([
            'password' => Hash::make($validated['password']),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Password berhasil direset.',
        ]);
    }
}
