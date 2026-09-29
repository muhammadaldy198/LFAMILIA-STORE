<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        RateLimiter::for('checkout-nickname', fn (Request $request) => Limit::perMinute(30)
            ->by('checkout-nickname:'.$request->ip()));
        RateLimiter::for('checkout-quote', fn (Request $request) => Limit::perMinute(30)
            ->by('checkout-quote:'.$request->ip()));
        RateLimiter::for('checkout-create', fn (Request $request) => Limit::perMinute(20)
            ->by('checkout-create:'.$request->ip()));
        RateLimiter::for('guest-order', fn (Request $request) => Limit::perMinute(10)
            ->by('guest-order:'.$request->ip()));
        RateLimiter::for('google-oauth', fn (Request $request) => Limit::perMinute(10)
            ->by('google-oauth:'.$request->ip()));
        RateLimiter::for('support-ticket', fn (Request $request) => Limit::perMinute(5)
            ->by('support-ticket:'.($request->user()?->id ?? $request->ip()));
    }
}
