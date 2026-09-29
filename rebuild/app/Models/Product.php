<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Product extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $fillable = [
        'category_id', 'name', 'slug', 'description', 'fulfillment_mode',
        'manual_instructions', 'margin_percent', 'sort_order', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function packages(): HasMany
    {
        return $this->hasMany(ProductPackage::class);
    }

    public function fields(): HasMany
    {
        return $this->hasMany(ProductInputField::class);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('image')->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp'])->singleFile();
        $this->addMediaCollection('banner')->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp'])->singleFile();
    }
}
