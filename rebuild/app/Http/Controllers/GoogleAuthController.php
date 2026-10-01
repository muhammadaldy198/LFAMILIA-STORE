<?php

namespace App\Http\Controllers;

use App\Models\IntegrationCredential;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class GoogleAuthController
{
    private function configure(): bool
    {
        $profile = IntegrationCredential::where('code', 'google_oauth')
            ->where('is_active', true)->first();
        $settings = $profile?->config_ciphertext;

        if (! is_array($settings) || empty($settings['client_id']) || empty($settings['client_secret'])) {
            return false;
        }

        config(['services.google' => [
            'client_id' => $settings['client_id'],
            'client_secret' => $settings['client_secret'],
            'redirect' => route('google.callback'),
        ]]);

        return true;
    }

    public function redirect(): RedirectResponse
    {
        if (! $this->configure()) {
            return redirect()->route('login')->withErrors(['google' => 'Login Google belum tersedia. Silakan masuk dengan email dan kata sandi.']);
        }

        return Socialite::driver('google')->redirect();
    }

    public function callback(): RedirectResponse
    {
        if (! $this->configure()) {
            return redirect()->route('login')->withErrors(['google' => 'Login Google belum tersedia. Silakan masuk dengan email dan kata sandi.']);
        }

        try {
            $profile = Socialite::driver('google')->user();
        } catch (Throwable $exception) {
            Log::warning('Google OAuth callback failed.', [
                'exception_class' => $exception::class,
            ]);

            return redirect()->route('login')->withErrors(['google' => 'Login Google gagal atau dibatalkan. Silakan coba lagi.']);
        }

        $raw = $profile->user;
        $verified = filter_var($raw['email_verified'] ?? $raw['verified_email'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $email = Str::lower(trim((string) $profile->getEmail()));
        $subject = (string) $profile->getId();

        abort_unless($verified && filter_var($email, FILTER_VALIDATE_EMAIL)
            && $subject !== '', 403, 'Akun Google tidak valid.');

        $user = DB::transaction(function () use ($profile, $email, $subject): User {
            $user = User::where('google_sub', $subject)->lockForUpdate()->first();

            if (! $user) {
                $user = User::where('email', $email)->lockForUpdate()->first();
            }

            abort_if($user && $user->google_sub && $user->google_sub !== $subject,
                409, 'Akun sudah terhubung.');

            if (! $user) {
                return User::create([
                    'name' => (string) ($profile->getName() ?: $email),
                    'email' => $email,
                    'email_verified_at' => now(),
                    'google_sub' => $subject,
                    'membership_tier_code' => 'BASIC',
                ]);
            }

            $user->forceFill([
                'google_sub' => $subject,
                'email_verified_at' => $user->email_verified_at ?? now(),
            ])->save();

            return $user;
        });

        Auth::guard('web')->login($user);
        request()->session()->regenerate();

        return redirect()->route($user->phone ? 'account' : 'account.phone.edit');
    }
}
