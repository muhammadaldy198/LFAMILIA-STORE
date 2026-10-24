<?php

namespace App\Models;

use App\Actions\Fortify\CreateNewUser;
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
        'name', 'email', 'phone', 'phone_normalized', 'password', 'leaderboard_opt_in',
    ];

    protected $hidden = ['password', 'remember_token', 'google_sub'];

    protected static function booted(): void
    {
        static::creating(function (self $user): void {
            if ($user->phone !== null) {
                $user->phone_normalized = CreateNewUser::normalizePhone((string) $user->phone);
            }
        });

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
            'customer.verification.verify',
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
            'Atur ulang kata sandi LFAMILIA STORE',
            implode("\n", [
                'Halo,',
                '',
                'Kami menerima permintaan untuk mengatur ulang kata sandi akun LFAMILIA STORE.',
                '',
                'Atur ulang kata sandi: '.$url,
                '',
                'Jika kamu tidak meminta ini, abaikan email ini dan jangan bagikan tautan kepada siapa pun.',
                '',
                'Butuh bantuan? Hubungi CS: support@lfamiliastore.my.id',
            ])
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
