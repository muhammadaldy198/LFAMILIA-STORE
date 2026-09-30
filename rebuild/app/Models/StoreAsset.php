<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

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
}
