<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class PaymentChannel extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $fillable = [
        'code',
        'method',
        'name',
        'description',
        'fee_flat_idr',
        'fee_percent_bps',
        'supports_order',
        'supports_wallet_topup',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'fee_flat_idr' => 'integer',
            'fee_percent_bps' => 'integer',
            'supports_order' => 'boolean',
            'supports_wallet_topup' => 'boolean',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('logo')
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp'])
            ->singleFile();
    }
}
