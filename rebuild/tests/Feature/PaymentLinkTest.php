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
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Tes tautan pembayaran bertanda tangan: URL kembali dari
 * gateway dan tautan status pesanan untuk tamu dan member,
 * termasuk masa berlaku dan perlindungan tanda tangannya. Tes
 * pembuatan pembayaran dan keadaan pembayaran ada di class tes
 * pembayaran masing-masing.
 */
class PaymentLinkTest extends TestCase
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

    public function test_signed_guest_status_sets_guest_session_only_for_its_order(): void
    {
        $catalog = $this->catalog();
        $this->route('manual_qris', 'MANUAL_QRIS');
        $checkout = $this->postJson('/checkout/orders', $this->guestCheckout(
            $catalog['package_id'], 'manual_qris', 'signed-guest-session-order-0001'
        ))->assertCreated();
        $orderNumber = (string) $checkout->json('order_number');
        $orderId = (int) DB::table('orders')->where('order_number', $orderNumber)->value('id');
        $this->flushSession();

        $url = URL::temporarySignedRoute('orders.status.signed', now()->addMinutes(30), [
            'orderNumber' => $orderNumber,
        ]);
        $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Customer/OrderDetail')
            ->where('order.order_number', $orderNumber));
        $this->assertSame($orderId, (int) session('guest_order_id'));
        $this->get(route('guest.orders.show', $orderNumber))->assertOk();

        $this->flushSession();
        $this->get(route('guest.orders.show', $orderNumber))->assertNotFound();
        $this->get(route('orders.status.signed', ['orderNumber' => $orderNumber]))->assertForbidden();
    }

    public function test_signed_status_link_opens_member_order_without_login(): void
    {
        $catalog = $this->catalog();
        $this->route('qris', 'MIDTRANS', 0, 70, 'qris');
        // Checkout route MIDTRANS mensyaratkan gateway ready
        // (kredensial server_key ada) — tanpa ini checkout 422.
        IntegrationCredential::updateOrCreate(['code' => 'midtrans'], [
            'config_ciphertext' => ['server_key' => 'server-test', 'is_production' => false],
            'is_active' => true,
        ]);

        $user = User::create([
            'name' => 'Status Buyer',
            'email' => 'status-buyer@example.test',
            'phone' => '081234567893',
            'password' => Hash::make('StrongPassword123!'),
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        $checkout = $this->actingAs($user)->postJson('/checkout/orders', [
            'package_id' => $catalog['package_id'],
            'payment_channel_code' => 'qris',
            'customer_input' => ['user_id' => '888888'],
            'voucher_code' => null,
            'idempotency_key' => 'status-order-0001',
        ])->assertCreated();
        $orderNumber = $checkout->json('order_number');

        // Member logout membuka halaman payment lewat link signed:
        // CTA status mengarah ke tujuan status bertanda tangan
        // (bisa dibuka), BUKAN ke route auth yang hanya melempar
        // ke login.
        auth()->logout();
        $signedPage = URL::temporarySignedRoute('payment.show', now()->addMinutes(30), ['invoice' => $orderNumber]);
        $page = $this->get($signedPage)->assertOk();
        $statusUrl = $page->viewData('page')['props']['statusUrl'] ?? null;
        $this->assertIsString($statusUrl);
        $this->assertStringContainsString('/orders/'.$orderNumber.'/status', $statusUrl);
        $this->assertTrue(URL::hasValidSignature(Request::create($statusUrl)));

        // Tujuan status bisa dibuka tanpa login, order terlihat.
        $this->get($statusUrl)->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Customer/OrderDetail')
                ->where('order.order_number', $orderNumber));
        // Signed member order must not grant a guest checkout session.
        $this->assertNull(session('guest_order_id'));

        // Tanpa signature → 403 (status tidak dibuka tanpa otorisasi).
        $this->get(route('orders.status.signed', ['orderNumber' => $orderNumber]))->assertForbidden();

        // Signature dipalsu / invoice ditukar → 403.
        $tampered = preg_replace(
            '/\/orders\/[^\/]+\/status/',
            '/orders/ORD-TAMPERED/status',
            $statusUrl
        );
        $this->assertIsString($tampered);
        $this->get($tampered)->assertForbidden();

        // Owner yang login tetap ke halaman akun (tidak berubah),
        // dan setelah login sebagai owner mendarat di order itu.
        $ownerPage = $this->actingAs($user)->get(route('payment.show', ['invoice' => $orderNumber]))->assertOk();
        $accountUrl = route('account.orders.show', DB::table('orders')->where('order_number', $orderNumber)->value('id'));
        $this->assertSame(
            $accountUrl,
            $ownerPage->viewData('page')['props']['statusUrl'] ?? null
        );
        $this->actingAs($user)->get($accountUrl)->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Customer/OrderDetail')
                ->where('order.order_number', $orderNumber));
    }

    public function test_signed_status_link_opens_expired_order_without_broken_page(): void
    {
        // Order kedaluwarsa / terminal saat CTA diklik → halaman
        // status tetap tampil (status kedaluwarsa), bukan rusak.
        // Link untuk order kedaluwarsa mendapat jendela 30 menit
        // (keputusan user: SEMUA link bertanda tangan WAJIB
        // berexpiry): statusUrl yang digenerate controller untuk
        // order kedaluwarsa WAJIB punya param expires (bukan
        // tanpa-expiry) — dibuka dalam jendela → 200, sesudah
        // jendela → 403. createPaymentUrl yang sama juga wajib
        // berexpiry.
        $catalog = $this->catalog();
        $this->route('qris', 'MIDTRANS', 0, 70, 'qris');
        IntegrationCredential::updateOrCreate(['code' => 'midtrans'], [
            'config_ciphertext' => ['server_key' => 'server-test', 'is_production' => false],
            'is_active' => true,
        ]);
        $user = User::create([
            'name' => 'Expired Status Buyer',
            'email' => 'expired-status-buyer@example.test',
            'phone' => '081234567896',
            'password' => Hash::make('StrongPassword123!'),
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $checkout = $this->actingAs($user)->postJson('/checkout/orders', [
            'package_id' => $catalog['package_id'],
            'payment_channel_code' => 'qris',
            'customer_input' => ['user_id' => '888888'],
            'voucher_code' => null,
            'idempotency_key' => 'expired-status-order-0001',
        ])->assertCreated();
        $orderNumber = $checkout->json('order_number');
        DB::table('orders')->where('order_number', $orderNumber)
            ->update(['status' => 'EXPIRED', 'expires_at' => now()->subMinute()]);

        auth()->logout();
        $response = $this->get(URL::temporarySignedRoute('payment.show', now()->addMinutes(30), ['invoice' => $orderNumber]))
            ->assertOk();
        $statusUrl = $response->inertiaProps('statusUrl');
        $createUrl = $response->inertiaProps('createPaymentUrl');
        $this->assertIsString($statusUrl);
        $this->assertStringContainsString('expires=', $statusUrl);
        $this->assertIsString($createUrl);
        $this->assertStringContainsString('expires=', $createUrl);

        $this->get($statusUrl)->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Customer/OrderDetail')
                ->where('order.order_number', $orderNumber)
                ->where('order.status', 'FAILED')
                ->where('order.status_note', 'Kedaluwarsa'));

        // Sesudah jendela 30 menit, link yang sama → 403.
        Carbon::setTestNow(now()->addMinutes(31));
        try {
            $this->get($statusUrl)->assertForbidden();
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_account_order_login_preserves_intended_to_order(): void
    {
        // Alur penuh login-dengan-return-target: logout → buka
        // URL akun → redirect login dengan intended = order →
        // login sebagai owner → mendarat di order itu, bukan
        // halaman generik. Intended selamat melewati login
        // Fortify (regenerasi sesi mempertahankan data sesi).
        $catalog = $this->catalog();
        $this->route('qris', 'MIDTRANS', 0, 70, 'qris');
        IntegrationCredential::updateOrCreate(['code' => 'midtrans'], [
            'config_ciphertext' => ['server_key' => 'server-test', 'is_production' => false],
            'is_active' => true,
        ]);
        $user = User::create([
            'name' => 'Intended Buyer',
            'email' => 'intended-buyer@example.test',
            'phone' => '081234567897',
            'password' => Hash::make('StrongPassword123!'),
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $checkout = $this->actingAs($user)->postJson('/checkout/orders', [
            'package_id' => $catalog['package_id'],
            'payment_channel_code' => 'qris',
            'customer_input' => ['user_id' => '888888'],
            'voucher_code' => null,
            'idempotency_key' => 'intended-order-0001',
        ])->assertCreated();
        $orderNumber = $checkout->json('order_number');
        $accountUrl = route('account.orders.show', DB::table('orders')->where('order_number', $orderNumber)->value('id'));

        auth()->logout();
        $this->get($accountUrl)->assertRedirect(route('login'));
        $this->assertSame($accountUrl, session('url.intended'));

        $this->post('/login', ['email' => 'intended-buyer@example.test', 'password' => 'StrongPassword123!'])
            ->assertRedirect($accountUrl);
        $this->assertAuthenticatedAs($user);
        $this->get($accountUrl)->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Customer/OrderDetail')
                ->where('order.order_number', $orderNumber));
    }

    public function test_signed_status_link_opened_by_other_logged_in_user_with_signature(): void
    {
        // Signature adalah otorisasinya (sama seperti link email):
        // akun lain yang MEMEGANG link signed tetap bisa membuka
        // status order itu — yang diblokir adalah tanpa signature /
        // signature order lain (sudah dites di test status utama).
        // Ini mendokumentasikan batas desain: bukan kebocoran,
        // melainkan otorisasi berbasis kepemilikan link.
        $catalog = $this->catalog();
        $this->route('qris', 'MIDTRANS', 0, 70, 'qris');
        IntegrationCredential::updateOrCreate(['code' => 'midtrans'], [
            'config_ciphertext' => ['server_key' => 'server-test', 'is_production' => false],
            'is_active' => true,
        ]);
        $user = User::create([
            'name' => 'Owner Buyer',
            'email' => 'owner-buyer@example.test',
            'phone' => '081234567898',
            'password' => Hash::make('StrongPassword123!'),
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $other = User::create([
            'name' => 'Other Buyer',
            'email' => 'other-buyer@example.test',
            'phone' => '081234567899',
            'password' => Hash::make('StrongPassword123!'),
        ]);
        $other->forceFill(['email_verified_at' => now()])->save();
        $checkout = $this->actingAs($user)->postJson('/checkout/orders', [
            'package_id' => $catalog['package_id'],
            'payment_channel_code' => 'qris',
            'customer_input' => ['user_id' => '888888'],
            'voucher_code' => null,
            'idempotency_key' => 'owner-buyer-order-0001',
        ])->assertCreated();
        $orderNumber = $checkout->json('order_number');

        $this->actingAs($other)
            ->get(URL::temporarySignedRoute('orders.status.signed', now()->addMinutes(30), ['orderNumber' => $orderNumber]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Customer/OrderDetail')
                ->where('order.order_number', $orderNumber));
    }
}
