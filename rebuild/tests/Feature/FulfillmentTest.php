<?php

namespace Tests\Feature;

use App\Jobs\ReconcileFulfillmentJob;
use App\Jobs\SendFulfillmentJob;
use App\Jobs\SendTransactionalEmailJob;
use App\Jobs\StartFulfillmentJob;
use App\Models\Category;
use App\Models\IntegrationCredential;
use App\Models\Product;
use App\Services\DigiflazzAutoSources;
use App\Services\FulfillmentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
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
            'customer_no_template' => $template,
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
            'fulfillment_config' => json_encode(['provider_option' => 'preserve'], JSON_THROW_ON_ERROR),
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
        // A paid-order fixture must contain the fresh supplier rows that a
        // real successful checkout had already verified, including backups.
        $sources = DB::table('provider_mappings')->where('product_package_id', $catalog['package_id'])
            ->where('provider_id', DB::table('providers')->where('code', 'DIGIFLAZZ')->value('id'))
            ->whereNotNull('external_sku')->get();
        foreach ($sources as $source) {
            if (DB::table('digiflazz_catalog_items')->where('buyer_sku_code', $source->external_sku)->exists()) {
                continue;
            }
            DB::table('digiflazz_catalog_items')->insert([
                'buyer_sku_code' => $source->external_sku, 'product_name' => 'Fixture '.$source->external_sku,
                'category' => 'Games', 'brand' => 'Fixture', 'type' => 'Umum',
                'seller_name' => 'Fixture Seller', 'price_idr' => (int) $source->cost_idr,
                'baseline_price_idr' => (int) $source->cost_idr,
                'buyer_active' => true, 'seller_active' => true,
                'unlimited_stock' => true, 'stock' => 0, 'is_present' => true,
                'start_cut_off' => '00:00', 'end_cut_off' => '00:00',
                'synced_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $customerNoTemplate = $catalog['product']->customer_no_template;
        if ($customerNoTemplate === null) {
            $mappingConfig = is_string($mapping->fulfillment_config)
                ? (json_decode($mapping->fulfillment_config, true) ?: [])
                : (array) $mapping->fulfillment_config;
            $customerNoTemplate = $mappingConfig['customer_no_template'] ?? null;
        }
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
                'customer_no_template' => $customerNoTemplate,
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
            $this->assertArrayNotHasKey('max_price', $payload);
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
        Queue::assertNotPushed(SendTransactionalEmailJob::class, fn (SendTransactionalEmailJob $job): bool => str_contains($job->subject, 'gagal diproses'));
        Http::assertSentCount(1);
    }

    public function test_legacy_manual_mapping_lock_does_not_block_snapshot_authorized_seller_price(): void
    {
        Queue::fake();
        $this->credentials();
        $catalog = $this->automaticCatalog(10000, 10000, sku: 'DF-LEGACY-LOCK');
        $orderId = $this->paidOrder($catalog, 10000);

        // This simulates a pre-migration manually locked mapping. Its stored
        // cost is stale, but the paid order snapshot authorizes Rp10,000.
        DB::table('provider_mappings')->where('id', $catalog['mapping_id'])->update([
            'cost_idr' => 8000, 'store_cost_idr' => 8000,
            'max_price_idr' => 8000, 'is_manual_price' => true,
        ]);
        Http::fake(function ($request) {
            $this->assertSame('DF-LEGACY-LOCK', $request['buyer_sku_code']);
            $this->assertArrayNotHasKey('max_price', $request->data());

            return Http::response(['data' => [
                'ref_id' => $request['ref_id'],
                'customer_no' => $request['customer_no'],
                'buyer_sku_code' => $request['buyer_sku_code'],
                'message' => 'Sukses',
                'status' => 'Sukses',
                'rc' => '00',
                'sn' => 'LEGACY-SNAPSHOT',
                'price' => 10000,
            ]]);
        });

        $service = app(FulfillmentService::class);
        $service->startOrder($orderId);
        $attempt = DB::table('fulfillment_attempts')->where('order_id', $orderId)->firstOrFail();
        $this->assertSame('CREATED', $attempt->status);
        $service->sendAttempt((int) $attempt->id);
        Http::assertSentCount(1);
        $this->assertSame('SUCCESS', DB::table('orders')->where('id', $orderId)->value('status'));
    }

    public function test_old_digiflazz_snapshot_cap_does_not_block_selected_sku(): void
    {
        Queue::fake();
        $catalog = $this->automaticCatalog(10000, 10000, sku: 'DF-LEGACY-SPIKE');
        $orderId = $this->paidOrder($catalog, 9000);
        DB::table('provider_mappings')->where('id', $catalog['mapping_id'])->update([
            'is_manual_price' => true, 'cost_idr' => 8000,
            'max_price_idr' => 8000, 'store_cost_idr' => 8000,
        ]);
        Http::fake();
        app(FulfillmentService::class)->startOrder($orderId);
        $attempt = DB::table('fulfillment_attempts')->where('order_id', $orderId)->firstOrFail();
        $this->assertSame('CREATED', $attempt->status);
        $this->assertSame('PROCESSING', DB::table('orders')->where('id', $orderId)->value('status'));
        Http::assertNothingSent();
    }

    public function test_digiflazz_conflicting_codes_and_timeout_do_not_complete_or_fail_a_paid_order(): void
    {
        Queue::fake();
        $this->credentials();
        $service = app(FulfillmentService::class);

        foreach ([
            ['status' => 'Sukses', 'rc' => '03'],
            ['status' => 'Sukses', 'rc' => ''],
            ['status' => 'Gagal', 'rc' => '01'],
            ['status' => 'Gagal', 'rc' => '70'],
            ['status' => 'Gagal', 'rc' => '00'],
            ['status' => 'Pending', 'rc' => '00'],
        ] as $case => $responseState) {
            $sku = 'DF-CONFLICT-'.$case;
            $catalog = $this->automaticCatalog(sku: $sku);
            $orderId = $this->paidOrder($catalog);

            // Open a real fulfillment attempt without sending to the provider.
            $service->startOrder($orderId);
            $attempt = DB::table('fulfillment_attempts')->where('order_id', $orderId)->firstOrFail();
            $ref = 'FUL-'.DB::table('orders')->where('id', $orderId)->value('order_number').'-1';
            DB::table('fulfillment_attempts')->where('id', $attempt->id)->update([
                'status' => 'PENDING',
                'request_payload' => json_encode([
                    'ref_id' => $ref,
                    'buyer_sku_code' => $sku,
                    'customer_no' => '1234567890',
                    'max_price' => 12000,
                ], JSON_THROW_ON_ERROR),
            ]);

            $service->applyProviderResult((int) $attempt->id, [
                'ref_id' => $ref,
                'buyer_sku_code' => $sku,
                'customer_no' => '1234567890',
                'status' => $responseState['status'],
                'rc' => $responseState['rc'],
                'sn' => 'SHOULD-NOT-DELIVER',
                'price' => 10000,
            ], 'provider_response');

            $this->assertSame('UNKNOWN', DB::table('fulfillment_attempts')
                ->where('id', $attempt->id)->value('status'), json_encode($responseState));
            $this->assertSame('PROCESSING', DB::table('orders')
                ->where('id', $orderId)->value('status'), json_encode($responseState));
            $this->assertNull(DB::table('orders')->where('id', $orderId)->value('delivery_payload'));
            $this->assertSame(1, DB::table('fulfillment_attempts')->where('order_id', $orderId)->count());
        }
    }

    public function test_removed_supplier_sku_blocks_paid_order_and_queued_send(): void
    {
        Queue::fake();
        $this->credentials();
        $catalog = $this->automaticCatalog();
        $orderId = $this->paidOrder($catalog);
        DB::table('digiflazz_catalog_items')->where('buyer_sku_code', 'DF-PRIMARY')->delete();

        Http::fake();
        app(FulfillmentService::class)->startOrder($orderId);
        $attempt = DB::table('fulfillment_attempts')->where('order_id', $orderId)->firstOrFail();
        $this->assertSame('BLOCKED', $attempt->status);
        $this->assertSame('PAID', DB::table('orders')->where('id', $orderId)->value('status'));
        Http::assertNothingSent();

        $second = $this->automaticCatalog(sku: 'DF-DELETED-AFTER-QUEUE');
        $secondOrderId = $this->paidOrder($second);
        $service = app(FulfillmentService::class);
        $service->startOrder($secondOrderId);
        $queued = DB::table('fulfillment_attempts')->where('order_id', $secondOrderId)->firstOrFail();
        $this->assertSame('CREATED', $queued->status);
        DB::table('digiflazz_catalog_items')->where('buyer_sku_code', 'DF-DELETED-AFTER-QUEUE')->delete();
        $service->sendAttempt((int) $queued->id);
        $this->assertSame('BLOCKED', DB::table('fulfillment_attempts')->where('id', $queued->id)->value('status'));
        Http::assertNothingSent();
    }

    public function test_supplier_retagging_of_verified_sku_blocks_paid_order_before_send(): void
    {
        Queue::fake();
        $this->credentials();
        $catalog = $this->automaticCatalog();
        $orderId = $this->paidOrder($catalog);

        $item = DB::table('digiflazz_catalog_items')->where('buyer_sku_code', 'DF-PRIMARY')->first();
        $identity = app(DigiflazzAutoSources::class)->key($item);
        DB::table('provider_mappings')->where('id', $catalog['mapping_id'])->update([
            'fulfillment_config' => json_encode([
                'customer_no_template' => '{{user_id}}{{zone_id}}',
                'auto_source_group' => $identity,
            ], JSON_THROW_ON_ERROR),
        ]);
        DB::table('digiflazz_catalog_items')->where('buyer_sku_code', 'DF-PRIMARY')
            ->update(['brand' => 'Unrelated Supplier Product']);

        Http::fake();
        app(FulfillmentService::class)->startOrder($orderId);
        $attempt = DB::table('fulfillment_attempts')->where('order_id', $orderId)->firstOrFail();
        $this->assertSame('BLOCKED', $attempt->status);
        $this->assertSame('PAID', DB::table('orders')->where('id', $orderId)->value('status'));
        $this->assertDatabaseHas('provider_mappings', [
            'id' => $catalog['mapping_id'], 'is_active' => true,
        ]);
        Http::assertNothingSent();
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

    public function test_confirmed_failure_does_not_switch_to_another_mapping(): void
    {
        Queue::fake();
        $this->credentials();
        $catalog = $this->automaticCatalog();
        $providerId = DB::table('providers')->where('code', 'DIGIFLAZZ')->value('id');
        foreach (['DF-BACKUP', 'DF-THIRD'] as $index => $sku) {
            DB::table('provider_mappings')->insert([
                'product_package_id' => $catalog['package_id'],
                'provider_id' => $providerId,
                'external_sku' => $sku,
                'cost_idr' => 10000,
                'max_price_idr' => 12000,
                'fulfillment_config' => json_encode(['customer_no_template' => '{{user_id}}{{zone_id}}']),
                'priority' => $index + 2,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $orderId = $this->paidOrder($catalog);
        DB::table('orders')->where('id', $orderId)->update(['guest_email' => 'blocked@example.test']);
        Http::fake(function ($request) {
            $payload = $request->data();

            return Http::response(['data' => [
                'ref_id' => $payload['ref_id'],
                'customer_no' => $payload['customer_no'],
                'buyer_sku_code' => $payload['buyer_sku_code'],
                'status' => 'Gagal',
                'rc' => '44',
                'sn' => '',
                'message' => 'Gagal definitif',
                'price' => 10000,
            ]]);
        });

        $service = app(FulfillmentService::class);
        $service->startOrder($orderId);
        $attempt = DB::table('fulfillment_attempts')->where('order_id', $orderId)->firstOrFail();
        $this->assertSame('DF-PRIMARY', DB::table('provider_mappings')
            ->where('id', $attempt->provider_mapping_id)->value('external_sku'));
        $service->sendAttempt((int) $attempt->id);

        $this->assertSame('BLOCKED', DB::table('orders')->where('id', $orderId)->value('status'));
        $this->assertSame('FAILED_CONFIRMED', DB::table('fulfillment_attempts')->where('id', $attempt->id)->value('status'));
        $this->assertFalse((bool) DB::table('fulfillment_attempts')->where('id', $attempt->id)->value('safe_to_failover'));
        $this->assertSame(1, DB::table('fulfillment_attempts')->where('order_id', $orderId)->count());
        Queue::assertPushed(SendTransactionalEmailJob::class, fn (SendTransactionalEmailJob $job): bool => $job->recipient === 'blocked@example.test'
            && str_contains($job->subject, 'gagal diproses'));
        Http::assertSentCount(1);
        $service->startOrder($orderId);
        $service->reconcileAttempt((int) $attempt->id);
        Http::assertSentCount(1);
    }

    public function test_late_success_response_cannot_unblock_attempt_quarantined_mid_request(): void
    {
        Queue::fake();
        $this->credentials();
        $catalog = $this->automaticCatalog();
        $orderId = $this->paidOrder($catalog);
        $service = app(FulfillmentService::class);
        $service->startOrder($orderId);
        $attempt = DB::table('fulfillment_attempts')->where('order_id', $orderId)->firstOrFail();

        Http::fake(function ($request) {
            // A worker already sent a Test request; cutover occurs while the
            // HTTP call is in flight, before the worker handles its reply.
            $migration = require database_path('migrations/2026_11_01_000000_remove_nonproduction_integration_profiles.php');
            $migration->up();

            return Http::response(['data' => [
                'ref_id' => $request['ref_id'],
                'buyer_sku_code' => $request['buyer_sku_code'],
                'customer_no' => $request['customer_no'],
                'status' => 'Sukses',
                'rc' => '00',
                'sn' => 'LATE-TEST-RESULT',
                'price' => 10000,
            ]]);
        });

        $service->sendAttempt((int) $attempt->id);

        $this->assertSame('BLOCKED', DB::table('fulfillment_attempts')->where('id', $attempt->id)->value('status'));
        $this->assertSame('BLOCKED', DB::table('orders')->where('id', $orderId)->value('status'));
        $this->assertDatabaseHas('admin_notifications', [
            'event_type' => 'fulfillment.environment.quarantined',
            'target_type' => 'fulfillment_attempt',
            'target_id' => (string) $attempt->id,
        ]);
        Queue::assertNotPushed(SendTransactionalEmailJob::class, fn (SendTransactionalEmailJob $job): bool => str_contains($job->subject, 'berhasil'));
        Http::assertSentCount(1);
    }

    public function test_production_cutover_quarantines_old_digiflazz_attempts_before_scheduler_can_replay(): void
    {
        Queue::fake();
        $this->credentials();
        $catalog = $this->automaticCatalog();
        $orderId = $this->paidOrder($catalog);

        $service = app(FulfillmentService::class);
        $service->startOrder($orderId);
        $attempt = DB::table('fulfillment_attempts')->where('order_id', $orderId)->firstOrFail();
        DB::table('fulfillment_attempts')->where('id', $attempt->id)->update([
            'status' => 'PENDING',
            'updated_at' => now()->subMinutes(10),
        ]);

        $migration = require database_path('migrations/2026_11_01_000000_remove_nonproduction_integration_profiles.php');
        $migration->up();

        $this->assertSame('BLOCKED', DB::table('fulfillment_attempts')
            ->where('id', $attempt->id)->value('status'));
        $this->assertSame('BLOCKED', DB::table('orders')
            ->where('id', $orderId)->value('status'));
        $this->assertDatabaseHas('admin_notifications', [
            'event_type' => 'fulfillment.environment.quarantined',
            'target_type' => 'fulfillment_attempt',
            'target_id' => (string) $attempt->id,
        ]);

        Artisan::call('lfamilia:recover-fulfillment');
        Queue::assertNotPushed(ReconcileFulfillmentJob::class, fn (ReconcileFulfillmentJob $job): bool => $job->attemptId === (int) $attempt->id);

        $this->expectException(ValidationException::class);
        $service->retrySafeAttempt((int) $attempt->id);
    }

    public function test_uncertain_sku_keeps_checking_beyond_three_attempts_without_failover(): void
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
                'status' => 'Pending',
                'rc' => '03',
                'sn' => '',
                'message' => 'Pending',
                'price' => 10000,
            ]]);
        });
        $service = app(FulfillmentService::class);
        $service->startOrder($orderId);
        $attempt = DB::table('fulfillment_attempts')->where('order_id', $orderId)->firstOrFail();
        $service->sendAttempt((int) $attempt->id);
        for ($i = 0; $i < 5; $i++) {
            $service->reconcileAttempt((int) $attempt->id);
        }
        $this->assertSame('PENDING', DB::table('fulfillment_attempts')
            ->where('id', $attempt->id)->value('status'));
        $this->assertSame(5, (int) DB::table('fulfillment_attempts')
            ->where('id', $attempt->id)->value('reconcile_checks'));
        $this->assertSame(1, DB::table('fulfillment_attempts')->where('order_id', $orderId)->count());
        Http::assertSentCount(6);
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

    public function test_legacy_safe_to_failover_attempt_is_not_retried(): void
    {
        Queue::fake();
        $catalog = $this->automaticCatalog();
        $orderId = $this->paidOrder($catalog);
        $attemptId = DB::table('fulfillment_attempts')->insertGetId([
            'order_id' => $orderId,
            'provider_mapping_id' => $catalog['mapping_id'],
            'provider_id' => DB::table('providers')->where('code', 'DIGIFLAZZ')->value('id'),
            'attempt_no' => 1,
            'external_reference' => 'FUL-LEGACY-'.bin2hex(random_bytes(4)),
            'status' => 'FAILED_CONFIRMED',
            'safe_to_failover' => true,
            'correlation_id' => 'legacy-failover-test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(FulfillmentService::class)->retrySafeAttempt($attemptId);

        $this->assertSame('FAILED_CONFIRMED', DB::table('fulfillment_attempts')->where('id', $attemptId)->value('status'));
        $this->assertSame('PAID', DB::table('orders')->where('id', $orderId)->value('status'));
        Queue::assertNotPushed(SendFulfillmentJob::class);
    }

    public function test_confirmed_failure_marks_blocked_without_automatic_failover(): void
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
        $first = DB::table('fulfillment_attempts')->where('order_id', $orderId)->first();
        $service->sendAttempt((int) $first->id);

        // Per PRD §47: No auto-switch to DF-SECOND. Order becomes BLOCKED and attempt remains terminal FAILED_CONFIRMED.
        $this->assertSame(1, DB::table('fulfillment_attempts')->where('order_id', $orderId)->count());
        $this->assertSame('FAILED_CONFIRMED', DB::table('fulfillment_attempts')->where('id', $first->id)->value('status'));
        $this->assertSame('BLOCKED', DB::table('orders')->where('id', $orderId)->value('status'));
        $this->assertFalse((bool) DB::table('fulfillment_attempts')->where('id', $first->id)->value('safe_to_failover'));
    }

    public function test_confirmed_failure_can_send_more_expensive_backup_with_checkout_max_price_snapshot(): void
    {
        $this->markTestSkipped('Per PRD §47, automatic failover to another SKU is disabled.');
    }

    public function test_confirmed_failure_skips_unavailable_backup_before_queuing_next_safe_source(): void
    {
        $this->markTestSkipped('Per PRD §47, automatic failover to another SKU is disabled.');
    }

    public function test_unusable_selected_source_blocks_without_switching_to_another_sku(): void
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
        // A historic second mapping must never be chosen automatically.
        $this->assertCount(1, $attempts);
        $this->assertSame('BLOCKED', $attempts[0]->status);
        $this->assertSame((int) $catalog['mapping_id'], (int) $attempts[0]->provider_mapping_id);
        $this->assertNotSame($secondId, (int) $attempts[0]->provider_mapping_id);
        Http::assertNothingSent();
    }

    public function test_digiflazz_seller_price_rise_no_longer_blocks_selected_sku(): void
    {
        Queue::fake();
        $this->credentials();
        $catalog = $this->automaticCatalog(cost: 10000, maxPrice: 15000);
        $orderId = $this->paidOrder($catalog, snapshotMaxPrice: 10000);
        // The selected SKU remains the same when the seller price increases.
        DB::table('digiflazz_catalog_items')->where('buyer_sku_code', 'DF-PRIMARY')
            ->update(['price_idr' => 11000, 'synced_at' => now()]);

        Http::fake();
        app(FulfillmentService::class)->startOrder($orderId);

        $attempt = DB::table('fulfillment_attempts')->where('order_id', $orderId)->first();
        $this->assertSame('CREATED', $attempt->status);
        $this->assertSame('PROCESSING', DB::table('orders')->where('id', $orderId)->value('status'));
        Http::assertNothingSent();
    }

    public function test_product_customer_template_is_used_instead_of_mapping_template(): void
    {
        Queue::fake();
        $this->credentials();
        $catalog = $this->automaticCatalog(template: null);
        $catalog['product']->update(['customer_no_template' => '{{user_id}}{{zone_id}}']);
        $orderId = $this->paidOrder($catalog);
        $catalog['product']->update(['customer_no_template' => '{{zone_id}}{{user_id}}']);

        Http::fake(['*' => Http::response(['data' => [
            'ref_id' => 'shared-template-ref', 'customer_no' => '1234567890',
            'buyer_sku_code' => 'DF-PRIMARY', 'message' => 'Sukses', 'status' => 'Sukses',
            'rc' => '00', 'sn' => 'TEST', 'price' => 10000,
        ]])]);

        $service = app(FulfillmentService::class);
        $service->startOrder($orderId);
        $attempt = DB::table('fulfillment_attempts')->where('order_id', $orderId)->first();
        $service->sendAttempt((int) $attempt->id);

        Http::assertSent(fn ($request): bool => ($request['customer_no'] ?? null) === '1234567890'
            && ($request['buyer_sku_code'] ?? null) === 'DF-PRIMARY');
    }

    public function test_explicitly_cleared_template_snapshot_does_not_fall_back_to_mapping_template(): void
    {
        Queue::fake();
        $this->credentials();
        $catalog = $this->automaticCatalog(template: '{{user_id}}{{zone_id}}');
        $orderId = $this->paidOrder($catalog);
        $snapshot = json_decode((string) DB::table('orders')->where('id', $orderId)->value('snapshot'), true);
        $snapshot['provider']['customer_no_template'] = null;
        DB::table('orders')->where('id', $orderId)->update(['snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR)]);
        DB::table('provider_mappings')->where('id', $catalog['mapping_id'])->update([
            'fulfillment_config' => json_encode(['customer_no_template' => '{{zone_id}}{{user_id}}']),
        ]);

        Http::fake();
        $service = app(FulfillmentService::class);
        $service->startOrder($orderId);
        $attempt = DB::table('fulfillment_attempts')->where('order_id', $orderId)->first();
        $service->sendAttempt((int) $attempt->id);

        $this->assertSame('BLOCKED', DB::table('fulfillment_attempts')->where('id', $attempt->id)->value('status'));
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

    public function test_missing_optional_destination_value_never_rewrites_paid_snapshot(): void
    {
        Queue::fake();
        $this->credentials();
        $catalog = $this->automaticCatalog(template: '{{user_id}}-{{zone_id}}');
        $orderId = $this->paidOrder($catalog);
        // Optional checkout fields are intentionally absent in saved input.
        // The paid snapshot still requires zone_id in its delivery format.
        DB::table('orders')->where('id', $orderId)->update([
            'customer_input' => json_encode(['user_id' => '123456'], JSON_THROW_ON_ERROR),
        ]);

        Http::fake();
        $service = app(FulfillmentService::class);
        $service->startOrder($orderId);
        $attempt = DB::table('fulfillment_attempts')->where('order_id', $orderId)->firstOrFail();
        $service->sendAttempt((int) $attempt->id);

        $this->assertSame('BLOCKED', DB::table('fulfillment_attempts')
            ->where('id', $attempt->id)->value('status'));
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

    public function test_reconciliation_accepts_lowercase_sku_and_marks_success(): void
    {
        Queue::fake();
        $this->credentials();
        $catalog = $this->automaticCatalog(sku: 'ML5_A');
        $orderId = $this->paidOrder($catalog);
        $calls = 0;
        Http::fake(function ($request) use (&$calls) {
            $calls++;
            if ($calls === 1) {
                return Http::response(['error' => 'provider timeout'], 503);
            }

            return Http::response(['data' => [
                'ref_id' => $request['ref_id'],
                'buyer_sku_code' => 'ml5_a',
                'customer_no' => $request['customer_no'],
                'status' => 'Sukses',
                'rc' => '00',
                'sn' => 'DELIVERED',
                'price' => 10000,
            ]]);
        });

        $service = app(FulfillmentService::class);
        $service->startOrder($orderId);
        $id = (int) DB::table('fulfillment_attempts')->where('order_id', $orderId)->value('id');
        $service->sendAttempt($id);
        $this->assertSame('UNKNOWN', DB::table('fulfillment_attempts')->where('id', $id)->value('status'));
        $service->reconcileAttempt($id);

        $this->assertSame('SUCCESS', DB::table('fulfillment_attempts')->where('id', $id)->value('status'));
        $this->assertSame('SUCCESS', DB::table('orders')->where('id', $orderId)->value('status'));
        $this->assertSame(1, DB::table('fulfillment_attempts')->where('order_id', $orderId)->count());
        Http::assertSentCount(2);
    }

    public function test_reconciliation_accepts_lowercase_sku_failure_rc02_without_failover(): void
    {
        Queue::fake();
        $this->credentials();
        $catalog = $this->automaticCatalog(sku: 'ML5_A');
        $orderId = $this->paidOrder($catalog);
        $calls = 0;
        Http::fake(function ($request) use (&$calls) {
            $calls++;
            if ($calls === 1) {
                return Http::response(['error' => 'temporary'], 503);
            }

            return Http::response(['data' => [
                'ref_id' => $request['ref_id'],
                'buyer_sku_code' => 'ml5_a',
                'customer_no' => $request['customer_no'],
                'status' => 'Gagal',
                'rc' => '02',
                'message' => 'Transaksi Gagal',
                'price' => 10000,
            ]]);
        });
        $service = app(FulfillmentService::class);
        $service->startOrder($orderId);
        $id = (int) DB::table('fulfillment_attempts')->where('order_id', $orderId)->value('id');
        $service->sendAttempt($id);
        Queue::fake(); // Ignore the original enqueue; only reconciliation's actions count.
        $service->reconcileAttempt($id);

        $this->assertDatabaseHas('fulfillment_attempts', [
            'id' => $id, 'status' => 'FAILED_CONFIRMED', 'provider_rc' => '02',
        ]);
        $this->assertSame('BLOCKED', DB::table('orders')->where('id', $orderId)->value('status'));
        $this->assertSame(1, DB::table('fulfillment_attempts')->where('order_id', $orderId)->count());
        Queue::assertNotPushed(SendFulfillmentJob::class);
        Http::assertSentCount(2);
    }

    public function test_reconciliation_still_rejects_changed_ref_id_or_customer_no(): void
    {
        Queue::fake();
        $this->credentials();
        foreach (['ref_id', 'customer_no'] as $field) {
            Http::swap(new Factory);
            $catalog = $this->automaticCatalog(sku: 'ML5_A-'.strtoupper($field));
            $orderId = $this->paidOrder($catalog);
            $calls = 0;
            Http::fake(function ($request) use ($field, &$calls) {
                $calls++;
                if ($calls === 1) {
                    return Http::response(['error' => 'temporary'], 503);
                }
                $response = [
                    'ref_id' => $request['ref_id'],
                    'buyer_sku_code' => strtolower($request['buyer_sku_code']),
                    'customer_no' => $request['customer_no'],
                    'status' => 'Sukses',
                    'rc' => '00',
                    'price' => 10000,
                ];
                $response[$field] .= '-TAMPERED';

                return Http::response(['data' => $response]);
            });
            $service = app(FulfillmentService::class);
            $service->startOrder($orderId);
            $id = (int) DB::table('fulfillment_attempts')->where('order_id', $orderId)->value('id');
            $service->sendAttempt($id);
            $service->reconcileAttempt($id);

            $this->assertSame('UNKNOWN', DB::table('fulfillment_attempts')->where('id', $id)->value('status'));
            $this->assertSame('PROCESSING', DB::table('orders')->where('id', $orderId)->value('status'));
            Http::assertSentCount(2);
        }
    }

    private function quarantinedPaidOrder(): array
    {
        $this->credentials();
        $catalog = $this->automaticCatalog(sku: 'ML5_A');
        $orderId = $this->paidOrder($catalog);
        $service = app(FulfillmentService::class);
        $service->startOrder($orderId);
        $legacy = DB::table('fulfillment_attempts')->where('order_id', $orderId)->firstOrFail();
        DB::table('fulfillment_attempts')->where('id', $legacy->id)->update([
            'status' => 'BLOCKED',
            'last_error' => 'LEGACY_ENV_QUARANTINED: Asal environment belum bisa diverifikasi.',
        ]);
        DB::table('orders')->where('id', $orderId)->update(['status' => 'BLOCKED']);
        Queue::fake(); // The legacy enqueue precedes the behavior under test.

        return [$orderId, $legacy];
    }

    public function test_superadmin_can_create_one_new_attempt_from_documented_failed_quarantine(): void
    {
        Queue::fake();
        [$orderId, $legacy] = $this->quarantinedPaidOrder();
        $admin = $this->createAdmin([
            'name' => 'Quarantine supervisor',
            'email' => bin2hex(random_bytes(8)).'@example.test',
            'password' => bcrypt('test-password'),
            'role' => 'SUPER_ADMIN',
            'is_active' => true,
        ]);
        $this->actingAs($admin, 'admin');
        $this->get('/admin/fulfillment?scope=action')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Fulfillment')
                ->where('attempts.data.0.can_resolve_quarantine', true)
                ->where('attempts.data.0.resolution_destination', '1234567890')
            );

        $this->withSession(['admin.password_confirmed_at' => time()])->post(
            '/admin/fulfillment/'.$legacy->id.'/resolve-quarantine',
            [
                'provider_ref_id' => $legacy->external_reference,
                'provider_status' => 'Gagal',
                'provider_rc' => '02',
                'evidence_reference' => 'Digiflazz history - failed rc 02',
                'note' => 'Diperiksa dalam riwayat transaksi dan sudah final Gagal.',
                'confirmed_customer_no' => '1234567890',
            ]
        )->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('PROCESSING', DB::table('orders')->where('id', $orderId)->value('status'));
        $this->assertDatabaseHas('fulfillment_attempts', [
            'id' => $legacy->id,
            'status' => 'BLOCKED',
            'last_error' => 'LEGACY_ENV_QUARANTINED: Asal environment belum bisa diverifikasi.',
        ]);
        $this->assertSame(2, DB::table('fulfillment_attempts')->where('order_id', $orderId)->count());
        $new = DB::table('fulfillment_attempts')->where('order_id', $orderId)
            ->orderByDesc('id')->firstOrFail();
        $this->assertSame('CREATED', $new->status);
        $this->assertSame(2, (int) $new->attempt_no);
        $this->assertNotSame($legacy->external_reference, $new->external_reference);
        $this->assertSame($legacy->provider_mapping_id, $new->provider_mapping_id);
        $this->assertDatabaseHas('order_events', [
            'order_id' => $orderId,
            'event_type' => 'FULFILLMENT_QUARANTINE_MANUALLY_RESOLVED',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'fulfillment.quarantine.resolved',
            'target_id' => (string) $new->id,
        ]);
        Queue::assertPushed(SendFulfillmentJob::class, fn (SendFulfillmentJob $job): bool => $job->attemptId === (int) $new->id);
        Queue::assertNotPushed(SendFulfillmentJob::class, fn (SendFulfillmentJob $job): bool => $job->attemptId === (int) $legacy->id);

        $this->withSession(['admin.password_confirmed_at' => time()])->post(
            '/admin/fulfillment/'.$legacy->id.'/resolve-quarantine',
            [
                'provider_ref_id' => $legacy->external_reference,
                'provider_status' => 'Gagal',
                'provider_rc' => '02',
                'evidence_reference' => 'Duplicate click',
                'note' => 'Duplicate must fail',
                'confirmed_customer_no' => '1234567890',
            ]
        )->assertSessionHasErrors('fulfillment');
        $this->assertSame(2, DB::table('fulfillment_attempts')->where('order_id', $orderId)->count());
    }

    public function test_quarantine_resolution_rejects_uncertain_evidence_and_wrong_destination(): void
    {
        Queue::fake();
        [$orderId, $legacy] = $this->quarantinedPaidOrder();
        $service = app(FulfillmentService::class);
        $admin = $this->createAdmin([
            'name' => 'Evidence reviewer',
            'email' => bin2hex(random_bytes(8)).'@example.test',
            'password' => bcrypt('test-password'),
            'role' => 'SUPER_ADMIN',
            'is_active' => true,
        ]);
        foreach ([['01', '1234567890'], ['70', '1234567890'], ['02', '9999999999']] as [$rc, $destination]) {
            try {
                $service->resolveLegacyQuarantine(
                    (int) $legacy->id, (int) $admin->id, $legacy->external_reference,
                    $rc, 'Provider evidence', 'Manual check', $destination
                );
                $this->fail('Unverified quarantine resolution must be rejected.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
        $this->assertSame('BLOCKED', DB::table('orders')->where('id', $orderId)->value('status'));
        $this->assertSame(1, DB::table('fulfillment_attempts')->where('order_id', $orderId)->count());
        Queue::assertNotPushed(SendFulfillmentJob::class);
    }

    public function test_quarantine_resolution_requires_superadmin_and_recent_password_confirmation(): void
    {
        Queue::fake();
        [$orderId, $legacy] = $this->quarantinedPaidOrder();
        $admin = $this->createAdmin([
            'name' => 'Fulfillment staff',
            'email' => bin2hex(random_bytes(8)).'@example.test',
            'password' => bcrypt('test-password'),
            'role' => 'ADMIN',
            'permissions' => ['fulfillment.manage'],
            'is_active' => true,
        ]);
        $payload = [
            'provider_ref_id' => $legacy->external_reference,
            'provider_status' => 'Gagal',
            'provider_rc' => '02',
            'evidence_reference' => 'Digiflazz history',
            'note' => 'Failed transaction.',
            'confirmed_customer_no' => '1234567890',
        ];
        $this->actingAs($admin, 'admin');
        $this->withSession(['admin.password_confirmed_at' => time()])
            ->post('/admin/fulfillment/'.$legacy->id.'/resolve-quarantine', $payload)
            ->assertForbidden();

        $super = $this->createAdmin([
            'name' => 'Supervisor',
            'email' => bin2hex(random_bytes(8)).'@example.test',
            'password' => bcrypt('test-password'),
            'role' => 'SUPER_ADMIN',
            'is_active' => true,
        ]);
        $this->actingAs($super, 'admin');
        $this->withSession(['admin.password_confirmed_at' => 0])
            ->post('/admin/fulfillment/'.$legacy->id.'/resolve-quarantine', $payload)
            ->assertRedirect(route('admin.password.confirm'));

        $this->assertSame('BLOCKED', DB::table('orders')->where('id', $orderId)->value('status'));
        Queue::assertNotPushed(SendFulfillmentJob::class);
    }

    public function test_quarantine_confirmation_freezes_exact_customer_no_after_template_changes(): void
    {
        Queue::fake();
        [$orderId, $legacy] = $this->quarantinedPaidOrder();
        $order = DB::table('orders')->where('id', $orderId)->firstOrFail();
        $snapshot = json_decode((string) $order->snapshot, true);
        unset($snapshot['provider']['customer_no_template']);
        DB::table('orders')->where('id', $orderId)->update([
            'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
        ]);
        DB::table('products')->where('id', $order->product_id)->update([
            'customer_no_template' => 'ID-{{user_id}}',
        ]);
        $admin = $this->createAdmin([
            'name' => 'Frozen target reviewer',
            'email' => bin2hex(random_bytes(8)).'@example.test',
            'password' => bcrypt('test-password'),
            'role' => 'SUPER_ADMIN',
            'is_active' => true,
        ]);

        $service = app(FulfillmentService::class);
        $newId = $service->resolveLegacyQuarantine(
            (int) $legacy->id, (int) $admin->id, $legacy->external_reference,
            '02', 'Digiflazz historical final failure', 'Checked original reference',
            'ID-123456'
        );
        $new = DB::table('fulfillment_attempts')->where('id', $newId)->firstOrFail();
        $request = json_decode((string) $new->request_payload, true);
        $this->assertSame('ID-123456', $request['customer_no']);
        $this->assertSame($new->external_reference, $request['ref_id']);

        // Between approval and the worker send, an admin edits the product template.
        DB::table('products')->where('id', $order->product_id)->update([
            'customer_no_template' => 'CHANGED-{{zone_id}}',
        ]);
        Http::fake(function ($httpRequest) use ($request) {
            $this->assertSame($request['customer_no'], $httpRequest['customer_no']);
            $this->assertSame($request['ref_id'], $httpRequest['ref_id']);

            return Http::response(['data' => [
                'ref_id' => $httpRequest['ref_id'],
                'buyer_sku_code' => strtolower($httpRequest['buyer_sku_code']),
                'customer_no' => $httpRequest['customer_no'],
                'status' => 'Pending',
                'rc' => '03',
                'price' => 10000,
            ]]);
        });
        $service->sendAttempt($newId);

        Http::assertSentCount(1);
        $this->assertSame('PENDING', DB::table('fulfillment_attempts')->where('id', $newId)->value('status'));
        $this->assertSame('BLOCKED', DB::table('fulfillment_attempts')->where('id', $legacy->id)->value('status'));
    }
}
