<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class ProductPackage extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $fillable = ['product_id', 'code', 'name', 'note', 'group_name', 'nominal_value', 'sort_order', 'is_active', 'pricing_mode', 'margin_percent', 'margin_fixed_idr', 'sell_price_idr'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function mappings(): HasMany
    {
        return $this->hasMany(ProviderMapping::class);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('image')->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif'])->singleFile();
    }
}
