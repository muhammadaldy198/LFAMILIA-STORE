<?php

namespace Tests\Feature;

use App\Jobs\SendTransactionalEmailJob;
use App\Models\Category;
use App\Models\Product;
use App\Services\FulfillmentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class FulfillmentAdminNotificationsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function makeOrder(string $email, string $status = 'PAID'): array
    {
        $key = 'D53-'.bin2hex(random_bytes(4));
        $category = Category::where('slug', 'game')->firstOrFail();
        DB::table('providers')->where('code', 'DIGIFLAZZ')->update(['is_active' => true]);
        $providerId = DB::table('providers')->where('code', 'DIGIFLAZZ')->value('id');

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Paket '.$key,
            'slug' => 'paket-'.strtolower($key),
            'margin_percent' => 10,
            'fulfillment_mode' => 'AUTO_PROVIDER',
            'is_active' => true,
        ]);
        $product->fields()->create([
            'field_key' => 'user_id', 'label' => 'User ID', 'type' => 'text',
            'is_required' => true, 'sort_order' => 0,
        ]);
        $product->fields()->create([
            'field_key' => 'zone_id', 'label' => 'Zone ID', 'type' => 'text',
            'is_required' => true, 'sort_order' => 1,
        ]);
        $package = $product->packages()->create([
            'code' => 'D53'.strtoupper(bin2hex(random_bytes(3))),
            'name' => 'Paket '.$key,
            'nominal_value' => 100,
            'is_active' => true,
        ]);
        $mappingId = DB::table('provider_mappings')->insertGetId([
            'product_package_id' => $package->id,
            'provider_id' => $providerId,
            'external_sku' => 'D53-SKU-'.$key,
            'cost_idr' => 10000,
            'max_price_idr' => 12000,
            'fulfillment_config' => json_encode(['customer_no_template' => '{{user_id}}{{zone_id}}']),
            'priority' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->seedCheckoutDigiflazzCatalog($package->id);
        $orderId = DB::table('orders')->insertGetId([
            'order_number' => 'LF-'.strtoupper($key),
            'guest_email' => $email,
            'guest_phone' => '081234567890',
            'product_id' => $product->id,
            'product_package_id' => $package->id,
            'provider_mapping_id' => $mappingId,
            'status' => $status,
            'currency' => 'IDR',
            'customer_input' => json_encode(['user_id' => '123456', 'zone_id' => '9876'], JSON_THROW_ON_ERROR),
            'snapshot' => json_encode([
                'product' => [
                    'id' => $product->id,
                    'name' => 'Paket '.$key,
                    'fulfillment_mode' => 'AUTO_PROVIDER',
                ],
                'package' => [
                    'id' => $package->id,
                    'name' => 'Paket '.$key,
                    'is_active' => true,
                ],
                'provider' => [
                    'mapping_id' => $mappingId,
                    'code' => 'DIGIFLAZZ',
                    'sku' => 'D53-SKU-'.$key,
                    'cost_idr' => 10000,
                    'max_price_idr' => 12000,
                ],
                'pricing' => [
                    'cost_idr' => 10000,
                    'total_idr' => 11078,
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
            'cost_idr' => 10000,
            'margin_idr' => 1000,
            'discount_idr' => 0,
            'fee_idr' => 78,
            'total_idr' => 11078,
            'idempotency_key' => 'd53-order-'.bin2hex(random_bytes(8)),
            'paid_at' => $status === 'PAID' ? now() : null,
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
