<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\HomeBanner;
use App\Models\Product;
use App\Services\StorefrontContentService;
use App\Support\OptimizedMedia;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaPerformanceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_product_images_generate_small_and_detail_webp_without_overwriting_original(): void
    {
        Storage::fake(config('media-library.disk_name', 'public'));

        $product = Product::create([
            'category_id' => Category::where('slug', 'game')->firstOrFail()->id,
            'name' => 'Media Performance '.bin2hex(random_bytes(3)),
            'slug' => 'media-perf-'.bin2hex(random_bytes(4)),
            'fulfillment_mode' => 'MANUAL',
            'margin_percent' => 10,
            'is_active' => true,
        ]);

        $media = $product->addMedia(UploadedFile::fake()->image('cover.jpg', 1200, 1600))
            ->toMediaCollection('image', config('media-library.disk_name', 'public'));

        $this->assertFileExists($media->getPath());
        $this->assertSame('image/jpeg', mime_content_type($media->getPath()));
        foreach (['card', 'detail'] as $conversion) {
            $this->assertTrue($media->fresh()->hasGeneratedConversion($conversion));
            $this->assertFileExists($media->getPath($conversion));
            $this->assertSame('image/webp', mime_content_type($media->getPath($conversion)));
            $this->assertStringEndsWith('.webp', OptimizedMedia::url($product, 'image', $conversion));
        }

        [$width, $height] = getimagesize($media->getPath('card'));
        $this->assertLessThanOrEqual(288, $width);
        $this->assertLessThanOrEqual(432, $height);
        $this->assertLessThanOrEqual(60 * 1024, filesize($media->getPath('card')));
        $this->assertGreaterThan(0, filesize($media->getPath('detail')));
    }

    public function test_home_banner_uses_webp_and_missing_conversion_falls_back_safely(): void
    {
        Storage::fake(config('media-library.disk_name', 'public'));

        $banner = HomeBanner::create([
            'title' => 'Banner Conversion',
            'show_desktop' => true,
            'show_mobile' => true,
            'sort_order' => 0,
            'is_active' => true,
        ]);
        $media = $banner->addMedia(UploadedFile::fake()->image('hero.png', 1920, 510))
            ->toMediaCollection('desktop', config('media-library.disk_name', 'public'));

        $this->assertFileExists($media->getPath());
        $this->assertSame('image/png', mime_content_type($media->getPath()));
        $this->assertTrue($media->fresh()->hasGeneratedConversion('display'));
        $this->assertTrue($media->fresh()->hasGeneratedConversion('card'));
        $this->assertSame('image/webp', mime_content_type($media->getPath('display')));
        $this->assertLessThanOrEqual(300 * 1024, filesize($media->getPath('display')));
        $this->assertStringEndsWith('.webp', app(StorefrontContentService::class)->homeBanners()->first()['desktop_url']);

        // Historical uploads without conversions must not become broken images.
        $media->fresh()->forceFill(['generated_conversions' => []])->save();
        $banner->unsetRelation('media');
        $this->assertSame($media->getUrl(), OptimizedMedia::url($banner, 'desktop', 'display'));
    }
}
