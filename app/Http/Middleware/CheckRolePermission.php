<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRolePermission
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string $role): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated'
            ], 401);
        }

        // Cek apakah user memiliki role yang diperlukan
        if (!$user->hasRole($role)) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient permissions. Required role: ' . $role
            ], 403);
        }

        return $next($request);
    }
}
