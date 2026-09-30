<?php

namespace App\Models;

use App\Jobs\SendTransactionalEmailJob;
use Illuminate\Auth\MustVerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmailContract
{
    use HasApiTokens, MustVerifyEmail, Notifiable, SoftDeletes;

    protected $fillable = [
        'name', 'email', 'email_verified_at', 'phone', 'password', 'google_sub', 'membership_tier_code', 'membership_mode', 'membership_override_code', 'membership_progress_bonus_idr', 'leaderboard_opt_in',
    ];

    protected $hidden = ['password', 'remember_token', 'google_sub'];

    protected static function booted(): void
    {
        static::created(function (self $user): void {
            $user->wallet()->create();
        });
    }

    public function sendEmailVerificationNotification(): void
    {
        if (! is_string($this->email) || $this->email === '') {
            return;
        }

        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $this->getKey(), 'hash' => sha1($this->getEmailForVerification())]
        );
        SendTransactionalEmailJob::dispatch(
            $this->email,
            'Verifikasi email LFAMILIA STORE',
            "Verifikasi email akun LFAMILIA STORE melalui tautan berikut:\n".$url
        )->afterCommit();
    }

    public function sendPasswordResetNotification($token): void
    {
        if (! is_string($this->email) || $this->email === '') {
            return;
        }

        $url = route('password.reset', [
            'token' => $token,
            'email' => $this->getEmailForPasswordReset(),
        ]);
        SendTransactionalEmailJob::dispatch(
            $this->email,
            'Reset password LFAMILIA STORE',
            "Reset password akun LFAMILIA STORE melalui tautan berikut:\n".$url
        )->afterCommit();
    }

    public function wallet()
    {
        return $this->hasOne(Wallet::class);
    }

    public function savedGameAccounts()
    {
        return $this->hasMany(SavedGameAccount::class);
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_active_at' => 'datetime',
            'leaderboard_opt_in' => 'boolean',
            'membership_progress_bonus_idr' => 'integer',
        ];
    }
}
