<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class CacheUserData
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Jika request untuk mendapatkan user data dan user sudah login
        if ($request->is('api/user') && $request->user()) {
            $userId = $request->user()->id;
            $cacheKey = 'user_data.' . $userId;

            // Cek cache terlebih dahulu
            $cachedData = Cache::get($cacheKey);
            if ($cachedData) {
                return response()->json($cachedData);
            }

            // Jika tidak ada di cache, lanjutkan request dan cache hasilnya
            $response = $next($request);

            if ($response->getStatusCode() === 200) {
                $userData = $response->getData(true);
                Cache::put($cacheKey, $userData, 1800); // 30 menit
            }

            return $response;
        }

        return $next($request);
    }
}
