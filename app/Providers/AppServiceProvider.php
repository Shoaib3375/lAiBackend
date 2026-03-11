<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        $this->configureRateLimiting();
    }
    protected function configureRateLimiting(): void
    {
        // Default API: 120 req/min per user
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(120)->by($request->user()?->id ?? $request->ip());
        });

        // Re-review endpoint: 10/min (prevents abuse)
        RateLimiter::for('re-review', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()?->id);
        });
    }
}
