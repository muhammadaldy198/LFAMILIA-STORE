<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AdminPasswordConfirmController
{
    // Session key terpisah dari web guard (P2: jangan pakai auth.password_confirmed_at)
    public const SESSION_KEY = 'admin.password_confirmed_at';

    public function show(Request $request): Response|RedirectResponse
    {
        if ($this->confirmed($request)) {
            return redirect()->to($this->safeIntended($request, route('admin.panel')));
        }

        return Inertia::render('Admin/PasswordConfirm');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'string'],
        ]);

        $admin = Auth::guard('admin')->user();

        if (! $admin || ! Hash::check($request->input('password'), $admin->password)) {
            throw ValidationException::withMessages([
                'password' => 'Kata sandi tidak cocok.',
            ]);
        }

        $request->session()->put(self::SESSION_KEY, time());

        // P1: kembali ke halaman GET yang aman, bukan replay POST
        // P2: hanya izinkan URL internal
        $target = $this->safeIntended($request, route('admin.panel'));

        return redirect()->to($target);
    }

    private function confirmed(Request $request): bool
    {
        $timeout = (int) config('auth.password_timeout', 10800);

        $confirmedAt = (int) $request->session()->get(self::SESSION_KEY, 0);

        return $confirmedAt !== 0 && (time() - $confirmedAt) < $timeout;
    }

    /**
     * P2: Hanya izinkan redirect ke URL internal.
     * Tolak absolute URL ke domain lain (open redirect).
     */
    private function safeIntended(Request $request, string $default): string
    {
        $intended = $request->session()->pull('url.intended', $default);

        // Hanya izinkan path relatif atau URL dengan host yang sama
        if (! is_string($intended) || $intended === '') {
            return $default;
        }

        // Tolak absolute URL (http://, https://, //)
        if (preg_match('#^(https?:)?//#i', $intended)) {
            return $default;
        }

        // Pastikan diawali / (path internal)
        if (! str_starts_with($intended, '/')) {
            return $default;
        }

        return $intended;
    }
}
