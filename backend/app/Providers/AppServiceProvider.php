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
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by($request->ip().'|'.$request->input('login_id')));
        RateLimiter::for('registration', fn (Request $request) => Limit::perHour(5)->by($request->ip()));
        RateLimiter::for('device-activation', fn (Request $request) => Limit::perMinutes(15, 5)->by($request->ip()));
        RateLimiter::for('attendance-scan', fn (Request $request) => Limit::perMinute(30)->by((string) $request->user()?->id));
        RateLimiter::for('attendance-request', fn (Request $request) => Limit::perHour(20)->by((string) $request->user()?->id));
    }
}
