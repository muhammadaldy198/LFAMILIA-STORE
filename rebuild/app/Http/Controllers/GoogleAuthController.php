<?php

namespace App\Http\Controllers;

use App\Models\IntegrationCredential;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    public function reauthenticate(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user?->google_sub) {
            return redirect()->route('account.profile')
                ->withErrors(['google' => 'Akun ini belum terhubung ke Google.']);
        }

        if (! $this->configure()) {
            return redirect()->route('account.profile')
                ->withErrors(['google' => 'Login Google belum tersedia. Coba lagi nanti.']);
        }

        $request->session()->forget([
            'auth.google_reauth_at',
            'auth.google_reauth_user_id',
            'auth.google_reauth_pending_at',
            'auth.google_reauth_pending_user_id',
        ]);
        $request->session()->put([
            'auth.google_reauth_pending_at' => time(),
            'auth.google_reauth_pending_user_id' => (string) $user->getAuthIdentifier(),
        ]);

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

        $session = request()->session();
        $authenticated = Auth::guard('web')->user();
        $pendingUserId = $session->pull('auth.google_reauth_pending_user_id');
        $pendingAt = $session->pull('auth.google_reauth_pending_at');

        if ($authenticated) {
            // Never change the authenticated account while handling an OAuth callback.
            // A reauthentication proof must belong to this same Google identity.
            if ((string) $pendingUserId !== (string) $authenticated->getAuthIdentifier()
                || ! is_numeric($pendingAt)
                || (int) $pendingAt > time()
                || time() - (int) $pendingAt > 600
                || ! is_string($authenticated->google_sub)
                || ! hash_equals($authenticated->google_sub, $subject)) {
                $session->forget(['auth.google_reauth_at', 'auth.google_reauth_user_id']);

                return redirect()->route('account.profile')
                    ->withErrors(['google' => 'Autentikasi ulang Google tidak cocok atau sudah kedaluwarsa. Coba lagi.']);
            }

            $session->put([
                'auth.google_reauth_at' => time(),
                'auth.google_reauth_user_id' => (string) $authenticated->getAuthIdentifier(),
            ]);

            return redirect()->route('account.profile');
        }

        $session->forget(['auth.google_reauth_at', 'auth.google_reauth_user_id']);

        $user = DB::transaction(function () use ($profile, $email, $subject): User {
            $user = User::where('google_sub', $subject)->lockForUpdate()->first();

            if (! $user) {
                $user = User::where('email', $email)->lockForUpdate()->first();
            }

            abort_if($user && $user->google_sub && $user->google_sub !== $subject,
                409, 'Akun sudah terhubung.');

            if (! $user) {
                $user = User::create([
                    'name' => (string) ($profile->getName() ?: $email),
                    'email' => $email,
                ]);
                $user->forceFill([
                    'email_verified_at' => now(),
                    'google_sub' => $subject,
                    'membership_tier_code' => 'BASIC',
                ])->saveQuietly();

                return $user;
            }

            // An unverified local preregistration does not prove ownership of
            // its password or phone: never carry those credentials into Google.
            $adoptingUnverified = ! $user->google_sub && $user->email_verified_at === null;
            $attributes = [
                'google_sub' => $subject,
                'email_verified_at' => $user->email_verified_at ?? now(),
            ];
            if ($adoptingUnverified) {
                $attributes['password'] = null;
                $attributes['phone'] = null;
                $attributes['phone_normalized'] = null;
            }
            $user->forceFill($attributes)->save();

            return $user;
        });

        Auth::guard('web')->login($user);
        request()->session()->regenerate();
        // Only the phone-completion route accepts this short-lived Google proof.
        request()->session()->put([
            'auth.google_phone_onboarding_at' => time(),
            'auth.google_phone_onboarding_user_id' => (string) $user->getAuthIdentifier(),
        ]);

        return redirect()->route($user->phone ? 'account' : 'account.phone.edit');
    }
}
