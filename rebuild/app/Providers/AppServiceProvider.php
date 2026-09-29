<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use LogicException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        if ($this->app->environment('production')) {
            if (config('app.debug')) {
                throw new LogicException('APP_DEBUG must be false in production.');
            }
            if (! config('session.encrypt')) {
                throw new LogicException('SESSION_ENCRYPT must be true in production.');
            }
            if (config('session.secure') !== true) {
                throw new LogicException('SESSION_SECURE_COOKIE must be true in production.');
            }
            if ((array) config('lfamilia.trusted_hosts', []) === []) {
                throw new LogicException('APP_TRUSTED_HOSTS must be configured in production.');
            }
            if (! str_starts_with((string) config('app.url'), 'https://')) {
                throw new LogicException('APP_URL must use HTTPS in production.');
            }
        }

        RateLimiter::for('checkout-nickname', fn (Request $request) => Limit::perMinute(30)
            ->by('checkout-nickname:'.$request->ip()));
        RateLimiter::for('checkout-quote', function (Request $request): array {
            $limits = [
                Limit::perMinute(30)->by('checkout-quote:'.$request->ip()),
            ];
            $voucher = strtoupper(trim((string) $request->input('voucher_code', '')));
            if ($voucher !== '') {
                $limits[] = Limit::perMinute(10)
                    ->by('voucher-validation:'.$request->ip().':'.hash('sha256', $voucher));
            }

            return $limits;
        });
        RateLimiter::for('checkout-create', fn (Request $request) => Limit::perMinute(20)
            ->by('checkout-create:'.($request->user()?->id ?? $request->ip()));
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
        RateLimiter::for('api-account', fn (Request $request) => Limit::perMinute(60)
            ->by('api-account:'.($request->user()?->id ?? $request->ip())));
        RateLimiter::for('admin-sensitive', fn (Request $request) => Limit::perMinute(20)
            ->by('admin-sensitive:'.($request->user('admin')?->id ?? $request->ip())));
        RateLimiter::for('secret-reveal', fn (Request $request) => Limit::perMinute(5)
            ->by('secret-reveal:'.($request->user('admin')?->id ?? $request->ip())));
        RateLimiter::for('account-sensitive', fn (Request $request) => Limit::perMinute(10)
            ->by('account-sensitive:'.($request->user()?->id ?? $request->ip())));
    }
}
