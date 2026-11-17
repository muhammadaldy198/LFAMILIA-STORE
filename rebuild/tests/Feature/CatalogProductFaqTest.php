<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProviderMapping;
use App\Support\ProductFaq;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Mengunci FAQ halaman produk (PR 3): lapis khusus per game tampil sebelum
 * lapis umum, produk tanpa lapis khusus hanya menerima 8 entri umum, dan
 * jawaban lama proses mengikuti mode pemenuhan produk. Dipisah dari
 * CatalogTest yang sudah melewati batas ukuran file repo.
 */
class CatalogProductFaqTest extends TestCase
{
    use DatabaseTransactions;

    public function test_product_page_sends_game_specific_faq_before_general_faq(): void
    {
        $page = $this->productFaqQuestions('mobile-legends', 'Mobile Legends');
        $this->assertCount(12, $page);
        $this->assertSame('Apa batas pembelian Weekly Diamond Pass?', $page[0]);
        $this->assertSame('Berapa lama top up saya diproses?', $page[4]);
        $this->assertContains('Apakah saya bisa meminta pengembalian dana (refund)?', $page);
    }

    public function test_product_page_free_fire_gets_its_own_faq_plus_full_general_layer(): void
    {
        $page = $this->productFaqQuestions('free-fire', 'Free Fire');
        $this->assertCount(10, $page);
        $this->assertSame('Apakah Membership Mingguan Free Fire sama dengan Weekly Diamond Pass Mobile Legends?', $page[0]);
        $this->assertSame('Bagaimana cara memakai kode voucher?', $page[9]);
    }

    public function test_product_page_without_game_specific_faq_gets_only_the_eight_general_entries(): void
    {
        $page = $this->productFaqQuestions('delta-force-garena', 'Delta Force Garena');
        $this->assertCount(8, $page);
        $this->assertSame('Berapa lama top up saya diproses?', $page[0]);
    }

    public function test_product_faq_processing_answer_follows_fulfillment_mode(): void
    {
        $auto = ProductFaq::forProduct(new Product(['slug' => 'delta-force-garena', 'fulfillment_mode' => 'AUTO_PROVIDER']));
        $manual = ProductFaq::forProduct(new Product(['slug' => 'delta-force-garena', 'fulfillment_mode' => 'MANUAL']));
        $this->assertSame($auto[0]['question'], $manual[0]['question']);
        $this->assertStringStartsWith('Otomatis setelah pembayaran terkonfirmasi', $auto[0]['answer']);
        $this->assertStringStartsWith('Diproses manual oleh admin toko', $manual[0]['answer']);
    }

    /** @return list<string> */
    private function productFaqQuestions(string $slug, string $name): array
    {
        $category = Category::where('slug', 'game')->firstOrFail();
        $providerId = $this->activateDigiflazzProvider();
        $product = Product::create([
            'category_id' => $category->id, 'name' => $name, 'slug' => $slug,
            'fulfillment_mode' => 'AUTO_PROVIDER', 'margin_percent' => 10, 'is_active' => true,
        ]);
        $this->addPurchasablePackage($product, $providerId, 'FAQ-1', '100 Koin', 100, 'FAQ100', 10000);

        $questions = [];
        $this->get('/catalog/'.$slug)->assertOk()->assertInertia(function (Assert $page) use (&$questions) {
            $questions = array_column($page->toArray()['props']['productFaqs'], 'question');
            $page->missing('faqs');
        });

        return $questions;
    }

    private function activateDigiflazzProvider(): int
    {
        DB::table('providers')->where('code', 'DIGIFLAZZ')->update(['is_active' => true]);

        return (int) DB::table('providers')->where('code', 'DIGIFLAZZ')->value('id');
    }

    private function addPurchasablePackage(Product $product, int $providerId, string $code, string $name, int $nominalValue, string $sku, int $costIdr): void
    {
        $package = $product->packages()->create([
            'code' => $code,
            'name' => $name,
            'nominal_value' => $nominalValue,
            'is_active' => true,
        ]);
        ProviderMapping::create([
            'product_package_id' => $package->id,
            'provider_id' => $providerId,
            'external_sku' => $sku,
            'cost_idr' => $costIdr,
            'store_cost_idr' => $costIdr,
            'is_active' => true,
        ]);
        $this->seedCheckoutDigiflazzCatalog($package->id);
    }
}
