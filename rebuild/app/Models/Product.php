<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Image\Enums\Fit;

class Product extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $fillable = [
        'category_id', 'name', 'publisher', 'slug', 'description', 'fulfillment_mode',
        'initials', 'accent_color', 'instant', 'package_tabs_enabled', 'package_tabs',
        'customer_no_template', 'manual_instructions', 'manual_open_time', 'manual_close_time', 'manual_timezone', 'margin_percent', 'price_markup_type', 'price_markup_fixed_idr', 'sell_markup_percent', 'cost_uplift_percent', 'sort_order', 'is_active', 'popular',
        'nickname_check_enabled', 'nickname_game_code', 'nickname_user_field_key',
        'nickname_server_field_key',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'popular' => 'boolean',
            'instant' => 'boolean',
            'package_tabs_enabled' => 'boolean',
            'package_tabs' => 'array',
            'nickname_check_enabled' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (Product $product): void {
            // Some historical migrations create products before the group table exists.
            if (Schema::hasTable('product_package_groups')) {
                $product->packageGroups()->create(['name' => 'Umum', 'sort_order' => 0]);
            }
        });
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function packages(): HasMany
    {
        return $this->hasMany(ProductPackage::class);
    }

    public function packageGroups(): HasMany
    {
        return $this->hasMany(ProductPackageGroup::class)->orderBy('sort_order')->orderBy('id');
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
    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('card')
            ->performOnCollections('image')
            ->fit(Fit::Max, 288, 432)
            ->format('webp')
            ->quality(55)
            ->nonQueued();

        $this->addMediaConversion('detail')
            ->performOnCollections('image')
            ->fit(Fit::Max, 720, 960)
            ->format('webp')
            ->quality(62)
            ->nonQueued();

        $this->addMediaConversion('banner-small')
            ->performOnCollections('banner')
            ->fit(Fit::Max, 480, 360)
            ->format('webp')
            ->quality(50)
            ->nonQueued();

        $this->addMediaConversion('banner-display')
            ->performOnCollections('banner')
            ->fit(Fit::Max, 1440, 900)
            ->format('webp')
            ->quality(48)
            ->nonQueued();
    }

}
