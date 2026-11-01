<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Image\Enums\Fit;

class HomeBanner extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $fillable = [
        'title', 'subtitle', 'cta_label', 'cta_href',
        'show_desktop', 'show_mobile', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'show_desktop' => 'boolean',
            'show_mobile' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function registerMediaCollections(): void
    {
        foreach (['desktop', 'mobile'] as $collection) {
            $this->addMediaCollection($collection)
                ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif'])
                ->singleFile();
        }
    }
    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('card')
            ->performOnCollections('desktop', 'mobile')
            ->fit(Fit::Max, 480, 360)
            ->format('webp')
            ->quality(50)
            ->nonQueued();

        $this->addMediaConversion('display')
            ->performOnCollections('desktop', 'mobile')
            ->fit(Fit::Max, 1440, 900)
            ->format('webp')
            ->quality(48)
            ->nonQueued();
    }

}
