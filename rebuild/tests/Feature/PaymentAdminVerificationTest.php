<?php

namespace Tests\Feature;

use App\Jobs\SendTransactionalEmailJob;
use App\Models\Category;
use App\Models\IntegrationCredential;
use App\Models\Product;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Tes verifikasi admin atas pembayaran tidak diketahui:
 * admin memeriksa pembayaran Midtrans yang tidak pasti tanpa
 * membuat pembayaran baru, untuk tiap status akhir, dan
 * keadaan dibiarkan utuh bila gateway tidak terjangkau.
 */
class PaymentAdminVerificationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        Queue::fake([SendTransactionalEmailJob::class]);
    }

    /**
     * @return array{product:Product,package_id:int}
     */
    private function catalog(): array
    {
        $category = Category::where('slug', 'game')->firstOrFail();
        DB::table('providers')->where('code', 'DIGIFLAZZ')->update(['is_active' => true]);
        $providerId = DB::table('providers')->where('code', 'DIGIFLAZZ')->value('id');

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Payment Game '.bin2hex(random_bytes(3)),
            'slug' => 'payment-game-'.bin2hex(random_bytes(4)),
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
        $package = $product->packages()->create([
            'code' => 'PAY'.bin2hex(random_bytes(3)),
            'name' => '100 Diamonds',
            'nominal_value' => 100,
            // Explicit nominal markup: fixtures must not inherit a game price.
            'pricing_mode' => 'PERCENT', 'margin_percent' => 10,
            'is_active' => true,
        ]);
        DB::table('provider_mappings')->insert([
            'product_package_id' => $package->id,
            'provider_id' => $providerId,
            'external_sku' => 'PAY-SKU-'.bin2hex(random_bytes(4)),
            'cost_idr' => 10000,
            'max_price_idr' => 12000,
            'priority' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->seedCheckoutDigiflazzCatalog($package->id);

        return ['product' => $product, 'package_id' => $package->id];
    }

    private function route(
        string $channel,
        string $gateway,
        int $flat = 0,
        int $bps = 0,
        ?string $providerChannel = null,
        ?array $configuration = null,
    ): int {
        DB::table('payment_gateways')->where('code', $gateway)->update([
            'is_active' => true,
            'is_maintenance' => false,
            'updated_at' => now(),
        ]);
        DB::table('payment_channels')->where('code', $channel)->update([
            'is_active' => true,
            'fee_flat_idr' => $flat,
            'fee_percent_bps' => $bps,
            'updated_at' => now(),
        ]);

        $channelId = DB::table('payment_channels')->where('code', $channel)->value('id');
        $gatewayId = DB::table('payment_gateways')->where('code', $gateway)->value('id');

        DB::table('payment_routes')->updateOrInsert(
            ['payment_channel_id' => $channelId, 'payment_gateway_id' => $gatewayId],
            [
                'provider_channel' => $providerChannel,
                'configuration' => $configuration === null ? null : json_encode($configuration, JSON_THROW_ON_ERROR),
                'fee_flat_idr' => $flat,
                'fee_percent_bps' => $bps,
                'priority' => 0,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        return (int) DB::table('payment_routes')
            ->where('payment_channel_id', $channelId)
            ->where('payment_gateway_id', $gatewayId)
            ->value('id');
    }

    /**
     * @return array<string, mixed>
     */
    private function guestCheckout(int $packageId, string $channel, string $key): array
    {
        return [
            'package_id' => $packageId,
            'payment_channel_code' => $channel,
            'customer_input' => ['user_id' => '123456'],
            'voucher_code' => null,
            'guest_email' => 'pay@example.test',
            'guest_phone' => '081234567890',
            'idempotency_key' => $key,
        ];
    }

    private function creatingPaymentRow(
        int $routeId,
        ?int $orderId,
        string $reference,
        int $amount,
        string $gateway = 'MIDTRANS',
        string $channel = 'qris',
        ?int $topupId = null,
        int $staleMinutes = 10
    ): int {
        return DB::table('payment_transactions')->insertGetId([
            'order_id' => $orderId,
            'wallet_topup_id' => $topupId,
            'payment_route_id' => $routeId,
            'gateway_code' => $gateway,
            'channel_code' => $channel,
            'merchant_reference' => $reference,
            'amount_idr' => $amount,
            'status' => 'CREATING',
            'request_fingerprint' => hash('sha256', $reference),
            'idempotency_key' => 'pr4-'.strtolower($reference),
            'created_at' => now()->subMinutes($staleMinutes),
            'updated_at' => now()->subMinutes($staleMinutes),
        ]);
    }

    private function seedMidtransCredential(): void
    {
        IntegrationCredential::updateOrCreate(['code' => 'midtrans'], [
            'config_ciphertext' => ['server_key' => 'server-test', 'is_production' => false],
            'is_active' => true,
        ]);
    }

    #[DataProvider('adminUnknownStatuses')]
    public function test_admin_can_verify_unknown_midtrans_payment_without_creating_another_payment(
        string $gatewayStatus,
        string $expectedOrderStatus
    ): void {
        Queue::fake();
        $catalog = $this->catalog();
        $routeId = $this->route('qris', 'MIDTRANS', 0, 0, 'qris');
        IntegrationCredential::updateOrCreate(['code' => 'midtrans'], [
            'config_ciphertext' => ['server_key' => 'server-test', 'is_production' => false],
            'is_active' => true,
        ]);
        $checkout = $this->postJson('/checkout/orders', $this->guestCheckout(
            $catalog['package_id'], 'qris', 'unknown-admin-order-'.strtolower($gatewayStatus)
        ))->assertCreated();
        $order = DB::table('orders')->where('order_number', $checkout->json('order_number'))->firstOrFail();
        $reference = 'PAY-ADMIN-'.strtoupper($gatewayStatus).'-'.bin2hex(random_bytes(3));
        $paymentId = DB::table('payment_transactions')->insertGetId([
            'order_id' => $order->id,
            'wallet_topup_id' => null,
            'payment_route_id' => $routeId,
            'gateway_code' => 'MIDTRANS',
            'channel_code' => 'qris',
            'merchant_reference' => $reference,
            'amount_idr' => $order->total_idr,
            'status' => 'UNKNOWN',
            'request_fingerprint' => hash('sha256', $reference),
            'idempotency_key' => 'unknown-admin-payment-'.strtolower($gatewayStatus),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Http::fake(function ($request) use ($reference, $order, $gatewayStatus) {
            $this->assertStringContainsString('/v2/'.$reference.'/status', $request->url());

            return Http::response([
                'order_id' => $reference,
                'gross_amount' => (string) $order->total_idr,
                'status_code' => '200',
                'transaction_status' => $gatewayStatus,
            ]);
        });
        $admin = $this->createAdmin([
            'name' => 'Payment Checker',
            'email' => 'payment-check-'.bin2hex(random_bytes(3)).'@example.test',
            'password' => bcrypt('payment-test-password'),
            'role' => 'SUPER_ADMIN',
            'is_active' => true,
        ]);
        $this->actingAs($admin, 'admin')
            ->post('/admin/orders/'.$order->id.'/check-payment')
            ->assertRedirect();
        $this->assertSame($expectedOrderStatus, DB::table('orders')->where('id', $order->id)->value('status'));
        $this->assertSame(1, DB::table('payment_transactions')->where('order_id', $order->id)->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'order.payment.checked')
            ->where('target_id', (string) $order->id)->count());
    }

    public static function adminUnknownStatuses(): array
    {
        return [
            'paid' => ['settlement', 'PAID'],
            'failed' => ['failure', 'FAILED'],
            'expired' => ['expire', 'EXPIRED'],
        ];
    }

    public function test_admin_unknown_gateway_unreachable_leaves_status_unchanged(): void
    {
        Queue::fake();
        $catalog = $this->catalog();
        $routeId = $this->route('qris', 'MIDTRANS', 0, 0, 'qris');
        IntegrationCredential::updateOrCreate(['code' => 'midtrans'], [
            'config_ciphertext' => ['server_key' => 'server-test', 'is_production' => false],
            'is_active' => true,
        ]);
        $checkout = $this->postJson('/checkout/orders', $this->guestCheckout(
            $catalog['package_id'], 'qris', 'unknown-unreachable-order-0001'
        ))->assertCreated();
        $order = DB::table('orders')->where('order_number', $checkout->json('order_number'))->firstOrFail();
        $reference = 'PAY-ADMIN-UNREACHABLE-'.bin2hex(random_bytes(3));
        DB::table('payment_transactions')->insert([
            'order_id' => $order->id,
            'wallet_topup_id' => null,
            'payment_route_id' => $routeId,
            'gateway_code' => 'MIDTRANS',
            'channel_code' => 'qris',
            'merchant_reference' => $reference,
            'amount_idr' => $order->total_idr,
            'status' => 'UNKNOWN',
            'request_fingerprint' => hash('sha256', $reference),
            'idempotency_key' => 'unknown-unreachable-payment-0001',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Http::fake(['*' => Http::response(['error' => 'gateway unavailable'], 503)]);
        $admin = $this->createAdmin([
            'name' => 'Payment Retry Checker',
            'email' => 'payment-retry-'.bin2hex(random_bytes(3)).'@example.test',
            'password' => bcrypt('payment-test-password'),
            'role' => 'SUPER_ADMIN',
            'is_active' => true,
        ]);
        $this->actingAs($admin, 'admin')
            ->post('/admin/orders/'.$order->id.'/check-payment')
            ->assertStatus(503);
        $this->assertSame('UNKNOWN', DB::table('payment_transactions')
            ->where('order_id', $order->id)->value('status'));
        $this->assertSame('PENDING_PAYMENT', DB::table('orders')->where('id', $order->id)->value('status'));
    }

    public function test_admin_check_payment_on_creating_payment_drives_recovery(): void
    {
        Queue::fake();
        $catalog = $this->catalog();
        $routeId = $this->route('qris', 'MIDTRANS', 0, 70, 'qris');
        $this->seedMidtransCredential();
        $checkout = $this->postJson('/checkout/orders', $this->guestCheckout(
            $catalog['package_id'], 'qris', 'pr4-admin-creating-order'
        ))->assertCreated();
        $order = DB::table('orders')->where('order_number', $checkout->json('order_number'))->firstOrFail();
        $reference = 'PAY-PR4-ADMIN-'.strtoupper(bin2hex(random_bytes(3)));
        $paymentId = $this->creatingPaymentRow(
            $routeId, (int) $order->id, $reference, (int) $order->total_idr, staleMinutes: 1
        );

        $creates = 0;
        Http::fake(function ($request) use (&$creates) {
            if (str_contains($request->url(), '/status')) {
                return Http::response([
                    'status_code' => '404',
                    'status_message' => "Transaction doesn't exist.",
                ], 404);
            }
            if (str_contains($request->url(), 'snap/v1/transactions')) {
                $creates++;

                return Http::response(['token' => 'admin-recovered-token', 'redirect_url' => 'https://example.test/admin']);
            }

            return Http::response([], 404);
        });
        $admin = $this->createAdmin([
            'name' => 'Creating Checker',
            'email' => 'creating-check-'.bin2hex(random_bytes(3)).'@example.test',
            'password' => bcrypt('payment-test-password'),
            'role' => 'SUPER_ADMIN',
            'is_active' => true,
        ]);
        $this->actingAs($admin, 'admin')
            ->post('/admin/orders/'.$order->id.'/check-payment')
            ->assertRedirect();

        $this->assertSame(1, $creates);
        $this->assertSame('PENDING', DB::table('payment_transactions')->where('id', $paymentId)->value('status'));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'order.payment.checked')
            ->where('target_id', (string) $order->id)->count());
    }
}
