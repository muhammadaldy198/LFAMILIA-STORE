<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireAdminPasswordConfirm
{
    public function handle(Request $request, Closure $next): Response
    {
        $timeout = (int) config('auth.password_timeout', 10800);
        $confirmedAt = (int) $request->session()->get('auth.password_confirmed_at', 0);

        if ($confirmedAt === 0 || (time() - $confirmedAt) >= $timeout) {
            // Simpan URL tujuan agar bisa redirect kembali setelah konfirmasi
            $request->session()->put('url.intended', $request->fullUrl());

            return redirect()->route('admin.password.confirm');
        }

        return $next($request);
    }
}
