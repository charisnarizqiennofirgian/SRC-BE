<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use App\Models\User;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $key = 'login.' . $request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);
            return response()->json([
                'success' => false,
                'message' => "Too many login attempts. Please try again in {$seconds} seconds."
            ], 429);
        }

        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $cacheKey = 'user.' . md5($request->email);
        $user = Cache::remember($cacheKey, 300, function () use ($request) {
            return User::with('roles.permissions')->where('email', $request->email)->first();  // ✅ LOAD PERMISSIONS
        });

        if (!$user || !Hash::check($request->password, $user->password)) {
            RateLimiter::hit($key, 300);
            return response()->json([
                'success' => false,
                'message' => 'Invalid email or password'
            ], 401);
        }

        RateLimiter::clear($key);

        $token = $user->createToken('auth_token_' . time())->plainTextToken;

        Cache::put('user.' . $user->id, $user, 3600);

        // ✅ COLLECT PERMISSIONS
        $permissions = $this->getUserPermissions($user);

        // Tentukan dashboard route berdasarkan role
        $dashboardRoute = $this->getDashboardRouteByRole($user->roles->pluck('name')->toArray());

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->roles->pluck('name'),
            ],
            'permissions' => $permissions,  // ✅ RETURN PERMISSIONS
            'dashboard_route' => $dashboardRoute
        ], 200);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logout successful'
        ], 200);
    }

    /**
     * ✅ GET USER PERMISSIONS
     */
    private function getUserPermissions(User $user): array
    {
        // Jika super-admin, return wildcard
        if ($user->hasRole('super-admin')) {
            return ['*'];
        }

        // Collect all permissions dari semua roles
        $permissions = [];
        foreach ($user->roles as $role) {
            foreach ($role->permissions as $permission) {
                $permissions[] = $permission->name;
            }
        }

        // Return unique permissions
        return array_values(array_unique($permissions));
    }

    /**
     * Tentukan dashboard route berdasarkan role user
     */
    private function getDashboardRouteByRole(array $roles): string
    {
        // Jika user memiliki role super-admin ATAU admin, arahkan ke halaman admin terpusat
        if (in_array('super-admin', $roles) || in_array('admin', $roles)) {
            return '/admin';
        }

        if (in_array('manager', $roles)) {
            return '/manager/dashboard'; // Contoh untuk role lain
        }

        // Default untuk role lainnya
        return '/dashboard';
    }
}
