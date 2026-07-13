<?php

namespace App\Providers;

use App\Models\Settings;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Let the Super Admin control how long a Sanctum access token stays
        // valid straight from the Settings panel (Security & Access tab).
        // Guarded by Schema::hasTable so a fresh install (before the
        // `settings` migration has run) doesn't 500 on every request.
        try {
            if (Schema::hasTable('settings')) {
                Config::set('sanctum.expiration', Settings::current()->session_lifetime_minutes);
            }
        } catch (\Throwable $e) {
            // Database not reachable yet (e.g. during `artisan migrate` itself) — ignore.
        }
    }
}
