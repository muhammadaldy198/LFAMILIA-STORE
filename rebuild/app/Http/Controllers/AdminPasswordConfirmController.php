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
    public function show(Request $request): Response|RedirectResponse
    {
        // Jika sudah konfirmasi dalam timeout, langsung redirect ke tujuan
        if ($this->confirmed($request)) {
            return redirect()->intended(route('admin.panel'));
        }

        return Inertia::render('Admin/PasswordConfirm', [
            'intended' => $request->query('intended', route('admin.panel')),
        ]);
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

        $request->session()->put('auth.password_confirmed_at', time());

        return redirect()->intended($request->input('intended', route('admin.panel')));
    }

    private function confirmed(Request $request): bool
    {
        $timeout = (int) config('auth.password_timeout', 10800);

        $confirmedAt = (int) $request->session()->get('auth.password_confirmed_at', 0);

        return $confirmedAt !== 0 && (time() - $confirmedAt) < $timeout;
    }
}
