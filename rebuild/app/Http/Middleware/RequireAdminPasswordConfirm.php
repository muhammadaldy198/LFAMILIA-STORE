<?php

namespace App\Http\Middleware;

use App\Http\Controllers\AdminPasswordConfirmController;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireAdminPasswordConfirm
{
    public function handle(Request $request, Closure $next): Response
    {
        $timeout = (int) config('auth.password_timeout', 10800);
        $confirmedAt = (int) $request->session()->get(AdminPasswordConfirmController::SESSION_KEY, 0);

        if ($confirmedAt === 0 || (time() - $confirmedAt) >= $timeout) {
            // P1: Jangan simpan URL POST sebagai intended (akan 405 saat di-GET).
            // Simpan halaman GET yang aman sebagai fallback.
            $fallback = $request->headers->get('referer');
            if (! $fallback || ! str_starts_with($fallback, config('app.url'))) {
                $fallback = route('admin.panel');
            }
            // Ambil path-nya saja untuk keamanan
            $path = parse_url($fallback, PHP_URL_PATH) ?: '/admin';
            $request->session()->put('url.intended', $path);

            return redirect()->route('admin.password.confirm');
        }

        return $next($request);
    }
}
