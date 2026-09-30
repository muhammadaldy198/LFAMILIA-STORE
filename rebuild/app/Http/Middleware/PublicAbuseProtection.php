<?php

namespace App\Http\Middleware;

use App\Services\LoginRiskService;
use App\Services\TurnstileService;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class PublicAbuseProtection
{
    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly TurnstileService $turnstile,
        private readonly LoginRiskService $loginRisk,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('POST')) {
            return $next($request);
        }

        $path = trim($request->path(), '/');

        if ($path === 'register') {
            $this->limit($request, 'register', 5, 600);
            $this->turnstile->verify($request, 'register');
        } elseif ($path === 'forgot-password') {
            $this->limit($request, 'forgot-password', 5, 600);
            $this->turnstile->verify($request, 'forgot_password');
        } elseif ($path === 'reset-password') {
            $this->limit($request, 'reset-password', 5, 600);
        } elseif ($path === 'orders/check') {
            $this->turnstile->verify($request, 'order_lookup');
        } elseif ($path === 'checkout/orders' && ! $request->user()) {
            $this->turnstile->verify($request, 'guest_checkout');
        } elseif ($path === 'login' && $this->loginRisk->requiresChallenge(
            'customer',
            $request->ip(),
            (string) $request->input('email')
        )) {
            $this->turnstile->verify($request, 'customer_login');
        } elseif (in_array($path, ['admin/login', 'staff/login'], true) && $this->loginRisk->requiresChallenge(
            'admin',
            $request->ip(),
            (string) $request->input('email')
        )) {
            $this->turnstile->verify($request, 'admin_login');
        }

        return $next($request);
    }

    private function limit(Request $request, string $scope, int $maxAttempts, int $decaySeconds): void
    {
        $email = strtolower(trim((string) $request->input('email', '')));
        $key = 'public-abuse:'.$scope.':'.hash('sha256', $request->ip().'|'.$email);

        if ($this->limiter->tooManyAttempts($key, $maxAttempts)) {
            throw ValidationException::withMessages([
                'email' => 'Terlalu banyak percobaan. Coba lagi beberapa saat lagi.',
            ]);
        }

        $this->limiter->hit($key, $decaySeconds);
    }
}
