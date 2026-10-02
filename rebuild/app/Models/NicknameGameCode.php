<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class NicknameGameCode extends Model
{
    protected $fillable = [
        'name',
        'code',
        'requires_server',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'requires_server' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
