<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

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
       RateLimiter::for('reward-registration', function (Request $request) {
            return Limit::perMinute(20)
                ->by('reward-registration:'.$request->ip());
        });

        RateLimiter::for('reward-api', function (Request $request) {
            $tokenHash = hash(
                'sha256',
                (string) $request->bearerToken()
            );

            return Limit::perMinute(60)
                ->by('reward-api:'.$tokenHash);
        });
    }
}
