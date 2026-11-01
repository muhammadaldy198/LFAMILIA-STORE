<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class StoreAsset extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $fillable = ['target_url', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('image')->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif'])->singleFile();
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('card')
            ->performOnCollections('image')
            ->fit(Fit::Max, 480, 360)
            ->format('webp')
            ->quality(50)
            ->nonQueued();

        $this->addMediaConversion('display')
            ->performOnCollections('image')
            ->fit(Fit::Max, 1440, 900)
            ->format('webp')
            ->quality(48)
            ->nonQueued();

        $this->addMediaConversion('icon')
            ->performOnCollections('image')
            ->fit(Fit::Max, 128, 128)
            ->format('webp')
            ->quality(65)
            ->nonQueued();
    }

}
