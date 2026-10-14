<?php

namespace Tests\Feature;

use App\Jobs\SendTransactionalEmailJob;
use App\Services\FulfillmentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class FulfillmentAdminNotificationsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function makeOrder(string $email, string $status = 'PAID'): array
    {
        $key = 'D53-'.uniqid();
        $packageId = DB::table('packages')->insertGetId([
            'name' => 'Paket '.$key,
            'slug' => 'paket-'.strtolower($key),
            'category_id' => DB::table('categories')->insertGetId([
                'name' => 'Kategori '.$key,
                'slug' => 'kategori-'.strtolower($key),
            ]),
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $mappingId = DB::table('product_mappings')->insertGetId([
            'package_id' => $packageId,
            'provider_code' => 'DIGIFLAZZ',
            'external_sku' => 'D53-SKU-'.$key,
            'is_active' => 1,
            'priority' => 1,
            'fulfillment_config' => json_encode(['customer_no_template' => '{{user_id}}{{zone_id}}']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('digiflazz_catalog_items')->insert([
            'buyer_sku_code' => 'D53-SKU-'.$key,
            'product_name' => 'Paket '.$key,
            'category' => 'Games',
            'brand' => 'GameX',
            'type' => 'Umum',
            'seller_name' => 'SellerX',
            'seller_rating' => 4.9,
            'price' => 12000,
            'buyer_product_status' => true,
            'seller_product_status' => true,
            'unlimited_stock' => true,
            'is_present' => 1,
            'last_synced_at' => now(),
        ]);
        $orderId = DB::table('orders')->insertGetId([
            'order_number' => 'LF-'.strtoupper($key),
            'invoice' => 'INV-'.strtoupper($key),
            'user_id' => null,
            'guest_email' => $email,
            'guest_phone' => '081234567890',
            'status' => $status,
            'subtotal_idr' => 11000,
            'fee_idr' => 78,
            'total_idr' => 11078,
            'payment_channel' => 'qris',
            'provider_mapping_id' => $mappingId,
            'snapshot' => json_encode([
                'product' => [
                    'name' => 'Paket '.$key,
                    'package_id' => $packageId,
                    'fulfillment_mode' => 'AUTO_PROVIDER',
                ],
                'package' => [
                    'id' => $packageId,
                    'name' => 'Paket '.$key,
                    'is_active' => true,
                ],
                'provider' => [
                    'mapping_id' => $mappingId,
                    'cost' => 11000,
                    'max_price' => 12000,
                ],
                'pricing' => [
                    'subtotal' => 11000,
                    'fee' => 78,
                    'total' => 11078,
                ],
                'payment' => [
                    'channel' => 'qris',
                    'channel_name' => 'QRIS',
                ],
                'customer_input' => [
                    'user_id' => '123456',
                    'zone_id' => '9876',
                ],
            ]),
            'expires_at' => now()->addHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['order_id' => $orderId, 'mapping_id' => $mappingId];
    }

    public function test_mark_blocked_records_admin_notification_and_returns_order_to_paid(): void
    {
        ['order_id' => $orderId] = $this->makeOrder('blocked53@example.test');

        $service = app(FulfillmentService::class);
        $attemptId = $service->startOrder($orderId);
        $this->assertNotNull($attemptId);
        $this->assertSame('PROCESSING', DB::table('orders')->where('id', $orderId)->value('status'));

        // Tanpa kredensial Digiflazz, transact melempar ValidationException -> markBlocked.
        $service->sendAttempt((int) $attemptId);

        $this->assertSame('BLOCKED', DB::table('fulfillment_attempts')->where('id', $attemptId)->value('status'));
        $this->assertSame('PAID', DB::table('orders')->where('id', $orderId)->value('status'));
        $this->assertDatabaseHas('admin_notifications', [
            'event_type' => 'fulfillment.blocked',
            'target_type' => 'order',
            'target_id' => (string) $orderId,
        ]);
        $this->assertDatabaseHas('order_events', [
            'order_id' => $orderId,
            'event_type' => 'FULFILLMENT_BLOCKED',
        ]);
        // D5.3 sisi admin saja: tidak ada email ke customer.
        Queue::assertNotPushed(SendTransactionalEmailJob::class);
    }

    public function test_reconcile_cap_escalates_exactly_once(): void
    {
        ['order_id' => $orderId, 'mapping_id' => $mappingId] = $this->makeOrder('stall53@example.test');
        $attemptId = DB::table('fulfillment_attempts')->insertGetId([
            'order_id' => $orderId,
            'attempt_no' => 1,
            'provider_mapping_id' => $mappingId,
            'status' => 'PENDING',
            'external_reference' => 'D53-'.uniqid(),
            'correlation_id' => (string) Str::uuid(),
            'request_payload' => json_encode([
                'buyer_sku_code' => 'D53-SKU',
                'customer_no' => '1234569876',
                'ref_id' => 'D53-REF',
                'max_price' => 12000,
            ]),
            'reconcile_checks' => 3,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = app(FulfillmentService::class);
        $service->reconcileAttempt((int) $attemptId);
        $service->reconcileAttempt((int) $attemptId);

        $this->assertSame(1, DB::table('admin_notifications')
            ->where('event_type', 'fulfillment.reconcile.stalled')
            ->where('target_type', 'fulfillment_attempt')
            ->where('target_id', (string) $attemptId)
            ->count());
        $this->assertSame(1, DB::table('order_events')
            ->where('order_id', $orderId)
            ->where('event_type', 'FULFILLMENT_RECONCILE_STALLED')
            ->count());
        // Order tetap PAID/PROCESSING — eskalasi tidak mengubah status order.
        $this->assertSame('PAID', DB::table('orders')->where('id', $orderId)->value('status'));
    }
}
