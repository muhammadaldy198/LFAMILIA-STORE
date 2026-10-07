<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CustomerPasswordConfirmController
{
    public function show(Request $request): Response|RedirectResponse
    {
        if ($this->confirmed($request)) {
            return redirect()->intended(route('catalog.index'));
        }

        return Inertia::render('Auth/ConfirmPassword');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'string'],
        ]);

        $user = $request->user();

        if (! $user || ! Hash::check($request->input('password'), $user->password)) {
            throw ValidationException::withMessages([
                'password' => 'Kata sandi tidak cocok.',
            ]);
        }

        $request->session()->put('auth.password_confirmed_at', time());

        return redirect()->intended(route('catalog.index'));
    }

    private function confirmed(Request $request): bool
    {
        $timeout = (int) config('auth.password_timeout', 10800);

        $confirmedAt = (int) $request->session()->get('auth.password_confirmed_at', 0);

        return $confirmedAt !== 0 && (time() - $confirmedAt) < $timeout;
    }
}
