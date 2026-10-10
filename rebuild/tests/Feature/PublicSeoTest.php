<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\NewsArticle;
use App\Models\Product;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PublicSeoTest extends TestCase
{
    use DatabaseTransactions;

    public function test_robots_and_sitemap_index_only_public_catalog_and_published_content(): void
    {
        $category = Category::where('slug', 'game')->firstOrFail();
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Search Index Product',
            'slug' => 'search-index-product',
            'fulfillment_mode' => 'AUTO_PROVIDER',
            'margin_percent' => 9,
            'is_active' => true,
        ]);
        $product->packages()->create([
            'code' => 'SEO100', 'name' => '100 Diamonds', 'is_active' => true,
        ]);
        $hidden = Product::create([
            'category_id' => $category->id,
            'name' => 'Hidden Search Product',
            'slug' => 'hidden-search-product',
            'fulfillment_mode' => 'AUTO_PROVIDER',
            'margin_percent' => 9,
            'is_active' => false,
        ]);
        $hidden->packages()->create([
            'code' => 'SEO200', 'name' => '200 Diamonds', 'is_active' => true,
        ]);
        NewsArticle::create([
            'slug' => 'seo-public-news',
            'title' => 'Public SEO News',
            'body' => 'Test announcement',
            'summary' => 'Public',
            'is_active' => true,
            'published_at' => now()->subDay(),
        ]);
        NewsArticle::create([
            'slug' => 'seo-future-news',
            'title' => 'Future SEO News',
            'body' => 'Unpublished',
            'is_active' => true,
            'published_at' => now()->addDay(),
        ]);

        $robots = $this->get('/robots.txt')->assertOk();
        $robots->assertSee('Disallow: /admin/', false);
        $robots->assertSee('Disallow: /account/', false);
        $robots->assertSee(route('seo.sitemap'), false);

        $sitemap = $this->get('/sitemap.xml')->assertOk();
        $this->assertStringContainsString('application/xml', (string) $sitemap->headers->get('Content-Type'));
        $sitemap->assertSee('search-index-product', false);
        $sitemap->assertSee('seo-public-news', false);
        $sitemap->assertDontSee('hidden-search-product', false);
        $sitemap->assertDontSee('seo-future-news', false);
        $sitemap->assertDontSee('/admin/', false);
        $sitemap->assertDontSee('/payment?', false);
    }

    public function test_public_pages_include_canonical_metadata_while_login_is_noindex(): void
    {
        $home = $this->get('/')->assertOk();
        $home->assertSee('rel="canonical"', false);
        $home->assertSee('name="description"', false);
        $home->assertSee('application/ld+json', false);
        $home->assertSee('content="index,follow"', false);

        $login = $this->get('/login')->assertOk();
        $login->assertSee('content="noindex,nofollow"', false);
        $login->assertDontSee('rel="canonical"', false);
    }
}
