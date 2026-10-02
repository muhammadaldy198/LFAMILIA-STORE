<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class NicknameGameCode extends Model
{
    protected $fillable = [
        'name',
        'code',
        'supports_nickname_check',
        'requires_server',
        'requires_region_check',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'supports_nickname_check' => 'boolean',
            'requires_server' => 'boolean',
            'requires_region_check' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
