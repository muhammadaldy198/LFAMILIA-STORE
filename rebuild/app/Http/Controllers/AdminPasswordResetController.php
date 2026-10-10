<?php

namespace App\Http\Controllers;

use App\Models\AdminUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AdminPasswordResetController
{
    public function showRequest(): Response
    {
        return Inertia::render('Admin/ForgotPassword');
    }

    public function requestLink(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);
        $email = Str::lower(trim($data['email']));

        // Never disclose whether a privileged account exists or is active.
        Password::broker('admins')->sendResetLink(['email' => $email, 'is_active' => true]);

        return back()->with('status', 'Jika email terdaftar sebagai admin aktif, tautan pemulihan akan dikirim.');
    }

    public function showReset(Request $request, string $token): Response
    {
        return Inertia::render('Admin/ResetPassword', [
            'email' => $request->query('email', ''),
            'token' => $token,
        ]);
    }

    public function reset(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ]);

        $result = Password::broker('admins')->reset([
            ...$data,
            'email' => Str::lower(trim($data['email'])),
            'is_active' => true,
        ], function (AdminUser $admin, string $password): void {
            $admin->forceFill([
                'password' => Hash::make($password),
                'remember_token' => Str::random(60),
            ])->save();
        });

        if ($result !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'token' => 'Tautan reset tidak valid atau sudah kedaluwarsa. Minta tautan baru.',
            ]);
        }

        return redirect()->route('admin.login')
            ->with('status', 'Kata sandi admin berhasil diubah. Silakan masuk.');
    }
}
