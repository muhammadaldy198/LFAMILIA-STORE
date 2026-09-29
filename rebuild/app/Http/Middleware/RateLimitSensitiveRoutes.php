<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class RateLimitSensitiveRoutes
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('POST')) {
            return $next($request);
        }

        [$name, $max, $seconds] = match (true) {
            $request->is('register') => ['customer-register', 5, 3600],
            $request->is('forgot-password') => ['customer-forgot-password', 5, 3600],
            $request->is('reset-password') => ['customer-reset-password', 8, 3600],
            default => [null, 0, 0],
        };

        if ($name === null) {
            return $next($request);
        }

        $identity = strtolower(trim((string) $request->input('email')));
        $key = $name.':'.hash('sha256', $identity.'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($key, $max)) {
            $retryAfter = RateLimiter::availableIn($key);

            return response('Terlalu banyak permintaan. Coba lagi nanti.', 429, [
                'Retry-After' => (string) $retryAfter,
                'Cache-Control' => 'no-store',
            ]);
        }

        RateLimiter::hit($key, $seconds);

        return $next($request);
    }
}
