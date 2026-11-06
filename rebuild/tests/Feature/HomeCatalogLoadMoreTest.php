<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductPackage;
use App\Services\CheckoutPricing;
use App\Services\StorefrontContentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Tests\TestCase;

class HomeCatalogLoadMoreTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $pricing = Mockery::mock(CheckoutPricing::class);
        $pricing->shouldReceive('forPackages')->andReturnUsing(
            fn (array $ids): array => collect($ids)->mapWithKeys(
                fn ($id): array => [$id => ['subtotal_idr' => 1000]]
            )->all()
        );
        $this->app->instance(CheckoutPricing::class, $pricing);
    }

    private function categoryWithProducts(int $count, int $unbuyable = 0): Category
    {
        $suffix = bin2hex(random_bytes(6));
        $category = Category::create([
            'name' => 'Load more '.$suffix,
            'slug' => 'load-more-'.$suffix,
            'is_active' => true,
            'sort_order' => 999,
        ]);
        for ($i = 1; $i <= $count + $unbuyable; $i++) {
            $product = Product::create([
                'category_id' => $category->id,
                'name' => sprintf('Load More %03d', $i),
                'slug' => 'load-more-'.$suffix.'-'.$i,
                'margin_percent' => 10,
                'fulfillment_mode' => 'MANUAL',
                'is_active' => true,
                'sort_order' => $i,
            ]);
            ProductPackage::create([
                'product_id' => $product->id,
                'code' => 'LOAD-'.$suffix.'-'.$i,
                'name' => 'Fixture',
                'is_active' => $i <= $count,
            ]);
        }

        return $category;
    }

    public function test_home_grid_pages_twelve_buyable_products_up_to_one_hundred(): void
    {
        $category = $this->categoryWithProducts(100, 2);
        $url = '/?category='.$category->slug;
        for ($pageNumber = 1; $pageNumber <= 9; $pageNumber++) {
            $expectedCount = min(12, 100 - (($pageNumber - 1) * 12));
            $this->get($url.'&page='.$pageNumber)->assertOk()->assertInertia(
                fn (Assert $page) => $page->component('Catalog/Index')
                    ->has('products.data', $expectedCount)
                    ->where('products.per_page', 12)
                    ->where('products.total', 100)
                    ->where('products.current_page', $pageNumber)
            );
        }
    }

    public function test_partial_filters_do_not_evaluate_unrequested_home_sections(): void
    {
        $category = $this->categoryWithProducts(30);
        // Use the initial page version so partial reloads are not rejected with 409.
        $page = $this->get('/')->viewData('page');
        $version = (string) ($page['version'] ?? '');
        $content = Mockery::mock(StorefrontContentService::class)->makePartial();
        $content->shouldNotReceive('homeBanners');
        $content->shouldNotReceive('popups');
        $content->shouldNotReceive('news');
        $content->shouldNotReceive('reviews');
        $this->app->instance(StorefrontContentService::class, $content);

        $this->get('/?category='.$category->slug.'&page=3', [
            'X-Inertia-Version' => $version,
            'X-Inertia' => 'true',
            'X-Inertia-Partial-Component' => 'Catalog/Index',
            'X-Inertia-Partial-Data' => 'products,filters',
        ])->assertOk()->assertJsonPath('component', 'Catalog/Index')
            ->assertJsonCount(6, 'props.products.data')
            ->assertJsonPath('props.products.total', 30)
            ->assertJsonMissingPath('props.popularProducts')
            ->assertJsonMissingPath('props.banners')
            ->assertJsonMissingPath('props.seo');
    }

    public function test_category_change_restarts_page_at_one_and_full_home_remains_complete(): void
    {
        $category = $this->categoryWithProducts(30);
        $other = $this->categoryWithProducts(15);
        $this->get('/?category='.$category->slug.'&page=2')->assertOk()->assertInertia(
            fn (Assert $page) => $page->has('products.data', 12)
        );
        $this->get('/?category='.$other->slug)->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('Catalog/Index')
                ->has('products.data', 12)
                ->where('products.current_page', 1)
                ->where('products.total', 15)
                ->has('popularProducts')
                ->has('banners')
                ->has('news')
                ->has('reviews')
                ->has('seo')
        );
    }
}
