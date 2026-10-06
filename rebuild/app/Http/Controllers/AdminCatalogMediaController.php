<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\HomeBanner;
use App\Models\NewsArticle;
use App\Models\Product;
use App\Models\ProductPackage;
use App\Models\SitePopup;
use App\Models\StoreAsset;
use App\Services\CatalogAudit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\MediaLibrary\HasMedia;

class AdminCatalogMediaController
{
    private function target(string $type, int $id): HasMedia
    {
        return match ($type) {
            'category' => Category::findOrFail($id),
            'banner' => HomeBanner::findOrFail($id),
            'product' => Product::findOrFail($id),
            'package' => ProductPackage::findOrFail($id),
            'asset' => StoreAsset::findOrFail($id),
            'news' => NewsArticle::findOrFail($id),
            'popup' => SitePopup::findOrFail($id),
            default => abort(404),
        };
    }

    public function store(Request $request, string $type, int $id, CatalogAudit $audit): RedirectResponse
    {
        $data = $request->validate([
            'image' => ['required', 'image', 'mimes:jpeg,png,webp', 'max:5120'],
            'collection' => ['required', Rule::in(
                $type === 'product' ? ['image', 'banner']
                    : ($type === 'banner' ? ['desktop', 'mobile'] : ['image'])
            )],
        ]);
        $model = $this->target($type, $id);
        $media = $model->addMediaFromRequest('image')
            ->toMediaCollection($data['collection'], config('media-library.disk_name', 'public'));

        if ($model instanceof StoreAsset && $model->key === 'favicon' && ! $model->is_active) {
            $model->update(['is_active' => true]);
        }

        $audit->record($request, 'catalog.media.uploaded', $type, $id, null, [
            'media_id' => $media->id,
            'collection' => $data['collection'],
            'asset_activated' => $model instanceof StoreAsset && $model->key === 'favicon',
        ]);

        return back();
    }

    public function destroy(Request $request, string $type, int $id, CatalogAudit $audit): RedirectResponse
    {
        $data = $request->validate([
            'collection' => ['required', Rule::in(
                $type === 'product' ? ['image', 'banner']
                    : ($type === 'banner' ? ['desktop', 'mobile'] : ['image'])
            )],
        ]);
        $model = $this->target($type, $id);
        $media = $model->getFirstMedia($data['collection']);
        abort_unless($media, 404);
        $mediaId = $media->id;
        $media->delete();
        $audit->record($request, 'catalog.media.deleted', $type, $id,
            ['media_id' => $mediaId, 'collection' => $data['collection']], []);

        return back();
    }
}
