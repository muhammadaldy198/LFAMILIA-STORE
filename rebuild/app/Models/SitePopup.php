<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Image\Enums\Fit;

class SitePopup extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $fillable = [
        'title', 'body', 'primary_label', 'primary_href',
        'secondary_label', 'secondary_href', 'dismiss_days',
        'is_active', 'sort_order',
    ];

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('image')
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif'])
            ->singleFile();
    }

    protected function casts(): array
    {
        return [
            'dismiss_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }
    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('display')
            ->performOnCollections('image')
            ->fit(Fit::Max, 900, 900)
            ->format('webp')
            ->quality(58)
            ->nonQueued();
    }

}
