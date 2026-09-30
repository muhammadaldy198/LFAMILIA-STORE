<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SitePopup extends Model
{
    protected $fillable = [
        'title', 'body', 'primary_label', 'primary_href',
        'secondary_label', 'secondary_href', 'dismiss_days',
        'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'dismiss_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
