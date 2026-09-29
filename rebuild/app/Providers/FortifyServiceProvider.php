<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Models\User;
use App\Services\LoginRiskService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        Fortify::loginView(fn () => Inertia::render('Auth/Login'));
        Fortify::registerView(fn () => Inertia::render('Auth/Register'));
        Fortify::requestPasswordResetLinkView(fn () => Inertia::render('Auth/ForgotPassword'));
        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('Auth/ResetPassword', [
            'email' => $request->email,
            'token' => $request->route('token'),
        ]));
        Fortify::verifyEmailView(fn () => Inertia::render('Auth/VerifyEmail'));

        Fortify::authenticateUsing(function (Request $request): ?User {
            $risk = app(LoginRiskService::class);
            $user = User::where('email', Str::lower((string) $request->input('email')))->first();
            $valid = $user && is_string($user->password)
                && Hash::check((string) $request->input('password'), $user->password);

            $identity = Str::lower(trim((string) $request->input('email')));
            if (! $valid) {
                $failures = $risk->recordFailure('customer', $request->ip(), $identity);
                if ($failures >= 3) {
                    $request->session()->put('security.customer_login_challenge', true);
                }

                return null;
            }

            $risk->clear('customer', $request->ip(), $identity);
            $request->session()->forget('security.customer_login_challenge');

            return $user;
        });

        RateLimiter::for('customer-login', fn (Request $request) => Limit::perMinute(5)
            ->by(Str::lower((string) $request->input('email')).'|'.$request->ip()));
        RateLimiter::for('admin-login', fn (Request $request) => Limit::perMinute(5)
            ->by(Str::lower((string) $request->input('email')).'|'.$request->ip()));
    }
}
