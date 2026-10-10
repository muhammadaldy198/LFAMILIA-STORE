<?php

namespace Tests\Feature;

use App\Jobs\ReconcileFulfillmentJob;
use App\Jobs\SendFulfillmentJob;
use App\Jobs\StartFulfillmentJob;
use App\Models\Category;
use App\Models\IntegrationCredential;
use App\Models\Product;
use App\Services\CheckoutPricing;
use App\Services\FulfillmentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FulfillmentTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @return array{product:Product,package_id:int,mapping_id:int}
     */
    private function automaticCatalog(
        int $cost = 10000,
        int $maxPrice = 12000,
        ?string $template = '{{user_id}}{{zone_id}}',
        string $sku = 'DF-PRIMARY',
    ): array {
        $category = Category::where('slug', 'game')->firstOrFail();
        DB::table('providers')->where('code', 'DIGIFLAZZ')->update(['is_active' => true]);
        $providerId = DB::table('providers')->where('code', 'DIGIFLAZZ')->value('id');

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Fulfillment Game '.bin2hex(random_bytes(3)),
            'slug' => 'fulfillment-game-'.bin2hex(random_bytes(4)),
            'margin_percent' => 10,
            'fulfillment_mode' => 'AUTO_PROVIDER',
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
            'is_required' => true,
            'sort_order' => 1,
        ]);
        $package = $product->packages()->create([
            'code' => 'FUL'.bin2hex(random_bytes(3)),
            'name' => '100 Diamonds',
            'nominal_value' => 100,
            'is_active' => true,
        ]);
        $mappingId = DB::table('provider_mappings')->insertGetId([
            'product_package_id' => $package->id,
            'provider_id' => $providerId,
            'external_sku' => $sku,
            'cost_idr' => $cost,
            'max_price_idr' => $maxPrice,
            'fulfillment_config' => $template === null ? null : json_encode([
                'customer_no_template' => $template,
            ], JSON_THROW_ON_ERROR),
            'priority' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['product' => $product, 'package_id' => $package->id, 'mapping_id' => $mappingId];
    }

    private function paidOrder(array $catalog, ?int $snapshotMaxPrice = null): int
    {
        $mapping = DB::table('provider_mappings')->where('id', $catalog['mapping_id'])->first();
        $snapshot = [
            'product' => [
                'id' => $catalog['product']->id,
                'name' => $catalog['product']->name,
                'fulfillment_mode' => $catalog['product']->fulfillment_mode,
            ],
            'package' => ['id' => $catalog['package_id'], 'name' => '100 Diamonds'],
            'provider' => [
                'mapping_id' => $catalog['mapping_id'],
                'code' => 'DIGIFLAZZ',
                'sku' => $mapping->external_sku,
                'cost_idr' => (int) $mapping->cost_idr,
                'max_price_idr' => $snapshotMaxPrice ?? (int) $mapping->max_price_idr,
            ],
            'pricing' => ['cost_idr' => (int) $mapping->cost_idr, 'total_idr' => 11000],
            'customer_input' => ['user_id' => '123456', 'zone_id' => '7890'],
        ];

        return DB::table('orders')->insertGetId([
            'order_number' => 'ORD-FUL-'.bin2hex(random_bytes(5)),
            'product_id' => $catalog['product']->id,
            'product_package_id' => $catalog['package_id'],
            'provider_mapping_id' => $catalog['mapping_id'],
            'status' => 'PAID',
            'currency' => 'IDR',
            'customer_input' => json_encode($snapshot['customer_input'], JSON_THROW_ON_ERROR),
            'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
            'cost_idr' => (int) $mapping->cost_idr,
            'margin_idr' => 1000,
            'discount_idr' => 0,
            'fee_idr' => 0,
            'total_idr' => 11000,
            'idempotency_key' => 'fulfillment-order-'.bin2hex(random_bytes(8)),
            'paid_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function credentials(): void
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
    }

    public function test_digiflazz_success_uses_server_snapshot_and_creates_only_one_attempt(): void
    {
        Queue::fake();
        $this->credentials();
        $catalog = $this->automaticCatalog();
        $orderId = $this->paidOrder($catalog);

        Http::fake(function ($request) {
            $payload = $request->data();
            $this->assertSame('buyer-test', $payload['username']);
            $this->assertSame('DF-PRIMARY', $payload['buyer_sku_code']);
            $this->assertSame('1234567890', $payload['customer_no']);
            $this->assertSame(12000, $payload['max_price']);
            $this->assertTrue($payload['testing']);
            $this->assertSame(
                md5('buyer-test'.'api-secret'.$payload['ref_id']),
                $payload['sign']
            );

            return Http::response(['data' => [
                'ref_id' => $payload['ref_id'],
                'customer_no' => $payload['customer_no'],
                'buyer_sku_code' => $payload['buyer_sku_code'],
                'message' => 'Sukses',
                'status' => 'Sukses',
                'rc' => '00',
                'sn' => 'SERIAL-123',
                'price' => 10000,
            ]]);
        });

        $service = app(FulfillmentService::class);
        $service->startOrder($orderId);
        Queue::assertPushed(SendFulfillmentJob::class);

        $attempt = DB::table('fulfillment_attempts')->where('order_id', $orderId)->first();
        $service->sendAttempt((int) $attempt->id);
        $service->startOrder($orderId);

        $this->assertSame(1, DB::table('fulfillment_attempts')->where('order_id', $orderId)->count());
        $this->assertSame('SUCCESS', DB::table('orders')->where('id', $orderId)->value('status'));
        $this->assertSame('SUCCESS', DB::table('fulfillment_attempts')->where('id', $attempt->id)->value('status'));
        $delivery = json_decode(DB::table('orders')->where('id', $orderId)->value('delivery_payload'), true);
        $this->assertSame('SERIAL-123', $delivery['serial_number']);
        Http::assertSentCount(1);
    }

    public function test_timeout_becomes_unknown_and_reconciliation_reuses_same_reference(): void
    {
        Queue::fake();
        $this->credentials();
        $catalog = $this->automaticCatalog();
        $orderId = $this->paidOrder($catalog);

        $calls = 0;
        Http::fake(function ($request) use (&$calls) {
            $calls++;
            $payload = $request->data();
            if ($calls === 1) {
                return Http::response(['error' => 'temporary'], 503);
            }

            return Http::response(['data' => [
                'ref_id' => $payload['ref_id'],
                'customer_no' => $payload['customer_no'],
                'buyer_sku_code' => $payload['buyer_sku_code'],
                'message' => 'Sukses',
                'status' => 'Sukses',
                'rc' => '00',
                'sn' => 'RECOVERED',
                'price' => 10000,
            ]]);
        });

        $service = app(FulfillmentService::class);
        $service->startOrder($orderId);
        $attempt = DB::table('fulfillment_attempts')->where('order_id', $orderId)->first();
        $service->sendAttempt((int) $attempt->id);

        $this->assertSame('UNKNOWN', DB::table('fulfillment_attempts')->where('id', $attempt->id)->value('status'));
        $firstRequest = json_decode(DB::table('fulfillment_attempts')->where('id', $attempt->id)->value('request_payload'), true);

        $service->reconcileAttempt((int) $attempt->id);

        $this->assertSame('SUCCESS', DB::table('orders')->where('id', $orderId)->value('status'));
        $this->assertSame(1, DB::table('fulfillment_attempts')->where('order_id', $orderId)->count());
        $recorded = Http::recorded();
        $this->assertSame($recorded[0][0]->data()['ref_id'], $recorded[1][0]->data()['ref_id']);
        $this->assertSame($firstRequest['ref_id'], $recorded[1][0]->data()['ref_id']);
    }

    public function test_pending_never_failovers_before_confirmed_failure(): void
    {
        Queue::fake();
        $this->credentials();
        $catalog = $this->automaticCatalog();
        $providerId = DB::table('providers')->where('code', 'DIGIFLAZZ')->value('id');
        DB::table('provider_mappings')->insert([
            'product_package_id' => $catalog['package_id'],
            'provider_id' => $providerId,
            'external_sku' => 'DF-SECOND',
            'cost_idr' => 10500,
            'max_price_idr' => 12000,
            'fulfillment_config' => json_encode(['customer_no_template' => '{{user_id}}{{zone_id}}']),
            'priority' => 2,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $orderId = $this->paidOrder($catalog);

        Http::fake(function ($request) {
            $payload = $request->data();

            return Http::response(['data' => [
                'ref_id' => $payload['ref_id'],
                'customer_no' => $payload['customer_no'],
                'buyer_sku_code' => $payload['buyer_sku_code'],
                'message' => 'Transaksi Pending',
                'status' => 'Pending',
                'rc' => '03',
                'sn' => '',
                'price' => 10000,
            ]]);
        });

        $service = app(FulfillmentService::class);
        $service->startOrder($orderId);
        $attempt = DB::table('fulfillment_attempts')->where('order_id', $orderId)->first();
        $service->sendAttempt((int) $attempt->id);
        $service->startOrder($orderId);

        $this->assertSame('PENDING', DB::table('fulfillment_attempts')->where('id', $attempt->id)->value('status'));
        $this->assertFalse((bool) DB::table('fulfillment_attempts')->where('id', $attempt->id)->value('safe_to_failover'));
        $this->assertSame(1, DB::table('fulfillment_attempts')->where('order_id', $orderId)->count());
        $this->assertSame('PROCESSING', DB::table('orders')->where('id', $orderId)->value('status'));
    }

    public function test_confirmed_failure_can_failover_to_next_mapping_within_price_guard(): void
    {
        Queue::fake();
        $this->credentials();
        $catalog = $this->automaticCatalog();
        $providerId = DB::table('providers')->where('code', 'DIGIFLAZZ')->value('id');
        $secondId = DB::table('provider_mappings')->insertGetId([
            'product_package_id' => $catalog['package_id'],
            'provider_id' => $providerId,
            'external_sku' => 'DF-SECOND',
            'cost_idr' => 11000,
            'max_price_idr' => 12000,
            'fulfillment_config' => json_encode(['customer_no_template' => '{{user_id}}{{zone_id}}']),
            'priority' => 2,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $orderId = $this->paidOrder($catalog);

        Http::fake(function ($request) {
            $payload = $request->data();
            $success = $payload['buyer_sku_code'] === 'DF-SECOND';

            return Http::response(['data' => [
                'ref_id' => $payload['ref_id'],
                'customer_no' => $payload['customer_no'],
                'buyer_sku_code' => $payload['buyer_sku_code'],
                'message' => $success ? 'Sukses' : 'Gagal',
                'status' => $success ? 'Sukses' : 'Gagal',
                'rc' => $success ? '00' : '44',
                'sn' => $success ? 'FAILOVER-SN' : '',
                'price' => $success ? 11000 : 10000,
            ]]);
        });

        $service = app(FulfillmentService::class);
        $service->startOrder($orderId);
        $first = DB::table('fulfillment_attempts')->where('order_id', $orderId)->first();
        $service->sendAttempt((int) $first->id);

        $second = DB::table('fulfillment_attempts')->where('order_id', $orderId)
            ->where('provider_mapping_id', $secondId)->first();
        $this->assertNotNull($second);
        $this->assertSame('FAILED_CONFIRMED', DB::table('fulfillment_attempts')->where('id', $first->id)->value('status'));
        $this->assertTrue((bool) DB::table('fulfillment_attempts')->where('id', $first->id)->value('safe_to_failover'));

        $service->sendAttempt((int) $second->id);

        $this->assertSame('SUCCESS', DB::table('orders')->where('id', $orderId)->value('status'));
        $this->assertSame(2, DB::table('fulfillment_attempts')->where('order_id', $orderId)->count());
    }

    public function test_confirmed_failure_can_send_more_expensive_backup_with_checkout_max_price_snapshot(): void
    {
        Queue::fake();
        $this->credentials();
        $catalog = $this->automaticCatalog();
        $providerId = DB::table('providers')->where('code', 'DIGIFLAZZ')->value('id');
        $backupId = DB::table('provider_mappings')->insertGetId([
            'product_package_id' => $catalog['package_id'],
            'provider_id' => $providerId,
            'external_sku' => 'DF-EXPENSIVE-BACKUP',
            'cost_idr' => 13000,
            'max_price_idr' => 13000,
            'fulfillment_config' => json_encode(['customer_no_template' => '{{user_id}}{{zone_id}}'], JSON_THROW_ON_ERROR),
            'priority' => 2,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $checkout = app(CheckoutPricing::class)->forPackage($catalog['package_id']);
        $this->assertSame(13130, $checkout['max_price_idr']);
        $this->assertSame(11000, $checkout['subtotal_idr']);

        $orderId = $this->paidOrder($catalog, $checkout['max_price_idr']);
        Http::fake(function ($request) {
            $payload = $request->data();
            $success = $payload['buyer_sku_code'] === 'DF-EXPENSIVE-BACKUP';

            return Http::response(['data' => [
                'ref_id' => $payload['ref_id'],
                'customer_no' => $payload['customer_no'],
                'buyer_sku_code' => $payload['buyer_sku_code'],
                'status' => $success ? 'Sukses' : 'Gagal',
                'message' => $success ? 'Sukses' : 'Gagal definitif',
                'rc' => $success ? '00' : '44',
                'sn' => $success ? 'BACKUP-SN' : '',
                'price' => $success ? 13000 : 10000,
            ]]);
        });

        $service = app(FulfillmentService::class);
        $service->startOrder($orderId);
        $first = DB::table('fulfillment_attempts')->where('order_id', $orderId)->firstOrFail();
        $service->sendAttempt((int) $first->id);

        $backup = DB::table('fulfillment_attempts')->where('order_id', $orderId)
            ->where('provider_mapping_id', $backupId)->first();
        $this->assertNotNull($backup);
        $service->sendAttempt((int) $backup->id);

        $this->assertSame('SUCCESS', DB::table('orders')->where('id', $orderId)->value('status'));
        $this->assertSame(2, DB::table('fulfillment_attempts')->where('order_id', $orderId)->count());
        $this->assertSame(2, count(Http::recorded()));
        foreach (Http::recorded() as [$request]) {
            $this->assertSame(13130, $request->data()['max_price']);
        }
    }

    public function test_confirmed_failure_skips_unavailable_backup_before_queuing_next_safe_source(): void
    {
        Queue::fake();
        $this->credentials();
        $catalog = $this->automaticCatalog();
        $providerId = DB::table('providers')->where('code', 'DIGIFLAZZ')->value('id');

        $unavailableId = DB::table('provider_mappings')->insertGetId([
            'product_package_id' => $catalog['package_id'],
            'provider_id' => $providerId,
            'external_sku' => 'DF-BACKUP-OOS',
            'cost_idr' => 10500,
            'max_price_idr' => 12000,
            'fulfillment_config' => json_encode(['customer_no_template' => '{{user_id}}{{zone_id}}']),
            'priority' => 2,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $safeId = DB::table('provider_mappings')->insertGetId([
            'product_package_id' => $catalog['package_id'],
            'provider_id' => $providerId,
            'external_sku' => 'DF-BACKUP-SAFE',
            'cost_idr' => 11000,
            'max_price_idr' => 12000,
            'fulfillment_config' => json_encode(['customer_no_template' => '{{user_id}}{{zone_id}}']),
            'priority' => 3,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('digiflazz_catalog_items')->insert([
            [
                'buyer_sku_code' => 'DF-BACKUP-OOS',
                'product_name' => 'Backup OOS',
                'category' => 'Games',
                'brand' => 'Test',
                'type' => 'Diamonds',
                'seller_name' => 'Seller OOS',
                'price_idr' => 10500,
                'baseline_price_idr' => 10500,
                'buyer_active' => true,
                'seller_active' => true,
                'unlimited_stock' => false,
                'stock' => 0,
                'start_cut_off' => '00:00',
                'end_cut_off' => '00:00',
                'synced_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'buyer_sku_code' => 'DF-BACKUP-SAFE',
                'product_name' => 'Backup safe',
                'category' => 'Games',
                'brand' => 'Test',
                'type' => 'Diamonds',
                'seller_name' => 'Seller Safe',
                'price_idr' => 11000,
                'baseline_price_idr' => 11000,
                'buyer_active' => true,
                'seller_active' => true,
                'unlimited_stock' => true,
                'stock' => 0,
                'start_cut_off' => '00:00',
                'end_cut_off' => '00:00',
                'synced_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $orderId = $this->paidOrder($catalog);
        Http::fake(function ($request) {
            $payload = $request->data();

            return Http::response(['data' => [
                'ref_id' => $payload['ref_id'],
                'customer_no' => $payload['customer_no'],
                'buyer_sku_code' => $payload['buyer_sku_code'],
                'message' => 'Gagal definitif',
                'status' => 'Gagal',
                'rc' => '44',
                'sn' => '',
                'price' => 10000,
            ]]);
        });

        $service = app(FulfillmentService::class);
        $service->startOrder($orderId);
        $first = DB::table('fulfillment_attempts')->where('order_id', $orderId)->firstOrFail();
        $service->sendAttempt((int) $first->id);

        $this->assertSame(0, DB::table('fulfillment_attempts')
            ->where('order_id', $orderId)
            ->where('provider_mapping_id', $unavailableId)
            ->count());

        $next = DB::table('fulfillment_attempts')
            ->where('order_id', $orderId)
            ->where('provider_mapping_id', $safeId)
            ->first();
        $this->assertNotNull($next);
        $this->assertSame('CREATED', $next->status);
        $this->assertSame(2, (int) $next->attempt_no);
        Queue::assertPushed(SendFulfillmentJob::class, fn ($job) => $job->attemptId === (int) $next->id);
    }

    public function test_unusable_primary_source_is_skipped_before_any_provider_send(): void
    {
        Queue::fake();
        $this->credentials();
        $catalog = $this->automaticCatalog(cost: 10000, maxPrice: 12000);
        $providerId = DB::table('providers')->where('code', 'DIGIFLAZZ')->value('id');
        $secondId = DB::table('provider_mappings')->insertGetId([
            'product_package_id' => $catalog['package_id'],
            'provider_id' => $providerId,
            'external_sku' => 'DF-SAFE-BACKUP',
            'cost_idr' => 11000,
            'max_price_idr' => 12000,
            'fulfillment_config' => json_encode(['customer_no_template' => '{{user_id}}{{zone_id}}']),
            'priority' => 2,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $orderId = $this->paidOrder($catalog);
        DB::table('provider_mappings')->where('id', $catalog['mapping_id'])->update(['is_active' => false]);

        app(FulfillmentService::class)->startOrder($orderId);

        $attempts = DB::table('fulfillment_attempts')->where('order_id', $orderId)
            ->orderBy('attempt_no')->get();
        $this->assertCount(2, $attempts);
        $this->assertSame('BLOCKED', $attempts[0]->status);
        $this->assertSame($secondId, (int) $attempts[1]->provider_mapping_id);
        $this->assertSame('CREATED', $attempts[1]->status);
        Http::assertNothingSent();
    }

    public function test_price_above_order_guard_blocks_before_provider_request(): void
    {
        Queue::fake();
        $this->credentials();
        $catalog = $this->automaticCatalog(cost: 10000, maxPrice: 15000);
        $orderId = $this->paidOrder($catalog, snapshotMaxPrice: 10000);
        DB::table('provider_mappings')->where('id', $catalog['mapping_id'])->update(['cost_idr' => 11000]);

        Http::fake();
        app(FulfillmentService::class)->startOrder($orderId);

        $attempt = DB::table('fulfillment_attempts')->where('order_id', $orderId)->first();
        $this->assertSame('BLOCKED', $attempt->status);
        $this->assertSame('PAID', DB::table('orders')->where('id', $orderId)->value('status'));
        Http::assertNothingSent();
    }

    public function test_missing_customer_no_template_blocks_multi_field_product_before_send(): void
    {
        Queue::fake();
        $this->credentials();
        $catalog = $this->automaticCatalog(template: null);
        $orderId = $this->paidOrder($catalog);

        Http::fake();
        $service = app(FulfillmentService::class);
        $service->startOrder($orderId);
        $attempt = DB::table('fulfillment_attempts')->where('order_id', $orderId)->first();
        $service->sendAttempt((int) $attempt->id);

        $this->assertSame('BLOCKED', DB::table('fulfillment_attempts')->where('id', $attempt->id)->value('status'));
        $this->assertSame('PAID', DB::table('orders')->where('id', $orderId)->value('status'));
        Http::assertNothingSent();
    }

    public function test_signed_digiflazz_webhook_is_idempotent_and_fake_signature_is_rejected(): void
    {
        Queue::fake();
        $this->credentials();
        $catalog = $this->automaticCatalog();
        $orderId = $this->paidOrder($catalog);

        Http::fake(function ($request) {
            $payload = $request->data();

            return Http::response(['data' => [
                'ref_id' => $payload['ref_id'],
                'customer_no' => $payload['customer_no'],
                'buyer_sku_code' => $payload['buyer_sku_code'],
                'message' => 'Transaksi Pending',
                'status' => 'Pending',
                'rc' => '03',
                'sn' => '',
                'price' => 10000,
            ]]);
        });

        $service = app(FulfillmentService::class);
        $service->startOrder($orderId);
        $attempt = DB::table('fulfillment_attempts')->where('order_id', $orderId)->first();
        $service->sendAttempt((int) $attempt->id);
        $requestPayload = json_decode(DB::table('fulfillment_attempts')->where('id', $attempt->id)->value('request_payload'), true);

        $payload = ['data' => [
            'ref_id' => $requestPayload['ref_id'],
            'customer_no' => $requestPayload['customer_no'],
            'buyer_sku_code' => $requestPayload['buyer_sku_code'],
            'message' => 'Sukses',
            'status' => 'Sukses',
            'rc' => '00',
            'sn' => 'WEBHOOK-SN',
            'price' => 10000,
        ]];
        $raw = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $this->call('POST', '/api/fulfillment/digiflazz/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_USER_AGENT' => 'Digiflazz-Hookshot',
            'HTTP_X_DIGIFLAZZ_EVENT' => 'update',
            'HTTP_X_HUB_SIGNATURE' => 'sha1=bad',
        ], $raw)->assertUnauthorized();

        $signature = 'sha1='.hash_hmac('sha1', $raw, 'webhook-secret');
        $headers = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_USER_AGENT' => 'Digiflazz-Hookshot',
            'HTTP_X_DIGIFLAZZ_EVENT' => 'update',
            'HTTP_X_HUB_SIGNATURE' => $signature,
        ];

        $this->call('POST', '/api/fulfillment/digiflazz/webhook', [], [], [], $headers, $raw)
            ->assertOk()->assertJsonPath('status', 'ok');
        $this->call('POST', '/api/fulfillment/digiflazz/webhook', [], [], [], $headers, $raw)
            ->assertOk()->assertJsonPath('status', 'duplicate');

        $this->assertSame('SUCCESS', DB::table('orders')->where('id', $orderId)->value('status'));
        $this->assertSame(1, DB::table('fulfillment_callbacks')->count());
    }

    public function test_manual_fulfillment_moves_paid_order_to_processing_then_success(): void
    {
        Queue::fake();
        $category = Category::where('slug', 'voucher')->firstOrFail();
        DB::table('providers')->where('code', 'MANUAL')->update(['is_active' => true]);
        $providerId = DB::table('providers')->where('code', 'MANUAL')->value('id');

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Manual Voucher '.bin2hex(random_bytes(3)),
            'slug' => 'manual-voucher-'.bin2hex(random_bytes(4)),
            'margin_percent' => 10,
            'fulfillment_mode' => 'MANUAL',
            'manual_instructions' => 'Kirim kode voucher setelah pembayaran terverifikasi.',
            'is_active' => true,
        ]);
        $package = $product->packages()->create([
            'code' => 'MAN'.bin2hex(random_bytes(3)),
            'name' => 'Voucher 10K',
            'nominal_value' => 10000,
            'is_active' => true,
        ]);
        $mappingId = DB::table('provider_mappings')->insertGetId([
            'product_package_id' => $package->id,
            'provider_id' => $providerId,
            'cost_idr' => 8000,
            'priority' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $snapshot = [
            'product' => ['id' => $product->id, 'fulfillment_mode' => 'MANUAL'],
            'package' => ['id' => $package->id],
            'provider' => ['mapping_id' => $mappingId, 'code' => 'MANUAL', 'cost_idr' => 8000],
            'customer_input' => ['email' => 'manual@example.test'],
        ];
        $orderId = DB::table('orders')->insertGetId([
            'order_number' => 'ORD-MAN-'.bin2hex(random_bytes(5)),
            'product_id' => $product->id,
            'product_package_id' => $package->id,
            'provider_mapping_id' => $mappingId,
            'status' => 'PAID',
            'currency' => 'IDR',
            'customer_input' => json_encode($snapshot['customer_input']),
            'snapshot' => json_encode($snapshot),
            'cost_idr' => 8000,
            'margin_idr' => 800,
            'total_idr' => 8800,
            'idempotency_key' => 'manual-order-'.bin2hex(random_bytes(8)),
            'paid_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = app(FulfillmentService::class);
        $service->startOrder($orderId);
        $attempt = DB::table('fulfillment_attempts')->where('order_id', $orderId)->first();

        $this->assertSame('MANUAL_PENDING', $attempt->status);
        $this->assertSame('PROCESSING', DB::table('orders')->where('id', $orderId)->value('status'));

        $service->completeManual((int) $attempt->id, 'VOUCHER-CODE-123', 'Gunakan satu kali.', 1);

        $this->assertSame('SUCCESS', DB::table('orders')->where('id', $orderId)->value('status'));
        $delivery = json_decode(DB::table('orders')->where('id', $orderId)->value('delivery_payload'), true);
        $this->assertSame('VOUCHER-CODE-123', $delivery['code']);

        $this->expectException(ValidationException::class);
        $service->completeManual((int) $attempt->id, 'SECOND', null, 1);
    }

    public function test_recovery_command_queues_paid_and_uncertain_work_without_new_attempts(): void
    {
        Queue::fake();
        $catalog = $this->automaticCatalog();
        $paidOrderId = $this->paidOrder($catalog);

        $catalog2 = $this->automaticCatalog(sku: 'DF-RECOVERY');
        $processingOrderId = $this->paidOrder($catalog2);
        DB::table('orders')->where('id', $processingOrderId)->update(['status' => 'PROCESSING']);
        $providerId = DB::table('providers')->where('code', 'DIGIFLAZZ')->value('id');
        DB::table('fulfillment_attempts')->insert([
            'order_id' => $processingOrderId,
            'provider_mapping_id' => $catalog2['mapping_id'],
            'provider_id' => $providerId,
            'attempt_no' => 1,
            'external_reference' => 'FUL-RECOVERY-1',
            'status' => 'UNKNOWN',
            'correlation_id' => 'recovery-test',
            'request_payload' => json_encode([
                'buyer_sku_code' => 'DF-RECOVERY',
                'customer_no' => '1234567890',
                'ref_id' => 'FUL-RECOVERY-1',
                'max_price' => 12000,
            ]),
            'updated_at' => now()->subMinutes(5),
            'created_at' => now()->subMinutes(5),
        ]);

        $this->artisan('lfamilia:recover-fulfillment')->assertExitCode(0);

        Queue::assertPushed(StartFulfillmentJob::class, fn ($job) => $job->orderId === $paidOrderId);
        Queue::assertPushed(ReconcileFulfillmentJob::class);
        $this->assertSame(1, DB::table('fulfillment_attempts')->where('order_id', $processingOrderId)->count());
    }
}
