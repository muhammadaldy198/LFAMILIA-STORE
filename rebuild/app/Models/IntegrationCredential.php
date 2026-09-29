<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IntegrationCredential extends Model
{
    protected $fillable = ['code', 'config_ciphertext', 'is_active'];

    protected $hidden = ['config_ciphertext'];

    protected function casts(): array
    {
        return ['config_ciphertext' => 'encrypted:array', 'is_active' => 'boolean'];
    }
}
