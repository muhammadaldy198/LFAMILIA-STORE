<?php

namespace App\Models;

use App\Jobs\SendTransactionalEmailJob;
use Illuminate\Foundation\Auth\User as Authenticatable;

class AdminUser extends Authenticatable
{
    protected $fillable = ['name', 'email', 'password'];

    protected $attributes = [
        'role' => 'ADMIN',
        'is_active' => true,
    ];

    protected $hidden = ['password', 'remember_token'];

    public function sendPasswordResetNotification($token): void
    {
        if (! is_string($this->email) || $this->email === '') {
            return;
        }

        $url = route('admin.password.reset', [
            'token' => $token,
            'email' => $this->getEmailForPasswordReset(),
        ]);

        SendTransactionalEmailJob::dispatch(
            $this->email,
            'Reset kata sandi admin LFAMILIA',
            "Permintaan reset kata sandi admin LFAMILIA. Tautan ini berlaku selama 30 menit.\n"
                .$url."\n\nJika Anda tidak meminta reset, abaikan email ini."
        )->afterCommit();
    }

    protected function casts(): array
    {
        return ['permissions' => 'array', 'is_active' => 'boolean', 'last_login_at' => 'datetime'];
    }
}
