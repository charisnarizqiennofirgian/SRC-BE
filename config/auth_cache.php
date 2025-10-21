<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Cache Configuration for Authentication
    |--------------------------------------------------------------------------
    |
    | Konfigurasi cache khusus untuk optimasi authentication
    |
    */

    'user_cache_ttl' => env('USER_CACHE_TTL', 3600), // 1 jam default
    'login_cache_ttl' => env('LOGIN_CACHE_TTL', 300), // 5 menit default
    'rate_limit_attempts' => env('RATE_LIMIT_ATTEMPTS', 5),
    'rate_limit_decay' => env('RATE_LIMIT_DECAY', 300), // 5 menit default
];
