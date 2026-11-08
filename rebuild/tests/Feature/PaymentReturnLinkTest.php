<?php

namespace Tests\Feature;

use App\Jobs\SendTransactionalEmailJob;
use App\Models\Category;
use App\Models\IntegrationCredential;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Tes URL kembali dari gateway: tautan bertanda tangan untuk
 * member yang keluar dan tamu, parameter hasil gateway, masa
 * berlaku, dan jendela minimum tanda tangan. Tes tautan status
 * pesanan ada di PaymentStatusLinkTest.
 */
class PaymentReturnLinkTest extends TestCase
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

    public function test_gateway_return_url_is_signed_so_logged_out_member_is_not_blocked_after_paying(): void
    {
        $catalog = $this->catalog();
        $this->route('qris', 'MIDTRANS', 0, 70, 'qris');
        IntegrationCredential::updateOrCreate(['code' => 'midtrans'], [
            'config_ciphertext' => ['server_key' => 'server-test', 'is_production' => false],
            'is_active' => true,
        ]);

        Http::fake(function ($request) {
            if ($request->url() === 'https://app.sandbox.midtrans.com/snap/v1/transactions') {
                return Http::response([
                    'token' => 'snap-token-test',
                    'redirect_url' => 'https://sandbox.midtrans.test/pay',
                ]);
            }

            return Http::response([], 404);
        });

        $user = User::create([
            'name' => 'Return Buyer',
            'email' => 'return-buyer@example.test',
            'phone' => '081234567891',
            'password' => Hash::make('StrongPassword123!'),
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        $checkout = $this->actingAs($user)->postJson('/checkout/orders', [
            'package_id' => $catalog['package_id'],
            'payment_channel_code' => 'qris',
            'customer_input' => ['user_id' => '888888'],
            'voucher_code' => null,
            'idempotency_key' => 'return-order-0001',
        ])->assertCreated();
        $orderNumber = $checkout->json('order_number');

        // Alur nyata: member buka link signed email saat logout,
        // lalu membuat pembayaran lewat URL signed itu.
        auth()->logout();
        $signedCreate = URL::temporarySignedRoute('payments.orders.create', now()->addMinutes(30), ['orderNumber' => $orderNumber]);
        $this->postJson($signedCreate, ['idempotency_key' => 'return-payment-0001'])
            ->assertOk()
            ->assertJsonPath('status', 'PENDING');

        // Gateway (Midtrans Snap) menerima finish_url dari konteks.
        $snap = Http::recorded(fn ($request) => $request->url() === 'https://app.sandbox.midtrans.com/snap/v1/transactions')->first();
        $this->assertNotNull($snap);
        $finishUrl = json_decode($snap[0]->body(), true, 512, JSON_THROW_ON_ERROR)['callbacks']['finish'] ?? null;
        $this->assertIsString($finishUrl);

        // Signature is valid and invoice-bound. Unlike an email link,
        // the provider return URL must outlive the order deadline but
        // remain bounded by the 120-minute provider cap plus five minutes.
        $this->assertTrue(URL::hasValidSignature(Request::create($finishUrl)));
        parse_str((string) parse_url($finishUrl, PHP_URL_QUERY), $query);
        $order = DB::table('orders')->where('order_number', $orderNumber)->firstOrFail();
        $this->assertGreaterThan((int) Carbon::parse($order->expires_at)->timestamp, (int) $query['expires']);
        $this->assertLessThanOrEqual((int) now()->addMinutes(125)->timestamp, (int) $query['expires']);

        // Kembali dari gateway saat logout: BUKAN 403, halaman
        // order terlihat (Inertia 200).
        $this->get($finishUrl)->assertOk();

        // Tanpa signature, order member tetap 403 untuk pengunjung
        // logout — otorisasi tidak dilonggarkan.
        $this->get(route('payment.show', ['invoice' => $orderNumber]))->assertForbidden();

        // Signature terikat ke invoice ini saja: menukar invoice
        // ke order lain merusak signature → 403.
        $checkout2 = $this->actingAs($user)->postJson('/checkout/orders', [
            'package_id' => $catalog['package_id'],
            'payment_channel_code' => 'qris',
            'customer_input' => ['user_id' => '888888'],
            'voucher_code' => null,
            'idempotency_key' => 'return-order-0002',
        ])->assertCreated();
        auth()->logout();
        $tampered = preg_replace(
            '/invoice=[^&]+/',
            'invoice='.$checkout2->json('order_number'),
            $finishUrl
        );
        $this->assertIsString($tampered);
        $this->get($tampered)->assertForbidden();
    }

    public function test_gateway_return_url_with_midtrans_result_params_keeps_signature_valid(): void
    {
        $catalog = $this->catalog();
        $this->route('qris', 'MIDTRANS', 0, 70, 'qris');
        IntegrationCredential::updateOrCreate(['code' => 'midtrans'], [
            'config_ciphertext' => ['server_key' => 'server-test', 'is_production' => false],
            'is_active' => true,
        ]);

        Http::fake(function ($request) {
            if ($request->url() === 'https://app.sandbox.midtrans.com/snap/v1/transactions') {
                return Http::response([
                    'token' => 'snap-token-test',
                    'redirect_url' => 'https://sandbox.midtrans.test/pay',
                ]);
            }

            return Http::response([], 404);
        });

        $user = User::create([
            'name' => 'Params Buyer',
            'email' => 'params-buyer@example.test',
            'phone' => '081234567892',
            'password' => Hash::make('StrongPassword123!'),
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        $checkout = $this->actingAs($user)->postJson('/checkout/orders', [
            'package_id' => $catalog['package_id'],
            'payment_channel_code' => 'qris',
            'customer_input' => ['user_id' => '888888'],
            'voucher_code' => null,
            'idempotency_key' => 'params-order-0001',
        ])->assertCreated();
        $orderNumber = $checkout->json('order_number');

        auth()->logout();
        $signedCreate = URL::temporarySignedRoute('payments.orders.create', now()->addMinutes(30), ['orderNumber' => $orderNumber]);
        $this->postJson($signedCreate, ['idempotency_key' => 'params-payment-0001'])->assertOk();

        $snap = Http::recorded(fn ($request) => $request->url() === 'https://app.sandbox.midtrans.com/snap/v1/transactions')->first();
        $this->assertNotNull($snap);
        $finishUrl = json_decode($snap[0]->body(), true, 512, JSON_THROW_ON_ERROR)['callbacks']['finish'] ?? null;
        $this->assertIsString($finishUrl);
        $payment = DB::table('payment_transactions')
            ->where('idempotency_key', 'params-payment-0001')->firstOrFail();
        $order = DB::table('orders')->where('order_number', $orderNumber)->firstOrFail();

        // Midtrans menempelkan param hasil ke finish redirect
        // SETELAH URL ditandatangani — signature harus tetap valid.
        // Param selalu ada: merchant_id, order_id, status_code,
        // transaction_status. Kadang ada: gross_amount.
        $withParams = $finishUrl.'&merchant_id=Mxxxxx&order_id='.$payment->merchant_reference
            .'&status_code=200&transaction_status=settlement&gross_amount='.(int) $order->total_idr;
        $this->get($withParams)->assertOk();

        // Param tambahan SEMBARANG tetap merusak signature → 403.
        $this->get($finishUrl.'&foo=bar')->assertForbidden();

        // signature_key adalah field notifikasi server-to-server,
        // BUKAN finish redirect — di luar allowlist → 403
        // (fail-closed, bukan ditebak masuk allowlist).
        $this->get($finishUrl.'&signature_key='.hash('sha512', 'fake'))->assertForbidden();

        // Invoice ditukar (ke order lain) + param gateway → 403:
        // param gateway tidak bisa dipakai untuk mengakses order lain.
        $checkout2 = $this->actingAs($user)->postJson('/checkout/orders', [
            'package_id' => $catalog['package_id'],
            'payment_channel_code' => 'qris',
            'customer_input' => ['user_id' => '888888'],
            'voucher_code' => null,
            'idempotency_key' => 'params-order-0002',
        ])->assertCreated();
        auth()->logout();
        $tampered = preg_replace(
            '/invoice=[^&]+/',
            'invoice='.$checkout2->json('order_number'),
            $withParams
        );
        $this->assertIsString($tampered);
        $this->get($tampered)->assertForbidden();

        // Expiry TIDAK dilemahkan oleh allowlist: signature
        // kedaluwarsa + param gateway → tetap 403.
        $expired = URL::temporarySignedRoute('payment.show', now()->subMinute(), ['invoice' => $orderNumber])
            .'&order_id='.$payment->merchant_reference.'&status_code=200&transaction_status=settlement';
        $this->get($expired)->assertForbidden();
    }

    public function test_gateway_return_url_can_be_revisited_with_params(): void
    {
        // Refresh / revisit return URL setelah bayar (param gateway
        // masih ada) → tetap bisa dibuka, bukan sekali pakai.
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
            'name' => 'Revisit Buyer',
            'email' => 'revisit-buyer@example.test',
            'phone' => '081234567894',
            'password' => Hash::make('StrongPassword123!'),
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $checkout = $this->actingAs($user)->postJson('/checkout/orders', [
            'package_id' => $catalog['package_id'],
            'payment_channel_code' => 'qris',
            'customer_input' => ['user_id' => '888888'],
            'voucher_code' => null,
            'idempotency_key' => 'revisit-order-0001',
        ])->assertCreated();
        $orderNumber = $checkout->json('order_number');
        auth()->logout();
        $this->postJson(
            URL::temporarySignedRoute('payments.orders.create', now()->addMinutes(30), ['orderNumber' => $orderNumber]),
            ['idempotency_key' => 'revisit-payment-0001']
        )->assertOk();
        $snap = Http::recorded(fn ($request) => $request->url() === 'https://app.sandbox.midtrans.com/snap/v1/transactions')->first();
        $finishUrl = json_decode($snap[0]->body(), true, 512, JSON_THROW_ON_ERROR)['callbacks']['finish'] ?? null;
        $this->assertIsString($finishUrl);
        $payment = DB::table('payment_transactions')->where('idempotency_key', 'revisit-payment-0001')->firstOrFail();
        $withParams = $finishUrl.'&merchant_id=Mxxxxx&order_id='.$payment->merchant_reference
            .'&status_code=200&transaction_status=settlement';

        $this->get($withParams)->assertOk();
        $this->get($withParams)->assertOk();
    }
}
