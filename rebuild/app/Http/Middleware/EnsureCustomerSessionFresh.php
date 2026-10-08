<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureCustomerSessionFresh
{
    private const SESSION_KEY = 'security.customer_auth_fingerprint';

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession()) {
            return $next($request);
        }

        $guard = Auth::guard('web');
        $user = $guard->user();

        // Real session logins have the guard's login key. This excludes test-only
        // actingAs() guards, which do not represent a browser session.
        if ($user && $request->session()->has($guard->getName())) {
            $stored = $request->session()->get(self::SESSION_KEY);
            $current = $this->fingerprint($user->getAuthIdentifier(), $user->getAuthPassword());

            // Requiring a fingerprint intentionally expires legacy web sessions
            // once on deployment; otherwise inactive sessions could evade resets.
            if (! is_string($stored) || ! hash_equals($current, $stored)) {
                $guard->logoutCurrentDevice();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->guest(route('login'));
            }
        }

        $response = $next($request);

        // After login, or an in-session password change, stamp the active session
        // with the user's current credential. Other devices keep their old stamp.
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
