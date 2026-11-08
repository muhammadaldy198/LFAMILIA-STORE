<?php

namespace Tests\Feature;

use App\Jobs\SendTransactionalEmailJob;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\PaymentStateService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Tes pembayaran memakai saldo dompet member: pemotongan yang
 * idempoten, larangan saldo kurang, dan aturan kepemilikan pada
 * halaman bayar dompet lewat tautan bertanda tangan. Tes topup
 * dompet ada di PaymentTopupTest; tes gateway dan checkout umum
 * ada di class tes pembayaran masing-masing.
 */
class PaymentWalletTest extends TestCase
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
     * @return array{user:User, order_number:string}
     */
    private function walletOrder(string $email, string $orderKey): array
    {
        $catalog = $this->catalog();
        $this->route('saldo', 'WALLET');

        $user = User::create([
            'name' => 'Wallet Buyer',
            'email' => $email,
            'phone' => '081234567891',
            'password' => Hash::make('StrongPassword123!'),
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        DB::table('wallets')->where('user_id', $user->id)->update(['balance_idr' => 50000]);

        $checkout = $this->actingAs($user)->postJson('/checkout/orders', [
            'package_id' => $catalog['package_id'],
            'payment_channel_code' => 'saldo',
            'customer_input' => ['user_id' => '888888'],
            'voucher_code' => null,
            'idempotency_key' => $orderKey,
        ])->assertCreated();

        return ['user' => $user, 'order_number' => $checkout->json('order_number')];
    }

    public function test_wallet_payment_debits_once_on_idempotent_retry(): void
    {
        $catalog = $this->catalog();
        $this->route('saldo', 'WALLET');

        $user = User::create([
            'name' => 'Wallet Buyer',
            'email' => 'wallet@example.test',
            'phone' => '081234567891',
            'password' => Hash::make('StrongPassword123!'),
        ]);
        // email_verified_at TIDAK fillable — create di atas diam-diam
        // membuangnya; gate D7 lalu menolak checkout member tak terverifikasi.
        $user->forceFill(['email_verified_at' => now()])->save();
        DB::table('wallets')->where('user_id', $user->id)->update(['balance_idr' => 50000]);

        $checkout = $this->actingAs($user)->postJson('/checkout/orders', [
            'package_id' => $catalog['package_id'],
            'payment_channel_code' => 'saldo',
            'customer_input' => ['user_id' => '888888'],
            'voucher_code' => null,
            'idempotency_key' => 'm7-wallet-order-0001',
        ])->assertCreated();

        $first = $this->actingAs($user)->postJson('/payments/orders/'.$checkout->json('order_number'), [
            'idempotency_key' => 'm7-wallet-payment-0001',
        ])->assertOk();
        $second = $this->actingAs($user)->postJson('/payments/orders/'.$checkout->json('order_number'), [
            'idempotency_key' => 'm7-wallet-payment-0001',
        ])->assertOk();

        $first->assertJsonPath('status', 'PAID');
        $second->assertJsonPath('status', 'PAID');
        $this->assertSame(39000, (int) DB::table('wallets')->where('user_id', $user->id)->value('balance_idr'));
        $walletId = DB::table('wallets')->where('user_id', $user->id)->value('id');
        $this->assertSame(1, DB::table('wallet_ledger')
            ->where('wallet_id', $walletId)->where('source', 'CHECKOUT')->count());
        $this->assertSame('PAID', DB::table('orders')
            ->where('order_number', $checkout->json('order_number'))->value('status'));
    }

    public function test_wallet_payment_cannot_overdraw_or_create_ledger_when_balance_is_insufficient(): void
    {
        $catalog = $this->catalog();
        $this->route('saldo', 'WALLET');

        $user = User::create([
            'name' => 'Low Balance Buyer',
            'email' => 'low-balance@example.test',
            'phone' => '081234567893',
            'password' => Hash::make('StrongPassword123!'),
        ]);
        // email_verified_at TIDAK fillable — lihat catatan di test wallet di atas.
        $user->forceFill(['email_verified_at' => now()])->save();
        DB::table('wallets')->where('user_id', $user->id)->update(['balance_idr' => 5000]);

        $checkout = $this->actingAs($user)->postJson('/checkout/orders', [
            'package_id' => $catalog['package_id'],
            'payment_channel_code' => 'saldo',
            'customer_input' => ['user_id' => '999999'],
            'voucher_code' => null,
            'idempotency_key' => 'm11-wallet-low-order-0001',
        ])->assertCreated();

        $this->actingAs($user)->postJson('/payments/orders/'.$checkout->json('order_number'), [
            'idempotency_key' => 'm11-wallet-low-payment-0001',
        ])->assertUnprocessable()->assertJsonValidationErrors(['payment']);

        $this->assertSame(5000, (int) DB::table('wallets')->where('user_id', $user->id)->value('balance_idr'));
        $this->assertSame(0, DB::table('wallet_ledger')
            ->where('wallet_id', DB::table('wallets')->where('user_id', $user->id)->value('id'))
            ->where('source', 'CHECKOUT')
            ->count());
        $this->assertSame('PENDING_PAYMENT', DB::table('orders')
            ->where('order_number', $checkout->json('order_number'))->value('status'));
        $this->assertSame('REJECTED', DB::table('payment_transactions')
            ->where('idempotency_key', 'm11-wallet-low-payment-0001')->value('status'));
    }

    public function test_wallet_payment_via_signed_link_requires_owner_login(): void
    {
        $order = $this->walletOrder('wallet-signed@example.test', 'wallet-signed-order-0001');

        // Buka link signed email saat logout: signature lolos cek
        // otorisasi, TAPI wallet wajib owner login — bukan 422 sesudah
        // payment dibuat-lalu-ditolak, melainkan 401 + login_url, dan
        // TIDAK ada payment_transactions yang dibuat.
        auth()->logout();
        $signed = URL::temporarySignedRoute('payments.orders.create', now()->addMinutes(30), ['orderNumber' => $order['order_number']]);

        $this->postJson($signed, ['idempotency_key' => 'wallet-signed-payment-0001'])
            ->assertUnauthorized()
            ->assertJsonPath('login_url', route('login'))
            ->assertJsonPath('message', 'Masuk sebagai pemilik pesanan untuk membayar dengan saldo.');

        $this->assertSame(0, DB::table('payment_transactions')
            ->where('order_id', DB::table('orders')->where('order_number', $order['order_number'])->value('id'))
            ->count());
    }

    public function test_wallet_payment_rejects_other_logged_in_account(): void
    {
        $order = $this->walletOrder('wallet-other@example.test', 'wallet-other-order-0001');

        $other = User::create([
            'name' => 'Other Account',
            'email' => 'wallet-other-account@example.test',
            'phone' => '081234567899',
            'password' => Hash::make('StrongPassword123!'),
        ]);
        $other->forceFill(['email_verified_at' => now()])->save();

        $signed = URL::temporarySignedRoute('payments.orders.create', now()->addMinutes(30), ['orderNumber' => $order['order_number']]);

        $this->actingAs($other)->postJson($signed, ['idempotency_key' => 'wallet-other-payment-0001'])
            ->assertUnauthorized()
            ->assertJsonPath('login_url', route('login'));
    }

    public function test_wallet_pay_page_via_signed_link_redirects_to_login_with_intended(): void
    {
        $order = $this->walletOrder('wallet-page@example.test', 'wallet-page-order-0001');
        auth()->logout();

        $signedPage = URL::temporarySignedRoute('payment.show', now()->addMinutes(30), ['invoice' => $order['order_number']]);

        $this->get($signedPage)
            ->assertRedirect(route('login'));
        $intended = session('url.intended');
        $this->assertIsString($intended);
        $this->assertStringContainsString('/payment?', $intended);
        $this->assertStringContainsString('invoice='.$order['order_number'], $intended);
        $this->assertTrue(URL::hasValidSignature(Request::create($intended)));
    }

    public function test_wallet_pay_page_opens_for_owner(): void
    {
        $order = $this->walletOrder('wallet-page-owner@example.test', 'wallet-page-owner-order-0001');

        $signedPage = URL::temporarySignedRoute('payment.show', now()->addMinutes(30), ['invoice' => $order['order_number']]);

        $this->actingAs($order['user'])->get($signedPage)->assertOk();
    }

    public function test_wallet_pay_page_logs_out_wrong_account_before_login_redirect(): void
    {
        $order = $this->walletOrder('wallet-page-wrong@example.test', 'wallet-page-wrong-order-0001');

        $other = User::create([
            'name' => 'Other Account',
            'email' => 'wallet-page-wrong-account@example.test',
            'phone' => '081234567899',
            'password' => Hash::make('StrongPassword123!'),
        ]);
        $other->forceFill(['email_verified_at' => now()])->save();

        $signedPage = URL::temporarySignedRoute('payment.show', now()->addMinutes(30), ['invoice' => $order['order_number']]);

        // TANPA fix, user yang login sebagai akun lain dilempar ke
        // /login lalu middleware guest Fortify melempar balik ke
        // /account — form login pemilik tidak pernah tampil dan
        // intended hilang saat logout dari /account. DENGAN fix:
        // sesi akun salah diakhiri dulu, redirect mendarat di form
        // login (bukan /account), intended terjaga, dan pengunjung
        // sudah guest.
        $this->actingAs($other)->get($signedPage)
            ->assertRedirect(route('login'))
            ->assertSessionHas('status');
        $this->assertGuest();
        $intended = session('url.intended');
        $this->assertIsString($intended);
        $this->assertStringContainsString('/payment?', $intended);
        $this->assertStringContainsString('invoice='.$order['order_number'], $intended);
        $this->assertTrue(URL::hasValidSignature(Request::create($intended)));
    }

    public function test_wallet_payment_page_with_gateway_params_still_requires_owner(): void
    {
        // Allowlist TIDAK membuat wallet bisa dibayar pakai
        // signature saja: order WALLET + param gateway + logout →
        // redirect login; login akun lain → redirect login;
        // owner login → 200.
        $order = $this->walletOrder('wallet-params@example.test', 'wallet-params-order-0001');
        $other = User::create([
            'name' => 'Other Account',
            'email' => 'wallet-params-other@example.test',
            'phone' => '081234567899',
            'password' => Hash::make('StrongPassword123!'),
        ]);
        $other->forceFill(['email_verified_at' => now()])->save();

        $signedWithParams = URL::temporarySignedRoute('payment.show', now()->addMinutes(30), ['invoice' => $order['order_number']])
            .'&merchant_id=Mxxxxx&order_id=wallet-params&status_code=200&transaction_status=settlement';

        auth()->logout();
        $this->get($signedWithParams)->assertRedirect(route('login'));
        $this->actingAs($other)->get($signedWithParams)->assertRedirect(route('login'));
        $this->actingAs($order['user'])->get($signedWithParams)->assertOk();
    }

    public function test_signed_status_link_for_wallet_order_still_requires_owner_login(): void
    {
        // Destination baru TIDAK BOLEH nge-bypass proteksi wallet:
        // order WALLET + status signed + logout → redirect login;
        // login akun lain → redirect login (sesi akun salah
        // diakhiri dulu, mirror halaman payment); owner → 200.
        $order = $this->walletOrder('wallet-status@example.test', 'wallet-status-order-0001');
        $other = User::create([
            'name' => 'Other Account',
            'email' => 'wallet-status-other@example.test',
            'phone' => '081234567899',
            'password' => Hash::make('StrongPassword123!'),
        ]);
        $other->forceFill(['email_verified_at' => now()])->save();

        $signedStatus = URL::temporarySignedRoute('orders.status.signed', now()->addMinutes(30), ['orderNumber' => $order['order_number']]);

        auth()->logout();
        $this->get($signedStatus)->assertRedirect(route('login'));
        $this->assertStringContainsString(
            '/orders/'.$order['order_number'].'/status',
            (string) session('url.intended')
        );

        $this->actingAs($other)->get($signedStatus)
            ->assertRedirect(route('login'))
            ->assertSessionHas('status');
        $this->assertGuest();

        $this->actingAs($order['user'])->get($signedStatus)->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Customer/OrderDetail')
                ->where('order.order_number', $order['order_number']));
    }

    public function test_wallet_checkout_is_blocked_while_topup_refund_review_is_open(): void
    {
        $catalog = $this->catalog();
        $this->route('saldo', 'WALLET');

        $user = User::create([
            'name' => 'Refund Hold Buyer',
            'email' => 'refund-hold@example.test',
            'phone' => '081234567895',
            'password' => Hash::make('StrongPassword123!'),
        ]);
        // email_verified_at TIDAK fillable — lihat catatan di test wallet di atas.
        $user->forceFill(['email_verified_at' => now()])->save();
        $wallet = DB::table('wallets')->where('user_id', $user->id)->firstOrFail();
        DB::table('wallets')->where('id', $wallet->id)->update(['balance_idr' => 50000]);
        $channelId = DB::table('payment_channels')->where('code', 'qris')->value('id');
        DB::table('wallet_topups')->insert([
            'wallet_id' => $wallet->id,
            'user_id' => $user->id,
            'payment_channel_id' => $channelId,
            'amount_idr' => 10000,
            'fee_idr' => 700,
            'total_idr' => 10700,
            'status' => 'REFUND_REVIEW',
            'idempotency_key' => 'refund-hold-topup-0001',
            'request_fingerprint' => hash('sha256', 'refund-hold-topup'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $checkout = $this->actingAs($user)->postJson('/checkout/orders', [
            'package_id' => $catalog['package_id'],
            'payment_channel_code' => 'saldo',
            'customer_input' => ['user_id' => '777777'],
            'voucher_code' => null,
            'idempotency_key' => 'refund-hold-order-0001',
        ])->assertCreated();

        $this->actingAs($user)->postJson('/payments/orders/'.$checkout->json('order_number'), [
            'idempotency_key' => 'refund-hold-payment-0001',
        ])->assertUnprocessable()->assertJsonValidationErrors(['payment']);

        $this->assertSame(50000, (int) DB::table('wallets')->where('id', $wallet->id)->value('balance_idr'));
        $this->assertSame(0, DB::table('wallet_ledger')
            ->where('wallet_id', $wallet->id)
            ->where('source', 'CHECKOUT')
            ->count());

        $topupId = DB::table('wallet_topups')->where('user_id', $user->id)->value('id');
        app(PaymentStateService::class)->closeTopupRefundReview((int) $topupId, 'Pemeriksaan selesai; penyesuaian manual terpisah.');
        $this->actingAs($user)->postJson('/payments/orders/'.$checkout->json('order_number'), [
            'idempotency_key' => 'refund-hold-payment-after-close-0001',
        ])->assertOk()->assertJsonPath('status', 'PAID');
        $this->assertSame(1, DB::table('wallet_ledger')->where('wallet_id', $wallet->id)->where('source', 'CHECKOUT')->count());
    }
}
