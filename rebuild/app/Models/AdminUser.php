<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

class AdminUser extends Authenticatable
{
    protected $fillable = ['name', 'email', 'password'];

    protected $attributes = [
        'role' => 'ADMIN',
        'is_active' => true,
    ];

    protected $hidden = ['password', 'remember_token'];

    protected static function booted(): void
    {
        static::creating(function (self $admin): void {
            foreach (['role', 'permissions', 'is_active'] as $attribute) {
                if (array_key_exists($attribute, $admin->getRawOriginal())) {
                    $admin->setAttribute($attribute, $admin->getRawOriginal($attribute));
                }
            }
        });
    }

    protected function casts(): array
    {
        return ['permissions' => 'array', 'is_active' => 'boolean', 'last_login_at' => 'datetime'];
    }
}
