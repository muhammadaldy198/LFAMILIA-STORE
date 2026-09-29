<?php

namespace App\Http\Middleware;

use App\Services\TurnstileService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class VerifySelectiveTurnstile
{
    public function __construct(private readonly TurnstileService $turnstile) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('POST') || ! $this->requiresChallenge($request)) {
            return $next($request);
        }

        try {
            $valid = $this->turnstile->verify($request, $request->input('turnstile_token'));
        } catch (RuntimeException) {
            return back()->withErrors([
                'turnstile_token' => 'Verifikasi keamanan sedang tidak tersedia. Coba lagi.',
            ])->withInput($request->except(['password', 'password_confirmation', 'turnstile_token']));
        }

        if (! $valid) {
            return back()->withErrors([
                'turnstile_token' => 'Selesaikan verifikasi keamanan terlebih dahulu.',
            ])->withInput($request->except(['password', 'password_confirmation', 'turnstile_token']));
        }

        return $next($request);
    }

    private function requiresChallenge(Request $request): bool
    {
        if ($request->is('register') || $request->is('forgot-password')) {
            return true;
        }

        if (! $request->is('login')) {
            return false;
        }

        $key = strtolower((string) $request->input('email')).'|'.$request->ip();

        return RateLimiter::attempts($key) >= 3;
    }
}
