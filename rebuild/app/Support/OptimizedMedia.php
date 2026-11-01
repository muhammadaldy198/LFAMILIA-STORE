<?php

namespace App\Support;

use Spatie\MediaLibrary\HasMedia;

final class OptimizedMedia
{
    /**
     * Media uploaded before conversion rollout stays visible until backfilled.
     * A generated conversion is always preferred over the original file.
     */
    public static function url(HasMedia $model, string $collection, string $conversion): string
    {
        $media = $model->getFirstMedia($collection);

        if ($media === null) {
            return '';
        }

        return $media->hasGeneratedConversion($conversion)
            ? $media->getUrl($conversion)
            : $media->getUrl();
    }
}
