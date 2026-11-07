<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureStatefulCustomerSessionFresh
{
    private const SESSION_KEY = 'security.customer_auth_fingerprint';

    public function handle(Request $request, Closure $next): Response
    {
        // Sanctum's EnsureFrontendRequestsAreStateful starts the session only
        // for first-party cookie requests. Bearer-only API calls bypass this.
        if (! $request->hasSession()) {
            return $next($request);
        }

        $guard = Auth::guard('web');
        $user = $guard->user();

        if ($user && $request->session()->has($guard->getName())) {
            $stored = $request->session()->get(self::SESSION_KEY);
            $fresh = $user->fresh();
            $current = $fresh ? $this->fingerprint($fresh->getAuthIdentifier(), $fresh->getAuthPassword()) : null;

            if (! is_string($stored) || ! is_string($current) || ! hash_equals($current, $stored)) {
                $guard->logoutCurrentDevice();
                $request->session()->forget([
                    self::SESSION_KEY,
                    'auth.password_confirmed_at',
                    'auth.google_phone_onboarding_at',
                    'auth.google_phone_onboarding_user_id',
                ]);
                $request->session()->regenerate(true);

                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
        }

        $response = $next($request);

        $user = $guard->user();
        if ($user && $request->session()->has($guard->getName())) {
            $request->session()->put(
                self::SESSION_KEY,
                $this->fingerprint($user->getAuthIdentifier(), $user->getAuthPassword())
            );
        } else {
            $request->session()->forget(self::SESSION_KEY);
        }

        return $response;
    }

    private function fingerprint(int|string $userId, ?string $password): string
    {
        return hash_hmac('sha256', $userId.'|'.($password ?? ''), (string) config('app.key'));
    }
}
