<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MembershipTier extends Model
{
    protected $fillable = [];

    protected function casts(): array
    {
        return ['requirements' => 'array', 'benefits' => 'array', 'is_active' => 'boolean'];
    }
}
