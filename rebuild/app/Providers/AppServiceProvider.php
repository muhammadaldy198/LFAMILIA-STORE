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
        RateLimiter::for('checkout-create', fn (Request $request) => Limit::perMinute(60)
            ->by('checkout-create:'.$request->ip()));
        RateLimiter::for('guest-order', fn (Request $request) => Limit::perMinute(10)
            ->by('guest-order:'.$request->ip()));
        RateLimiter::for('google-oauth', fn (Request $request) => Limit::perMinute(10)
            ->by('google-oauth:'.$request->ip()));
        RateLimiter::for('support-ticket', fn (Request $request) => Limit::perMinute(5)
            ->by('support-ticket:'.($request->user()?->id ?? $request->ip())));
        RateLimiter::for('payment-create', fn (Request $request) => Limit::perMinute(12)
            ->by('payment-create:'.($request->user()?->id ?? $request->ip())));
        RateLimiter::for('wallet-topup', fn (Request $request) => Limit::perMinute(10)
            ->by('wallet-topup:'.($request->user()?->id ?? $request->ip())));
        RateLimiter::for('payment-webhook', fn (Request $request) => Limit::perMinute(120)
            ->by('payment-webhook:'.$request->ip()));
        RateLimiter::for('fulfillment-webhook', fn (Request $request) => Limit::perMinute(180)
            ->by('fulfillment-webhook:'.$request->ip()));
        RateLimiter::for('admin-sensitive', fn (Request $request) => Limit::perMinute(30)
            ->by('admin-sensitive:'.($request->user('admin')?->id ?? $request->ip())));
        RateLimiter::for('secret-reveal', fn (Request $request) => Limit::perMinute(6)
            ->by('secret-reveal:'.($request->user('admin')?->id ?? $request->ip())));
    }
}
