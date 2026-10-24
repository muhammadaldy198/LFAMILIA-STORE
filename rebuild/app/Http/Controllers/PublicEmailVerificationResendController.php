<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        $email = Str::lower((string) $data['email']);
        $message = 'Jika email terdaftar dan belum terverifikasi, tautan baru sudah dikirim.';

        // Batas per ALAMAT, di samping batas per IP pada route. Tanpa ini satu
        // alamat bisa dibanjiri email dari banyak IP.
        $minuteKey = 'verify-resend:minute:'.sha1($email);
        $hourKey = 'verify-resend:hour:'.sha1($email);
        if (RateLimiter::tooManyAttempts($minuteKey, 1) || RateLimiter::tooManyAttempts($hourKey, 3)) {
            return back()->with('status', $message);
        }
        RateLimiter::hit($minuteKey, 60);
        RateLimiter::hit($hourKey, 3600);

        $user = User::where('email', $email)->first();

        // Jawaban selalu sama supaya tidak membocorkan email mana yang terdaftar.
        if ($user && ! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }

        return back()->with('status', $message);
    }
}
