<?php

namespace App\Providers;

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
        //
    }
}
