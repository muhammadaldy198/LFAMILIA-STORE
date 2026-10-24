<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Kirim ulang tautan verifikasi untuk akun yang belum terverifikasi.
 * Harus bisa dipakai SEBELUM login: pemilik tautan kedaluwarsa tidak punya
 * jalan lain untuk memulihkan akun (login ditolak sampai email diverifikasi).
 */
class PublicEmailVerificationResendController
{
    public function __invoke(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);

        $user = User::where('email', Str::lower((string) $data['email']))->first();

        // Jawaban selalu sama supaya tidak membocorkan email mana yang terdaftar.
        if ($user && ! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }

        return back()->with('status', 'Jika email terdaftar dan belum terverifikasi, tautan baru sudah dikirim.');
    }
}
