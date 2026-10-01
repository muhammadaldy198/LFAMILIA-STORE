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
        'category_id', 'name', 'publisher', 'slug', 'description', 'fulfillment_mode',
        'manual_instructions', 'manual_open_time', 'manual_close_time', 'manual_timezone', 'margin_percent', 'sort_order', 'is_active', 'popular',
        'nickname_check_enabled', 'nickname_game_code', 'nickname_user_field_key',
        'nickname_server_field_key',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'popular' => 'boolean',
            'nickname_check_enabled' => 'boolean',
        ];
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

    public function notices(): HasMany
    {
        return $this->hasMany(ProductNotice::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(ProductReview::class);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('image')->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif'])->singleFile();
        $this->addMediaCollection('banner')->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp'])->singleFile();
    }
}
