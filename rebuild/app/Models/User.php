<?php

namespace App\Models;

use Illuminate\Auth\MustVerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmailContract
{
    use HasApiTokens, MustVerifyEmail, Notifiable;

    protected $fillable = [
        'name', 'email', 'email_verified_at', 'phone', 'password', 'google_sub', 'membership_tier_code',
    ];

    protected $hidden = ['password', 'remember_token', 'google_sub'];

    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime', 'last_active_at' => 'datetime'];
    }
}
