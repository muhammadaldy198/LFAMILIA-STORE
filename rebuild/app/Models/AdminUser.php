<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

class AdminUser extends Authenticatable
{
    protected $fillable = ['name', 'email', 'password', 'role', 'permissions', 'is_active'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['permissions' => 'array', 'is_active' => 'boolean', 'last_login_at' => 'datetime'];
    }
}
