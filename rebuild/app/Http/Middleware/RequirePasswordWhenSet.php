<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Allow passwordless Google customers, or customers who just authenticated
 * with Google, to complete initial phone onboarding without a local password.
 *
 * Keep Laravel's normal password confirmation for established password users
 * outside this narrowly scoped onboarding exception.
 */
class RequirePasswordWhenSet
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user?->password || $this->hasRecentGoogleOnboarding($request)) {
            return $next($request);
        }

        return app(RequirePassword::class)->handle($request, $next);
    }

    private function hasRecentGoogleOnboarding(Request $request): bool
    {
        $user = $request->user();
        if (! $user || ! $user->google_sub || $user->phone) {
            return false;
        }

        $confirmedAt = (int) $request->session()->get('auth.google_phone_onboarding_at', 0);
        $userId = (string) $request->session()->get('auth.google_phone_onboarding_user_id', '');
        $now = time();

        return $confirmedAt > 0
            && $confirmedAt <= $now
            && ($now - $confirmedAt) <= 600
            && $userId !== ''
            && hash_equals((string) $user->getAuthIdentifier(), $userId);
    }
}
