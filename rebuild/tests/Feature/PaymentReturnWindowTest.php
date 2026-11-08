<?php

namespace Tests\Feature;

use App\Jobs\SendTransactionalEmailJob;
use App\Models\Category;
use App\Models\IntegrationCredential;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Tes masa berlaku URL kembali dari gateway: tautan yang
 * hidup lebih lama dari tenggat pesanan tetapi tetap
 * sementara, jendela tanda tangan minimum lima menit untuk
 * Midtrans, dan parameter gateway pada tautan tamu. Tes
 * tanda tangan dasar ada di PaymentReturnLinkTest.
 */
class PaymentReturnWindowTest extends TestCase
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

    public function test_gateway_return_url_outlives_order_deadline_but_remains_temporary(): void
    {
        // Return signatures must remain valid until the provider checkout
        // window plus a bounded redirect grace period. Otherwise payment
        // might succeed but a logged-out buyer returns to a 403.
        // The signed URL must still expire; email links have their own TTL.
        $catalog = $this->catalog();
        $this->route('qris', 'MIDTRANS', 0, 70, 'qris');
        IntegrationCredential::updateOrCreate(['code' => 'midtrans'], [
            'config_ciphertext' => ['server_key' => 'server-test', 'is_production' => false],
            'is_active' => true,
        ]);
        Http::fake(function ($request) {
            if ($request->url() === 'https://app.sandbox.midtrans.com/snap/v1/transactions') {
                return Http::response(['token' => 'snap-token-test', 'redirect_url' => 'https://sandbox.midtrans.test/pay']);
            }

            return Http::response([], 404);
        });
        $user = User::create([
            'name' => 'Expired Buyer',
            'email' => 'expired-buyer@example.test',
            'phone' => '081234567895',
            'password' => Hash::make('StrongPassword123!'),
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $checkout = $this->actingAs($user)->postJson('/checkout/orders', [
            'package_id' => $catalog['package_id'],
            'payment_channel_code' => 'qris',
            'customer_input' => ['user_id' => '888888'],
            'voucher_code' => null,
            'idempotency_key' => 'expired-order-0001',
        ])->assertCreated();
        $orderNumber = $checkout->json('order_number');
        auth()->logout();
        $this->postJson(
            URL::temporarySignedRoute('payments.orders.create', now()->addMinutes(30), ['orderNumber' => $orderNumber]),
            ['idempotency_key' => 'expired-payment-0001']
        )->assertOk();
        $snap = Http::recorded(fn ($request) => $request->url() === 'https://app.sandbox.midtrans.com/snap/v1/transactions')->first();
        $finishUrl = json_decode($snap[0]->body(), true, 512, JSON_THROW_ON_ERROR)['callbacks']['finish'] ?? null;
        $this->assertIsString($finishUrl);
        $payment = DB::table('payment_transactions')->where('idempotency_key', 'expired-payment-0001')->firstOrFail();
        $withParams = $finishUrl.'&merchant_id=Mxxxxx&order_id='.$payment->merchant_reference
            .'&status_code=200&transaction_status=settlement';

        // After the order deadline, the return link is still usable
        // during provider redirect grace, but not indefinitely.
        Carbon::setTestNow(now()->addMinutes(31));
        try {
            $this->get($withParams)->assertOk();
        } finally {
            Carbon::setTestNow();
        }
        Carbon::setTestNow(now()->addMinutes(36));
        try {
            $this->get($withParams)->assertForbidden();
        } finally {
            Carbon::setTestNow();
        }

        // Link email untuk order kedaluwarsa: jendela 30 menit
        // dari pembuatan (pola payUrl fallback) — dalam jendela
        // terbuka, sesudah jendela 403.
        $emailLink = URL::temporarySignedRoute('payment.show', now()->addMinutes(30), ['invoice' => $orderNumber]);
        $this->get($emailLink)->assertOk();
        Carbon::setTestNow(now()->addMinutes(31));
        try {
            $this->get($emailLink)->assertForbidden();
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_gateway_return_signature_covers_minimum_five_minute_midtrans_window(): void
    {
        $catalog = $this->catalog();
        $this->route('qris', 'MIDTRANS', 0, 70, 'qris');
        IntegrationCredential::updateOrCreate(['code' => 'midtrans'], [
            'config_ciphertext' => ['server_key' => 'server-test', 'is_production' => false],
            'is_active' => true,
        ]);
        Http::fake(function ($request) {
            if ($request->url() === 'https://app.sandbox.midtrans.com/snap/v1/transactions') {
                return Http::response(['token' => 'snap-token-test', 'redirect_url' => 'https://sandbox.midtrans.test/pay']);
            }

            return Http::response([], 404);
        });
        $user = User::create([
            'name' => 'Near Deadline Buyer',
            'email' => 'near-deadline-buyer@example.test',
            'phone' => '081234567895',
            'password' => Hash::make('StrongPassword123!'),
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $checkout = $this->actingAs($user)->postJson('/checkout/orders', [
            'package_id' => $catalog['package_id'],
            'payment_channel_code' => 'qris',
            'customer_input' => ['user_id' => '888888'],
            'idempotency_key' => 'near-deadline-order-0001',
        ])->assertCreated();
        $orderNumber = $checkout->json('order_number');
        DB::table('orders')->where('order_number', $orderNumber)->update([
            'expires_at' => now()->addSeconds(30),
        ]);
        auth()->logout();

        $this->postJson(
            URL::temporarySignedRoute('payments.orders.create', now()->addMinutes(1), ['orderNumber' => $orderNumber]),
            ['idempotency_key' => 'near-deadline-payment-0001']
        )->assertOk();
        $snap = Http::recorded(fn ($request) => $request->url() === 'https://app.sandbox.midtrans.com/snap/v1/transactions')->first();
        $snapPayload = json_decode($snap[0]->body(), true, 512, JSON_THROW_ON_ERROR);
        $finishUrl = $snapPayload['callbacks']['finish'] ?? null;
        $this->assertIsString($finishUrl);
        $this->assertStringContainsString('expires=', $finishUrl);
        $this->assertSame(5, $snapPayload['expiry']['duration']);
        $this->assertSame('minutes', $snapPayload['expiry']['unit']);
        $this->assertSame(5, $snapPayload['page_expiry']['duration']);
        $this->assertSame('minutes', $snapPayload['page_expiry']['unit']);
        $this->assertMatchesRegularExpression(
            '/^\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}:\\d{2} \\+0700$/',
            $snapPayload['expiry']['start_time']
        );

        Carbon::setTestNow(now()->addSeconds(90));
        try {
            $this->get($finishUrl)->assertOk();
        } finally {
            Carbon::setTestNow();
        }

        Carbon::setTestNow(now()->addMinutes(9));
        try {
            $this->get($finishUrl)->assertOk();
        } finally {
            Carbon::setTestNow();
        }

        Carbon::setTestNow(now()->addMinutes(11));
        try {
            $this->get($finishUrl)->assertForbidden();
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_guest_return_url_with_gateway_params_opens_and_unsigned_is_blocked(): void
    {
        // Allowlist berlaku juga untuk jalur guest (bukan hanya
        // member): guest kembali dari gateway dengan param hasil
        // → 200. Tanpa signature DAN tanpa session → tetap 403
        // (guest tidak lolos tanpa session).
        $catalog = $this->catalog();
        $this->route('qris', 'MIDTRANS', 0, 70, 'qris');
        IntegrationCredential::updateOrCreate(['code' => 'midtrans'], [
            'config_ciphertext' => ['server_key' => 'server-test', 'is_production' => false],
            'is_active' => true,
        ]);
        Http::fake(function ($request) {
            if ($request->url() === 'https://app.sandbox.midtrans.com/snap/v1/transactions') {
                return Http::response(['token' => 'snap-token-test', 'redirect_url' => 'https://sandbox.midtrans.test/pay']);
            }

            return Http::response([], 404);
        });
        $checkout = $this->postJson('/checkout/orders', $this->guestCheckout($catalog['package_id'], 'qris', 'guest-params-order-0001'))
            ->assertCreated();
        $orderNumber = $checkout->json('order_number');
        $this->postJson(
            URL::temporarySignedRoute('payments.orders.create', now()->addMinutes(30), ['orderNumber' => $orderNumber]),
            ['idempotency_key' => 'guest-params-payment-0001']
        )->assertOk();
        $snap = Http::recorded(fn ($request) => $request->url() === 'https://app.sandbox.midtrans.com/snap/v1/transactions')->first();
        $finishUrl = json_decode($snap[0]->body(), true, 512, JSON_THROW_ON_ERROR)['callbacks']['finish'] ?? null;
        $this->assertIsString($finishUrl);
        $payment = DB::table('payment_transactions')->where('idempotency_key', 'guest-params-payment-0001')->firstOrFail();

        $this->get($finishUrl.'&merchant_id=Mxxxxx&order_id='.$payment->merchant_reference
            .'&status_code=200&transaction_status=pending')->assertOk();

        // Sesi baru tanpa signature → 403 (tidak ada session guest).
        $this->flushSession();
        $this->get(route('payment.show', ['invoice' => $orderNumber]))->assertForbidden();
    }
}
