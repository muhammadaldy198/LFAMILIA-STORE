<?php

namespace App\Http\Controllers;

use App\Services\AdminNotificationService;
use App\Services\LoginRiskService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AdminAuthController
{
    public function show(): Response
    {
        return Inertia::render('Admin/Login');
    }

    public function login(
        Request $request,
        LoginRiskService $risk,
        AdminNotificationService $notifications,
    ): RedirectResponse {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $identity = strtolower(trim((string) $credentials['email']));
        if (! Auth::guard('admin')->attempt([
            ...$credentials,
            'is_active' => true,
        ])) {
            $failures = $risk->recordFailure('admin', $request->ip(), $identity);
            if ($failures >= 3) {
                $request->session()->put('security.admin_login_challenge', true);
            }
            if ($failures === 3) {
                $notifications->record(
                    'security.admin_login_suspicious',
                    'Login Admin mencurigakan',
                    'Turnstile diaktifkan untuk percobaan login berikutnya dari sumber ini.',
                    'WARNING',
                    'admin_login',
                    hash('sha256', (string) $request->ip())
                );
            }

            throw ValidationException::withMessages(['email' => 'Email atau kata sandi tidak cocok.']);
        }

        $admin = Auth::guard('admin')->user();

        if (! in_array($admin->role, ['SUPER_ADMIN', 'ADMIN', 'STAFF'], true)) {
            Auth::guard('admin')->logout();

            throw ValidationException::withMessages(['email' => 'Akses ditolak.']);
        }

        $risk->clear('admin', $request->ip(), $identity);
        $request->session()->forget('security.admin_login_challenge');
        $request->session()->regenerate();
        $admin->forceFill(['last_login_at' => now()])->save();

        $landing = $admin->role === 'STAFF' ? route('staff.panel') : route('admin.panel');

        return redirect()->intended($landing);
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('admin')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }
}
