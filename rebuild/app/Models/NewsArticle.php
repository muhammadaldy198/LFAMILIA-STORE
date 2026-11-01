<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class NewsArticle extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $fillable = ['slug', 'title', 'summary', 'body', 'source_label', 'sort_order', 'is_active', 'published_at'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'published_at' => 'datetime'];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('image')
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif'])
            ->singleFile();
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('card')
            ->performOnCollections('image')
            ->fit(Fit::Max, 600, 400)
            ->format('webp')
            ->quality(55)
            ->nonQueued();

        $this->addMediaConversion('detail')
            ->performOnCollections('image')
            ->fit(Fit::Max, 1200, 800)
            ->format('webp')
            ->quality(58)
            ->nonQueued();
    }
}
