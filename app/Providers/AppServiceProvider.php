<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Daftarkan Services
        $this->app->singleton(\App\Services\JournalService::class);
        $this->app->singleton(\App\Services\InvoiceService::class);
        $this->app->singleton(\App\Services\DownPaymentService::class);
        $this->app->singleton(\App\Services\InvoicePaymentService::class);
    }

    public function boot(): void
    {
        // Rate limiter untuk endpoint login: maks 5 percobaan per menit per IP
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        // Rate limiter untuk API umum: maks 120 request per menit per user (atau IP jika belum login)
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(120)->by($request->user()?->id ?: $request->ip());
        });
    }
}
