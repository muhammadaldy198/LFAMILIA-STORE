<?php

namespace App\Http\Middleware;

use App\Services\TurnstileService;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware untuk membatasi penyalahgunaan rute publik sensitif (register, login, password recovery).
 * Menggabungkan pembatasan frekuensi per email, bucket kuota per alamat IP untuk mencegah email spraying,
 * serta verifikasi Cloudflare Turnstile sebelum mencatat konsumsi kuota IP.
 * Bukan untuk mengurus otorisasi sesi atau verifikasi kredensial pengguna.
 */
class PublicAbuseProtection
{
    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly TurnstileService $turnstile,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('POST')) {
            return $next($request);
        }

        $path = trim($request->path(), '/');

        if ($path === 'register') {
            $this->enforce($request, 'register', 5, 600, 'register');
        } elseif ($path === 'forgot-password') {
            $this->enforce($request, 'forgot-password', 5, 600, 'forgot_password');
        } elseif ($path === 'admin/forgot-password') {
            $this->enforce($request, 'admin-forgot-password', 3, 600, 'admin_forgot_password');
        } elseif ($path === 'admin/reset-password') {
            $this->enforce($request, 'admin-reset-password', 5, 600);
        } elseif ($path === 'reset-password') {
            $this->enforce($request, 'reset-password', 5, 600);
        } elseif ($path === 'login') {
            $this->turnstile->verify($request, 'customer_login');
        } elseif ($path === 'admin/login') {
            $this->turnstile->verify($request, 'admin_login');
        }

        return $next($request);
    }

    private function enforce(Request $request, string $scope, int $maxAttempts, int $decaySeconds, ?string $turnstileAction = null): void
    {
        $ipKey = 'public-abuse:'.$scope.':ip:'.$request->ip();

        if ($this->limiter->tooManyAttempts($ipKey, $maxAttempts * 4)) {
            throw ValidationException::withMessages([
                'email' => 'Terlalu banyak percobaan. Coba lagi beberapa saat lagi.',
            ]);
        }

        $email = strtolower(trim((string) $request->input('email', '')));
        $emailKey = 'public-abuse:'.$scope.':'.hash('sha256', $request->ip().'|'.$email);

        if ($this->limiter->tooManyAttempts($emailKey, $maxAttempts)) {
            throw ValidationException::withMessages([
                'email' => 'Terlalu banyak percobaan. Coba lagi beberapa saat lagi.',
            ]);
        }

        $this->limiter->hit($emailKey, $decaySeconds);

        if ($turnstileAction !== null) {
            $this->turnstile->verify($request, $turnstileAction);
        }

        if ($this->limiter->hit($ipKey, $decaySeconds) > $maxAttempts * 4) {
            throw ValidationException::withMessages([
                'email' => 'Terlalu banyak percobaan. Coba lagi beberapa saat lagi.',
            ]);
        }
    }
}
