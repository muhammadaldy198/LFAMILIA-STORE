<?php

namespace Tests\Feature;

use App\Jobs\SendTransactionalEmailJob;
use App\Models\Category;
use App\Models\IntegrationCredential;
use App\Models\Product;
use App\Services\FulfillmentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Email penyelesaian untuk produk kategori voucher (Digiflazz):
 * SN adalah kode redeemable — tampil sebagai "Kode voucher",
 * klasifikasinya mengikuti snapshot order, dan SN kosong tidak
 * boleh dijanjikan sebagai kode. Dipisah dari
 * CustomerEmailMomentsTest karena file itu sudah melewati batas
 * 500 baris sebelum fitur ini (aturan repo: file besar tidak
 * ditambah fitur — pecah dulu kelompok fungsinya).
 */
class CustomerEmailVoucherMomentsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->withoutMiddleware(ThrottleRequests::class);
        Queue::fake();
    }

    /**
     * @return array{product:Product,package_id:int,mapping_id:int}
     */
    private function catalog(string $categorySlug = 'game'): array
    {
        $category = Category::where('slug', $categorySlug)->firstOrFail();
        DB::table('providers')->where('code', 'DIGIFLAZZ')->update(['is_active' => true]);
        $providerId = DB::table('providers')->where('code', 'DIGIFLAZZ')->value('id');

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Email Moments Game '.bin2hex(random_bytes(3)),
            'slug' => 'email-moments-game-'.bin2hex(random_bytes(4)),
            'margin_percent' => 10,
            'fulfillment_mode' => 'AUTO_PROVIDER',
            'nickname_check_enabled' => false,
            'is_active' => true,
        ]);
        $product->fields()->create([
            'field_key' => 'user_id',
            'label' => 'User ID',
            'type' => 'text',
            'is_required' => true,
            'sort_order' => 0,
        ]);
        $product->fields()->create([
            'field_key' => 'zone_id',
            'label' => 'Zone ID',
            'type' => 'text',
            'is_required' => false,
            'sort_order' => 1,
        ]);
        $package = $product->packages()->create([
            'code' => 'EML'.bin2hex(random_bytes(3)),
            'name' => '100 Diamonds',
            'nominal_value' => 100,
            // Explicit nominal markup: fixtures must not inherit a game price.
            'pricing_mode' => 'PERCENT', 'margin_percent' => 10,
            'is_active' => true,
        ]);
        $mappingId = DB::table('provider_mappings')->insertGetId([
            'product_package_id' => $package->id,
            'provider_id' => $providerId,
            'external_sku' => 'EML-SKU-'.bin2hex(random_bytes(4)),
            'cost_idr' => 10000,
            'max_price_idr' => 12000,
            'fulfillment_config' => json_encode(['customer_no_template' => '{{user_id}}{{zone_id}}'], JSON_THROW_ON_ERROR),
            'priority' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['product' => $product, 'package_id' => $package->id, 'mapping_id' => $mappingId];
    }

    public function test_order_completed_email_for_voucher_category_product_shows_sn_as_voucher_code(): void
    {
        IntegrationCredential::updateOrCreate(['code' => 'digiflazz'], [
            'config_ciphertext' => [
                'username' => 'buyer-test',
                'api_key' => 'api-secret',
                'webhook_secret' => 'webhook-secret',
                'base_url' => 'https://digiflazz.test',
                'testing' => true,
            ],
            'is_active' => true,
        ]);
        $catalog = $this->catalog('voucher');
        $orderId = DB::table('orders')->insertGetId([
            'order_number' => 'ORD-EMAIL-VOUCHER-'.bin2hex(random_bytes(4)),
            'guest_email' => 'moments-voucher@example.test',
            'guest_phone' => '081234567890',
            'product_id' => $catalog['product']->id,
            'product_package_id' => $catalog['package_id'],
            'provider_mapping_id' => $catalog['mapping_id'],
            'status' => 'PAID',
            'currency' => 'IDR',
            'customer_input' => json_encode(['user_id' => '123456', 'zone_id' => '7890'], JSON_THROW_ON_ERROR),
            'snapshot' => json_encode([
                'product' => ['id' => $catalog['product']->id, 'name' => $catalog['product']->name, 'category_id' => $catalog['product']->category_id, 'category_slug' => $catalog['product']->category->slug, 'fulfillment_mode' => 'AUTO_PROVIDER'],
                'package' => ['id' => $catalog['package_id'], 'name' => '100 Diamonds'],
                'provider' => ['mapping_id' => $catalog['mapping_id'], 'code' => 'DIGIFLAZZ', 'sku' => 'EML-SKU', 'cost_idr' => 10000, 'max_price_idr' => 12000],
                'pricing' => ['cost_idr' => 10000, 'total_idr' => 11000],
                'customer_input' => ['user_id' => '123456', 'zone_id' => '7890'],
            ], JSON_THROW_ON_ERROR),
            'cost_idr' => 10000,
            'margin_idr' => 1000,
            'discount_idr' => 0,
            'fee_idr' => 0,
            'total_idr' => 11000,
            'idempotency_key' => 'email-moments-voucher-'.bin2hex(random_bytes(6)),
            'paid_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->seedCheckoutDigiflazzCatalog($catalog['package_id']);
        // JANGAN tambah return type `: Response` pada closure ini:
        // Http::response() mengembalikan FulfilledPromise, bukan
        // Response — return type itu melempar TypeError di dalam stub,
        // transact() mengira provider tidak dapat dipastikan
        // (markUnknown) dan order mentok PROCESSING.
        Http::fake(fn ($request) => Http::response(['data' => [
            'ref_id' => $request->data()['ref_id'],
            'customer_no' => $request->data()['customer_no'],
            'buyer_sku_code' => $request->data()['buyer_sku_code'],
            'message' => 'Sukses',
            'status' => 'Sukses',
            'rc' => '00',
            'sn' => 'PB-VOUCHER-777',
            'price' => 10000,
        ]]));

        $service = app(FulfillmentService::class);
        $service->startOrder($orderId);
        $attempt = DB::table('fulfillment_attempts')->where('order_id', $orderId)->first();
        $service->sendAttempt((int) $attempt->id);

        $this->assertSame('SUCCESS', DB::table('orders')->where('id', $orderId)->value('status'));
        Queue::assertPushed(SendTransactionalEmailJob::class, function (SendTransactionalEmailJob $job): bool {
            return $job->recipient === 'moments-voucher@example.test'
                && str_contains($job->subject, 'siap ditukarkan')
                // SN Digiflazz untuk produk voucher adalah kode
                // redeemable — tampil sebagai kode voucher, bukan
                // nomor referensi pengiriman top up.
                && str_contains($job->text, 'Kode voucher: PB-VOUCHER-777')
                && str_contains($job->text, 'tukarkan sendiri')
                && ! str_contains($job->text, 'No. Referensi Pengiriman')
                && ! str_contains($job->text, 'periksa saldo di dalam game')
                && ! str_contains($job->text, 'sudah masuk ke akunmu');
        });
    }

    public function test_order_completed_email_uses_order_time_category_when_product_moves_category(): void
    {
        IntegrationCredential::updateOrCreate(['code' => 'digiflazz'], [
            'config_ciphertext' => [
                'username' => 'buyer-test',
                'api_key' => 'api-secret',
                'webhook_secret' => 'webhook-secret',
                'base_url' => 'https://digiflazz.test',
                'testing' => true,
            ],
            'is_active' => true,
        ]);
        $catalog = $this->catalog('game');
        $orderId = DB::table('orders')->insertGetId([
            'order_number' => 'ORD-EMAIL-MOVED-'.bin2hex(random_bytes(4)),
            'guest_email' => 'moments-moved@example.test',
            'guest_phone' => '081234567890',
            'product_id' => $catalog['product']->id,
            'product_package_id' => $catalog['package_id'],
            'provider_mapping_id' => $catalog['mapping_id'],
            'status' => 'PAID',
            'currency' => 'IDR',
            'customer_input' => json_encode(['user_id' => '123456', 'zone_id' => '7890'], JSON_THROW_ON_ERROR),
            'snapshot' => json_encode([
                'product' => ['id' => $catalog['product']->id, 'name' => $catalog['product']->name, 'category_id' => $catalog['product']->category_id, 'category_slug' => $catalog['product']->category->slug, 'fulfillment_mode' => 'AUTO_PROVIDER'],
                'package' => ['id' => $catalog['package_id'], 'name' => '100 Diamonds'],
                'provider' => ['mapping_id' => $catalog['mapping_id'], 'code' => 'DIGIFLAZZ', 'sku' => 'EML-SKU', 'cost_idr' => 10000, 'max_price_idr' => 12000],
                'pricing' => ['cost_idr' => 10000, 'total_idr' => 11000],
                'customer_input' => ['user_id' => '123456', 'zone_id' => '7890'],
            ], JSON_THROW_ON_ERROR),
            'cost_idr' => 10000,
            'margin_idr' => 1000,
            'discount_idr' => 0,
            'fee_idr' => 0,
            'total_idr' => 11000,
            'idempotency_key' => 'email-moments-moved-'.bin2hex(random_bytes(6)),
            'paid_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        // Admin memindah produk ke kategori voucher SELAGI order
        // berjalan — email harus tetap mengikuti kategori saat
        // order dibuat (game), bukan kategori produk saat ini.
        $voucherCategoryId = DB::table('categories')->where('slug', 'voucher')->value('id');
        DB::table('products')->where('id', $catalog['product']->id)->update(['category_id' => $voucherCategoryId]);
        $this->seedCheckoutDigiflazzCatalog($catalog['package_id']);
        Http::fake(fn ($request) => Http::response(['data' => [
            'ref_id' => $request->data()['ref_id'],
            'customer_no' => $request->data()['customer_no'],
            'buyer_sku_code' => $request->data()['buyer_sku_code'],
            'message' => 'Sukses',
            'status' => 'Sukses',
            'rc' => '00',
            'sn' => 'SERIAL-MOVED-1',
            'price' => 10000,
        ]]));

        $service = app(FulfillmentService::class);
        $service->startOrder($orderId);
        $attempt = DB::table('fulfillment_attempts')->where('order_id', $orderId)->first();
        $service->sendAttempt((int) $attempt->id);

        $this->assertSame('SUCCESS', DB::table('orders')->where('id', $orderId)->value('status'));
        Queue::assertPushed(SendTransactionalEmailJob::class, function (SendTransactionalEmailJob $job): bool {
            return $job->recipient === 'moments-moved@example.test'
                && str_contains($job->text, 'No. Referensi Pengiriman: SERIAL-MOVED-1')
                && ! str_contains($job->text, 'Kode voucher');
        });
    }

    public function test_order_completed_email_for_voucher_product_without_sn_does_not_promise_a_code(): void
    {
        IntegrationCredential::updateOrCreate(['code' => 'digiflazz'], [
            'config_ciphertext' => [
                'username' => 'buyer-test',
                'api_key' => 'api-secret',
                'webhook_secret' => 'webhook-secret',
                'base_url' => 'https://digiflazz.test',
                'testing' => true,
            ],
            'is_active' => true,
        ]);
        $catalog = $this->catalog('voucher');
        $orderId = DB::table('orders')->insertGetId([
            'order_number' => 'ORD-EMAIL-NOSN-'.bin2hex(random_bytes(4)),
            'guest_email' => 'moments-nosn@example.test',
            'guest_phone' => '081234567890',
            'product_id' => $catalog['product']->id,
            'product_package_id' => $catalog['package_id'],
            'provider_mapping_id' => $catalog['mapping_id'],
            'status' => 'PAID',
            'currency' => 'IDR',
            'customer_input' => json_encode(['user_id' => '123456', 'zone_id' => '7890'], JSON_THROW_ON_ERROR),
            'snapshot' => json_encode([
                'product' => ['id' => $catalog['product']->id, 'name' => $catalog['product']->name, 'category_id' => $catalog['product']->category_id, 'category_slug' => $catalog['product']->category->slug, 'fulfillment_mode' => 'AUTO_PROVIDER'],
                'package' => ['id' => $catalog['package_id'], 'name' => '100 Diamonds'],
                'provider' => ['mapping_id' => $catalog['mapping_id'], 'code' => 'DIGIFLAZZ', 'sku' => 'EML-SKU', 'cost_idr' => 10000, 'max_price_idr' => 12000],
                'pricing' => ['cost_idr' => 10000, 'total_idr' => 11000],
                'customer_input' => ['user_id' => '123456', 'zone_id' => '7890'],
            ], JSON_THROW_ON_ERROR),
            'cost_idr' => 10000,
            'margin_idr' => 1000,
            'discount_idr' => 0,
            'fee_idr' => 0,
            'total_idr' => 11000,
            'idempotency_key' => 'email-moments-nosn-'.bin2hex(random_bytes(6)),
            'paid_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->seedCheckoutDigiflazzCatalog($catalog['package_id']);
        // Provider melaporkan sukses TANPA SN: order tetap SUCCESS,
        // tetapi email tidak boleh menjanjikan kode yang tidak ada.
        Http::fake(fn ($request) => Http::response(['data' => [
            'ref_id' => $request->data()['ref_id'],
            'customer_no' => $request->data()['customer_no'],
            'buyer_sku_code' => $request->data()['buyer_sku_code'],
            'message' => 'Sukses',
            'status' => 'Sukses',
            'rc' => '00',
            'sn' => '',
            'price' => 10000,
        ]]));

        $service = app(FulfillmentService::class);
        $service->startOrder($orderId);
        $attempt = DB::table('fulfillment_attempts')->where('order_id', $orderId)->first();
        $service->sendAttempt((int) $attempt->id);

        $this->assertSame('SUCCESS', DB::table('orders')->where('id', $orderId)->value('status'));
        Queue::assertPushed(SendTransactionalEmailJob::class, function (SendTransactionalEmailJob $job): bool {
            return $job->recipient === 'moments-nosn@example.test'
                && str_contains($job->subject, 'sudah diproses')
                && ! str_contains($job->subject, 'siap ditukarkan')
                && str_contains($job->text, 'belum tersedia dari penyedia')
                && ! str_contains($job->text, 'Kode voucher:')
                && ! str_contains($job->text, 'tukarkan sendiri');
        });
    }

    public function test_order_completed_email_stays_voucher_when_category_slug_is_renamed(): void
    {
        IntegrationCredential::updateOrCreate(['code' => 'digiflazz'], [
            'config_ciphertext' => [
                'username' => 'buyer-test',
                'api_key' => 'api-secret',
                'webhook_secret' => 'webhook-secret',
                'base_url' => 'https://digiflazz.test',
                'testing' => true,
            ],
            'is_active' => true,
        ]);
        $catalog = $this->catalog('voucher');
        $orderId = DB::table('orders')->insertGetId([
            'order_number' => 'ORD-EMAIL-RENAMED-'.bin2hex(random_bytes(4)),
            'guest_email' => 'moments-renamed@example.test',
            'guest_phone' => '081234567890',
            'product_id' => $catalog['product']->id,
            'product_package_id' => $catalog['package_id'],
            'provider_mapping_id' => $catalog['mapping_id'],
            'status' => 'PAID',
            'currency' => 'IDR',
            'customer_input' => json_encode(['user_id' => '123456', 'zone_id' => '7890'], JSON_THROW_ON_ERROR),
            'snapshot' => json_encode([
                'product' => ['id' => $catalog['product']->id, 'name' => $catalog['product']->name, 'category_id' => $catalog['product']->category_id, 'category_slug' => $catalog['product']->category->slug, 'fulfillment_mode' => 'AUTO_PROVIDER'],
                'package' => ['id' => $catalog['package_id'], 'name' => '100 Diamonds'],
                'provider' => ['mapping_id' => $catalog['mapping_id'], 'code' => 'DIGIFLAZZ', 'sku' => 'EML-SKU', 'cost_idr' => 10000, 'max_price_idr' => 12000],
                'pricing' => ['cost_idr' => 10000, 'total_idr' => 11000],
                'customer_input' => ['user_id' => '123456', 'zone_id' => '7890'],
            ], JSON_THROW_ON_ERROR),
            'cost_idr' => 10000,
            'margin_idr' => 1000,
            'discount_idr' => 0,
            'fee_idr' => 0,
            'total_idr' => 11000,
            'idempotency_key' => 'email-moments-renamed-'.bin2hex(random_bytes(6)),
            'paid_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        // Admin mengganti slug kategori voucher SELAGI order
        // berjalan — klasifikasi di snapshot tidak boleh ikut
        // berubah: SN tetap tampil sebagai kode voucher.
        DB::table('categories')->where('slug', 'voucher')->update(['slug' => 'voucher-diubah']);
        $this->seedCheckoutDigiflazzCatalog($catalog['package_id']);
        Http::fake(fn ($request) => Http::response(['data' => [
            'ref_id' => $request->data()['ref_id'],
            'customer_no' => $request->data()['customer_no'],
            'buyer_sku_code' => $request->data()['buyer_sku_code'],
            'message' => 'Sukses',
            'status' => 'Sukses',
            'rc' => '00',
            'sn' => 'PB-VOUCHER-888',
            'price' => 10000,
        ]]));

        $service = app(FulfillmentService::class);
        $service->startOrder($orderId);
        $attempt = DB::table('fulfillment_attempts')->where('order_id', $orderId)->first();
        $service->sendAttempt((int) $attempt->id);

        $this->assertSame('SUCCESS', DB::table('orders')->where('id', $orderId)->value('status'));
        Queue::assertPushed(SendTransactionalEmailJob::class, function (SendTransactionalEmailJob $job): bool {
            return $job->recipient === 'moments-renamed@example.test'
                && str_contains($job->text, 'Kode voucher: PB-VOUCHER-888')
                && ! str_contains($job->text, 'No. Referensi Pengiriman');
        });
    }
}
