<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ServerSeoMetadataTest extends TestCase
{
    use DatabaseTransactions;

    public function test_server_html_has_distinct_titles_descriptions_default_image_and_product_offers(): void
    {
        $category = Category::where('slug', 'game')->firstOrFail();
        DB::table('providers')->where('code', 'DIGIFLAZZ')->update(['is_active' => true]);
        $providerId = DB::table('providers')->where('code', 'DIGIFLAZZ')->value('id');
        $suffix = bin2hex(random_bytes(4));
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Game SEO '.$suffix,
            'slug' => 'game-seo-'.$suffix,
            'publisher' => 'Studio SEO',
            'description' => 'Isi game ini resmi dan tersedia untuk pembelian nominal aktif. Produk memiliki deskripsi sendiri.',
            'fulfillment_mode' => 'AUTO_PROVIDER',
            'margin_percent' => 10,
            'is_active' => true,
        ]);
        $package = $product->packages()->create([
            'code' => 'SEO'.$suffix, 'name' => '100 Coins',
            'is_active' => true, 'nominal_value' => 100,
        ]);
        DB::table('provider_mappings')->insert([
            'product_package_id' => $package->id,
            'provider_id' => $providerId,
            'external_sku' => 'SEO-SKU-'.$suffix,
            'cost_idr' => 10000, 'max_price_idr' => 12000,
            'priority' => 1, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->seedCheckoutDigiflazzCatalog($package->id);

        $home = $this->get('/')->assertOk()->getContent();
        $detail = $this->get('/catalog/'.$product->slug)->assertOk()->getContent();
        $faq = $this->get('/faq')->assertOk()->getContent();

        foreach ([$home, $detail, $faq] as $html) {
            $this->assertSame(1, preg_match_all('/<title\b[^>]*>/i', $html));
            $this->assertSame(1, preg_match_all('/<meta\b[^>]*name="description"/i', $html));
            $this->assertSame(1, preg_match_all('/<meta\b[^>]*rel="canonical"/i', $html));
            $this->assertStringContainsString('name="robots" content="index,follow"', $html);
        }
        $this->assertSame('LFAMILIA STORE', $this->title($home));
        $this->assertSame($product->name.' — LFAMILIA STORE', $this->title($detail));
        $this->assertSame('Pertanyaan Umum — LFAMILIA STORE', $this->title($faq));
        $this->assertNotSame($this->description($home), $this->description($detail));
        $this->assertStringContainsString('Isi game ini resmi', $this->description($detail));
        $this->assertStringContainsString('property="og:title" content="'.$product->name.' — LFAMILIA STORE"', $detail);
        $this->assertStringContainsString('name="twitter:title" content="'.$product->name.' — LFAMILIA STORE"', $detail);

        preg_match('/<meta\b[^>]*property="og:image"[^>]*content="([^"]+)"/', $home, $image);
        $this->assertNotEmpty($image[1] ?? null);
        $this->assertTrue(str_starts_with($image[1], 'http'));
        $this->assertTrue(file_exists(public_path('brand/lfamilia-footer-desktop-wordmark.jpg')));

        $website = $this->schema($home);
        $this->assertSame('WebSite', $website['@type']);
        $this->assertSame('SearchAction', $website['potentialAction']['@type']);
        $schema = $this->schema($detail);
        $this->assertSame('Product', $schema['@type']);
        $this->assertSame($product->name, $schema['name']);
        $this->assertSame('Studio SEO', $schema['brand']['name']);
        $this->assertSame('AggregateOffer', $schema['offers']['@type']);
        $this->assertSame('IDR', $schema['offers']['priceCurrency']);
        $this->assertGreaterThan(0, $schema['offers']['lowPrice']);
        $this->assertSame($schema['offers']['lowPrice'], $schema['offers']['highPrice']);
        $this->assertSame(1, $schema['offers']['offerCount']);
        $this->assertSame('https://schema.org/InStock', $schema['offers']['availability']);
        $this->assertStringNotContainsString('application/ld+json', $faq);
    }

    public function test_custom_home_seo_and_word_boundary_are_respected(): void
    {
        foreach ([
            'store.seo_title' => 'Judul Beranda Khusus',
            'store.seo_description' => 'Deskripsi beranda khusus dari pengaturan toko.',
        ] as $key => $value) {
            DB::table('system_settings')->updateOrInsert(['key' => $key], [
                'value' => json_encode($value), 'updated_at' => now(),
            ]);
        }

        $home = $this->get('/')->assertOk()->getContent();
        $this->assertSame('Judul Beranda Khusus', $this->title($home));
        $this->assertSame('Deskripsi beranda khusus dari pengaturan toko.', $this->description($home));
        $this->assertStringContainsString('property="og:description" content="Deskripsi beranda khusus', $home);

        $text = implode(' ', array_fill(0, 30, 'panjangkalimat'));
        $seo = \App\Support\SeoMetadata::forPage('Catalog/Show', [
            'product' => ['name' => 'Game Panjang', 'description' => $text],
            'packages' => [
                ['is_available' => true, 'price_idr' => 2000],
                ['is_available' => false, 'price_idr' => 1],
                ['is_available' => true, 'price_idr' => 5000],
            ],
        ], ['storeName' => 'LFAMILIA STORE']);
        $this->assertLessThanOrEqual(160, mb_strlen($seo['description']));
        $this->assertStringEndsNotWith('panjangkalima', $seo['description']);
        $this->assertSame(2000, $seo['structuredData']['offers']['lowPrice']);
        $this->assertSame(5000, $seo['structuredData']['offers']['highPrice']);
        $this->assertSame(2, $seo['structuredData']['offers']['offerCount']);
    }

    private function title(string $html): string
    {
        preg_match('/<title\b[^>]*>(.*?)<\/title>/s', $html, $matches);

        return html_entity_decode($matches[1] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function description(string $html): string
    {
        preg_match('/<meta\b[^>]*name="description"[^>]*content="([^"]*)"/', $html, $matches);

        return html_entity_decode($matches[1] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function schema(string $html): array
    {
        preg_match('/<script\b[^>]*type="application\/ld\+json"[^>]*>(.*?)<\/script>/s', $html, $matches);
        $this->assertNotEmpty($matches[1] ?? null);
        $result = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($result);

        return $result;
    }
}
