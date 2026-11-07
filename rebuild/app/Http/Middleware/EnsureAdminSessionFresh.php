<?php

namespace App\Http\Middleware;

use App\Http\Controllers\AdminPasswordConfirmController;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminSessionFresh
{
    private const SESSION_KEY = 'security.admin_auth_fingerprint';

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession() || ! $request->is('admin/*')) {
            return $next($request);
        }

        $guard = Auth::guard('admin');
        $user = $guard->user();

        // actingAs() is not a browser session. Only enforce a stored login
        // key so pre-existing test-only guards are unaffected.
        $authenticatedAtStart = $user && $request->session()->has($guard->getName());
        $initialId = $authenticatedAtStart ? (string) $user->getAuthIdentifier() : null;

        if ($authenticatedAtStart) {
            $stored = $request->session()->get(self::SESSION_KEY);
            $fresh = $user->fresh();
            $current = $fresh ? $this->fingerprint($fresh->getAuthIdentifier(), $fresh->getAuthPassword()) : null;

            if (! is_string($stored) || ! is_string($current) || ! hash_equals($current, $stored)) {
                $guard->logoutCurrentDevice();
                $request->session()->forget([
                    self::SESSION_KEY,
                    AdminPasswordConfirmController::SESSION_KEY,
                ]);
                // Do not invalidate the web guard or its customer-only state.
                $request->session()->regenerate(true);

                return redirect()->guest(route('admin.login'));
            }
        }

        $response = $next($request);

        // A normal in-flight request must NEVER adopt a password rotated by
        // another device after the check above (otherwise revocation races).
        $user = $guard->user();
        if ($user && $request->session()->has($guard->getName())) {
            if (! $authenticatedAtStart || (string) $user->getAuthIdentifier() !== $initialId) {
                // New login: stamp the credential actually used to authenticate,
                // not a newer password fetched concurrently from the database.
                $request->session()->put(
                    self::SESSION_KEY,
                    $this->fingerprint($user->getAuthIdentifier(), $user->getAuthPassword())
                );
            } else {
                // Only an explicitly verified self-change may renew a stamp.
                // Recheck the exact hash saved by this request to avoid
                // adopting yet another concurrent password change.
                $selfChangedHash = $request->attributes->get('security.admin_self_password_hash');
                if (is_string($selfChangedHash)) {
                    $fresh = $user->fresh();
                    if ($fresh && (string) $fresh->getAuthIdentifier() === $initialId
                        && hash_equals($selfChangedHash, (string) $fresh->getAuthPassword())) {
                        $request->session()->put(
                            self::SESSION_KEY,
                            $this->fingerprint($fresh->getAuthIdentifier(), $selfChangedHash)
                        );
                    }
                }
            }
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
