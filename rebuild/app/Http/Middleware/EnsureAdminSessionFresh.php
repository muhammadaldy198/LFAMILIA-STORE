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
        if ($user && $request->session()->has($guard->getName())) {
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

        // Refresh from storage: AdminAccessController may update the same
        // admin through a different model instance during this request.
        $user = $guard->user();
        $fresh = $user?->fresh();
        if ($fresh && $request->session()->has($guard->getName())) {
            $request->session()->put(
                self::SESSION_KEY,
                $this->fingerprint($fresh->getAuthIdentifier(), $fresh->getAuthPassword())
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
