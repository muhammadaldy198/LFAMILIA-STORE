<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProviderMapping extends Model
{
    protected $fillable = [
        'product_package_id', 'provider_id', 'external_sku', 'cost_idr',
        'max_price_idr', 'fulfillment_config', 'priority', 'is_active',
    ];

    protected function casts(): array
    {
        return ['fulfillment_config' => 'array', 'is_active' => 'boolean'];
    }
}
