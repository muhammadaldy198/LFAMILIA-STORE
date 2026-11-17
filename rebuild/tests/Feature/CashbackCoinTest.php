<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProviderMapping;
use App\Models\User;
use App\Services\AdminManualOrderService;
use App\Services\CashbackCoinService;
use App\Services\FulfillmentService;
use App\Services\FulfillmentSupportService;
use App\Services\VoucherStockService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * Tes cashback LFAMILIA Coin: 1 coin per Rp1.000 basis belanja (subtotal
 * setelah voucher, tanpa biaya kanal), minimal Rp150.000, untuk member
 * atau tamu yang emailnya cocok dengan akun terdaftar, hanya pesanan SUCCESS, masuk tepat sekali dari jalur SUCCESS mana pun,
 * dan lencana "+{n} Coin" di payload kartu paket katalog. Aturan bayar
 * saldo wajib penuh sudah dikunci di PaymentWalletTest.
 */
class CashbackCoinTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function member(string $email, int $balanceIdr = 0): User
    {
        $user = User::create([
            'name' => 'Pelanggan Coin',
            'email' => $email,
            'phone' => '0812'.random_int(10000000, 99999999),
            'password' => Hash::make('StrongPassword123!'),
        ]);
        DB::table('wallets')->where('user_id', $user->id)->update(['balance_idr' => $balanceIdr]);

        return $user;
    }

    /**
     * @return array{product_id:int, package_id:int}
     */
    private function catalogRows(): array
    {
        $product = Product::create([
            'category_id' => Category::where('slug', 'game')->firstOrFail()->id,
            'name' => 'Produk Coin '.bin2hex(random_bytes(3)),
            'slug' => 'produk-coin-'.bin2hex(random_bytes(4)),
            'fulfillment_mode' => 'MANUAL',
            'is_active' => false,
        ]);
        $package = $product->packages()->create([
            'code' => 'CB'.bin2hex(random_bytes(3)), 'name' => 'Paket Coin', 'is_active' => false,
        ]);

        return ['product_id' => (int) $product->id, 'package_id' => (int) $package->id];
    }

    /**
     * Rincian harga persis seperti yang disimpan CheckoutCreationService.
     *
     * @return array<string, mixed>
     */
    private function snapshot(int $subtotalIdr, int $voucherIdr, int $feeIdr, string $gatewayCode): array
    {
        return [
            'pricing' => [
                'cost_idr' => $subtotalIdr, 'margin_idr' => 0, 'member_discount_idr' => 0,
                'voucher_discount_idr' => $voucherIdr, 'discount_idr' => $voucherIdr,
                'fee_idr' => $feeIdr, 'total_idr' => $subtotalIdr - $voucherIdr + $feeIdr,
            ],
            'payment' => ['gateway_code' => $gatewayCode],
        ];
    }

    /**
     * Kolom harga yang memenuhi orders_price_math_check untuk snapshot di
     * atas. Kunci snapshot lain (produk, paket, provider) dipertahankan.
     *
     * @param  array<string, mixed>  $baseSnapshot
     * @return array<string, int|string>
     */
    private function priceColumns(int $subtotalIdr, int $voucherIdr, int $feeIdr, string $gatewayCode, array $baseSnapshot = []): array
    {
        return [
            'cost_idr' => $subtotalIdr, 'margin_idr' => 0, 'discount_idr' => $voucherIdr,
            'fee_idr' => $feeIdr, 'total_idr' => $subtotalIdr - $voucherIdr + $feeIdr,
            'snapshot' => json_encode(
                [...$baseSnapshot, ...$this->snapshot($subtotalIdr, $voucherIdr, $feeIdr, $gatewayCode)],
                JSON_THROW_ON_ERROR
            ),
        ];
    }

    private function order(
        ?int $userId,
        string $status,
        int $subtotalIdr,
        int $voucherIdr = 0,
        int $feeIdr = 0,
        string $gatewayCode = 'MIDTRANS',
        string $guestEmail = 'tamu-coin@example.test',
    ): int {
        $catalog = $this->catalogRows();

        return (int) DB::table('orders')->insertGetId([
            'order_number' => 'LF-CB-'.strtoupper((string) Str::ulid()),
            'user_id' => $userId,
            'guest_email' => $userId === null ? $guestEmail : null,
            'guest_phone' => $userId === null ? '081234567890' : null,
            'product_id' => $catalog['product_id'],
            'product_package_id' => $catalog['package_id'],
            'status' => $status,
            'currency' => 'IDR',
            'customer_input' => json_encode(['user_id' => '123456'], JSON_THROW_ON_ERROR),
            ...$this->priceColumns($subtotalIdr, $voucherIdr, $feeIdr, $gatewayCode),
            'idempotency_key' => 'cashback-test:'.Str::uuid(),
            'paid_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function walletBalance(int $userId): int
    {
        return (int) DB::table('wallets')->where('user_id', $userId)->value('balance_idr');
    }

    private function cashbackRowCount(int $orderId): int
    {
        return DB::table('wallet_ledger')->where('source', 'CASHBACK')
            ->where('reference_type', 'ORDER')->where('reference_id', (string) $orderId)->count();
    }

    private function earn(int $orderId): int
    {
        return app(CashbackCoinService::class)->earnForOrder($orderId);
    }

    public function test_member_success_order_earns_one_coin_per_thousand_rupiah_rounded_down(): void
    {
        $user = $this->member('coin-153@example.test', 20000);
        $orderId = $this->order($user->id, 'SUCCESS', 153908);
        $versionBefore = (int) DB::table('wallets')->where('user_id', $user->id)->value('version');

        $this->assertSame(153, $this->earn($orderId));

        $this->assertSame(20153, $this->walletBalance($user->id));
        $this->assertSame($versionBefore + 1, (int) DB::table('wallets')->where('user_id', $user->id)->value('version'));
        $entry = DB::table('wallet_ledger')->where('source', 'CASHBACK')
            ->where('reference_id', (string) $orderId)->sole();
        $this->assertSame((int) DB::table('wallets')->where('user_id', $user->id)->value('id'), (int) $entry->wallet_id);
        $this->assertSame(153, (int) $entry->amount_idr);
        $this->assertSame(20000, (int) $entry->balance_before_idr);
        $this->assertSame(20153, (int) $entry->balance_after_idr);
        $this->assertSame('ORDER', $entry->reference_type);
        $this->assertSame('system', $entry->actor_type);
        $this->assertNull($entry->actor_id);
        $this->assertSame('cashback:order:'.$orderId, $entry->idempotency_key);
    }

    public function test_repeated_earn_for_the_same_order_credits_exactly_once(): void
    {
        $user = $this->member('coin-repeat@example.test');
        $orderId = $this->order($user->id, 'SUCCESS', 153908);
        $service = app(CashbackCoinService::class);

        $this->assertSame(153, $service->earnForOrder($orderId));
        $this->assertSame(0, $service->earnForOrder($orderId));
        $this->assertSame(0, $service->earnForOrderSafely($orderId));
        $service->earnAfterCommit($orderId);

        $this->assertSame(153, $this->walletBalance($user->id));
        $this->assertSame(1, $this->cashbackRowCount($orderId));
    }

    public function test_basis_below_minimum_writes_nothing(): void
    {
        $user = $this->member('coin-below@example.test', 5000);
        $orderId = $this->order($user->id, 'SUCCESS', 149999);
        $versionBefore = (int) DB::table('wallets')->where('user_id', $user->id)->value('version');

        $this->assertSame(0, $this->earn($orderId));

        $this->assertSame(5000, $this->walletBalance($user->id));
        $this->assertSame($versionBefore, (int) DB::table('wallets')->where('user_id', $user->id)->value('version'));
        $this->assertSame(0, $this->cashbackRowCount($orderId));
    }

    public function test_guest_success_order_without_a_matching_account_earns_nothing(): void
    {
        $orderId = $this->order(null, 'SUCCESS', 153908, guestEmail: 'tanpa-akun-coin@example.test');
        $this->assertFalse(DB::table('users')->whereRaw('LOWER(email) = ?', ['tanpa-akun-coin@example.test'])->exists());
        $ledgerBefore = DB::table('wallet_ledger')->count();

        $this->assertSame(0, $this->earn($orderId));

        $this->assertSame(0, $this->cashbackRowCount($orderId));
        $this->assertSame($ledgerBefore, DB::table('wallet_ledger')->count());
    }

    public function test_guest_order_with_an_email_matching_an_account_credits_that_account(): void
    {
        $account = $this->member('Tamu.Cocok@Example.test', 1000);
        $orderId = $this->order(null, 'SUCCESS', 153908, guestEmail: 'tamu.cocok@example.test');

        $this->assertSame(153, $this->earn($orderId));

        $this->assertSame(1153, $this->walletBalance($account->id));
        $entry = DB::table('wallet_ledger')->where('source', 'CASHBACK')
            ->where('reference_type', 'ORDER')->where('reference_id', (string) $orderId)->sole();
        $this->assertSame((int) DB::table('wallets')->where('user_id', $account->id)->value('id'), (int) $entry->wallet_id);
        $this->assertSame(1000, (int) $entry->balance_before_idr);
        $this->assertSame([1153, 'cashback:order:'.$orderId], [(int) $entry->balance_after_idr, $entry->idempotency_key]);
    }

    public function test_guest_order_with_a_matching_account_below_minimum_earns_nothing(): void
    {
        $account = $this->member('tamu-kecil@example.test', 1000);
        $orderId = $this->order(null, 'SUCCESS', 149999, guestEmail: 'tamu-kecil@example.test');

        $this->assertSame(0, $this->earn($orderId));

        $this->assertSame(1000, $this->walletBalance($account->id));
        $this->assertSame(0, $this->cashbackRowCount($orderId));
    }

    public function test_repeated_earn_for_a_guest_email_order_credits_exactly_once(): void
    {
        $account = $this->member('tamu-ulang@example.test');
        $orderId = $this->order(null, 'SUCCESS', 153908, guestEmail: 'tamu-ulang@example.test');
        $service = app(CashbackCoinService::class);

        $this->assertSame(153, $service->earnForOrder($orderId));
        $this->assertSame(0, $service->earnForOrder($orderId));
        $this->assertSame(0, $service->earnForOrderSafely($orderId));

        $this->assertSame(153, $this->walletBalance($account->id));
        $this->assertSame(1, $this->cashbackRowCount($orderId));
    }

    public function test_member_order_is_credited_to_its_owner_not_by_email(): void
    {
        $owner = $this->member('pemilik-order@example.test');
        $sameEmailElsewhere = $this->member('email-lain@example.test');
        $orderId = $this->order($owner->id, 'SUCCESS', 153908); // guest_email sengaja menunjuk akun lain:
        DB::table('orders')->where('id', $orderId)->update(['guest_email' => 'email-lain@example.test']);

        $this->assertSame(153, $this->earn($orderId));

        $this->assertSame(153, $this->walletBalance($owner->id));
        $this->assertSame(0, $this->walletBalance($sameEmailElsewhere->id));
    }

    public function test_voucher_discount_reduces_the_basis(): void
    {
        $user = $this->member('coin-voucher@example.test');
        $orderId = $this->order($user->id, 'SUCCESS', 160000, voucherIdr: 10000);

        $this->assertSame(150, $this->earn($orderId));
        $this->assertSame(150, $this->walletBalance($user->id));
    }

    public function test_voucher_that_drops_the_basis_below_minimum_earns_nothing(): void
    {
        $user = $this->member('coin-voucher-low@example.test');
        $orderId = $this->order($user->id, 'SUCCESS', 155000, voucherIdr: 10000);

        $this->assertSame(0, $this->earn($orderId));
        $this->assertSame(0, $this->cashbackRowCount($orderId));
    }

    public function test_gateway_fee_is_not_part_of_the_basis(): void
    {
        $user = $this->member('coin-fee@example.test');
        // Total dibayar 159.500, tetapi basis tetap subtotal 155.000.
        $orderId = $this->order($user->id, 'SUCCESS', 155000, feeIdr: 4500);
        $this->assertSame(159500, (int) DB::table('orders')->where('id', $orderId)->value('total_idr'));

        $this->assertSame(155, $this->earn($orderId));
        $this->assertSame(155, $this->walletBalance($user->id));
    }

    public function test_fee_does_not_lift_a_basis_below_minimum(): void
    {
        $user = $this->member('coin-fee-low@example.test');
        $orderId = $this->order($user->id, 'SUCCESS', 149000, feeIdr: 3000);

        $this->assertSame(0, $this->earn($orderId));
        $this->assertSame(0, $this->cashbackRowCount($orderId));
    }

    public function test_order_paid_with_lfamilia_coin_also_earns(): void
    {
        $user = $this->member('coin-wallet-paid@example.test');
        $orderId = $this->order($user->id, 'SUCCESS', 153908, gatewayCode: 'WALLET');

        $this->assertSame(153, $this->earn($orderId));
        $this->assertSame(153, $this->walletBalance($user->id));
    }

    public function test_orders_that_are_not_success_earn_nothing(): void
    {
        $user = $this->member('coin-not-success@example.test');

        foreach (['PENDING_PAYMENT', 'PAID', 'PROCESSING', 'BLOCKED', 'FAILED', 'CANCELLED'] as $status) {
            $orderId = $this->order($user->id, $status, 153908);

            $this->assertSame(0, $this->earn($orderId), $status);
            $this->assertSame(0, $this->cashbackRowCount($orderId), $status);
        }
        $this->assertSame(0, $this->walletBalance($user->id));
    }

    public function test_unreadable_snapshot_is_skipped_with_a_warning(): void
    {
        Log::spy();
        $user = $this->member('coin-bad-snapshot@example.test');
        $orderId = $this->order($user->id, 'SUCCESS', 153908);
        DB::table('orders')->where('id', $orderId)->update(['snapshot' => json_encode(['product' => []])]);

        $this->assertSame(0, app(CashbackCoinService::class)->earnForOrderSafely($orderId));

        $this->assertSame(0, $this->cashbackRowCount($orderId));
        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message, array $context = []): bool => str_contains($message, 'Cashback coin dilewati')
                && $context === ['order_id' => $orderId]
        )->once();
    }

    private function admin(): AdminUser
    {
        $admin = $this->createAdmin([
            'name' => 'Admin Coin', 'email' => bin2hex(random_bytes(6)).'@example.test',
            'password' => bcrypt('cashback-coin-only'), 'role' => 'SUPER_ADMIN',
            'permissions' => [], 'is_active' => true,
        ]);
        $this->actingAs($admin, 'admin');

        return $admin;
    }

    /**
     * Pesanan PROCESSING dengan satu attempt MANUAL_PENDING (lewat jalur
     * resmi AdminManualOrderService), lalu dijadikan pesanan member dengan
     * basis 153.908 supaya ketiga jalur SUCCESS bisa diuji dari titik awal
     * yang sama.
     *
     * @return array{order_id:int, attempt_id:int, admin_id:int}
     */
    private function memberOrderInFulfillment(User $user, string $destination): array
    {
        $admin = $this->admin();
        $orderId = app(AdminManualOrderService::class)->create([
            'customer_name' => 'Pelanggan Coin', 'phone' => '081234567890',
            'email' => 'manual-coin@example.test', 'product_name' => 'Produk',
            'package_name' => 'Paket', 'destination' => $destination,
            'total_idr' => 153908, 'note' => 'catatan', 'payment_received' => true,
            'idempotency_key' => (string) Str::uuid(),
        ], (int) $admin->id);
        $manualSnapshot = json_decode((string) DB::table('orders')->where('id', $orderId)->value('snapshot'), true);
        DB::table('orders')->where('id', $orderId)->update([
            'user_id' => $user->id, 'guest_email' => null, 'guest_phone' => null,
            ...$this->priceColumns(153908, 0, 0, 'MIDTRANS', $manualSnapshot),
        ]);
        $attempt = DB::table('fulfillment_attempts')->where('order_id', $orderId)->sole();
        $this->assertSame('MANUAL_PENDING', $attempt->status);

        return ['order_id' => $orderId, 'attempt_id' => (int) $attempt->id, 'admin_id' => (int) $admin->id];
    }

    public function test_manual_admin_completion_earns_once(): void
    {
        $user = $this->member('coin-manual@example.test');
        $context = $this->memberOrderInFulfillment($user, 'COIN-MANUAL');

        app(FulfillmentService::class)->completeManual($context['attempt_id'], 'KODE-COIN-1', null, $context['admin_id']);

        $this->assertSame('SUCCESS', DB::table('orders')->where('id', $context['order_id'])->value('status'));
        $this->assertSame(153, $this->walletBalance($user->id));
        $this->assertSame(0, $this->earn($context['order_id']));
        $this->assertSame(153, $this->walletBalance($user->id));
        $this->assertSame(1, $this->cashbackRowCount($context['order_id']));
    }

    public function test_provider_success_result_earns_once_even_when_the_result_is_replayed(): void
    {
        $user = $this->member('coin-provider@example.test');
        $context = $this->memberOrderInFulfillment($user, 'COIN-PROVIDER');
        $request = [
            'ref_id' => (string) DB::table('fulfillment_attempts')->where('id', $context['attempt_id'])->value('external_reference'),
            'buyer_sku_code' => 'COIN-TEST-SKU',
            'customer_no' => 'COIN-TEST-DESTINATION',
        ];
        DB::table('fulfillment_attempts')->where('id', $context['attempt_id'])->update([
            'status' => 'PENDING',
            'request_payload' => json_encode($request, JSON_THROW_ON_ERROR),
        ]);
        $response = [...$request, 'status' => 'Sukses', 'rc' => '00', 'sn' => 'SN-COIN-1'];

        app(FulfillmentService::class)->applyProviderResult($context['attempt_id'], $response, 'webhook');
        app(FulfillmentService::class)->applyProviderResult($context['attempt_id'], $response, 'reconciliation');

        $this->assertSame('SUCCESS', DB::table('orders')->where('id', $context['order_id'])->value('status'));
        $this->assertSame(153, $this->walletBalance($user->id));
        $this->assertSame(1, $this->cashbackRowCount($context['order_id']));
    }

    public function test_voucher_stock_delivery_earns_once(): void
    {
        $user = $this->member('coin-stock@example.test');
        $context = $this->memberOrderInFulfillment($user, 'COIN-STOCK');
        $stockKey = 'cashback.'.bin2hex(random_bytes(4));
        app(VoucherStockService::class)->import($stockKey, 'KODE-STOK-COIN-1');
        DB::table('fulfillment_attempts')->where('id', $context['attempt_id'])->update(['status' => 'SENDING']);

        app(FulfillmentSupportService::class)->fulfillVoucherStock($context['attempt_id'], $stockKey);
        app(FulfillmentSupportService::class)->fulfillVoucherStock($context['attempt_id'], $stockKey);

        $this->assertSame('SUCCESS', DB::table('orders')->where('id', $context['order_id'])->value('status'));
        $this->assertSame(153, $this->walletBalance($user->id));
        $this->assertSame(1, $this->cashbackRowCount($context['order_id']));
    }

    public function test_earn_failure_never_blocks_the_success_transition(): void
    {
        Log::spy();
        $user = $this->member('coin-failure@example.test');
        $context = $this->memberOrderInFulfillment($user, 'COIN-FAILURE');
        $failingCashback = Mockery::mock(CashbackCoinService::class)->makePartial();
        $failingCashback->shouldReceive('earnForOrder')->andThrow(new RuntimeException('simulasi gangguan dompet'));
        $this->app->instance(CashbackCoinService::class, $failingCashback);

        app(FulfillmentService::class)->completeManual($context['attempt_id'], 'KODE-COIN-2', null, $context['admin_id']);

        $this->assertSame('SUCCESS', DB::table('orders')->where('id', $context['order_id'])->value('status'));
        $this->assertSame(0, $this->walletBalance($user->id));
        $this->assertSame(0, $this->cashbackRowCount($context['order_id']));
        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message, array $logContext = []): bool => str_contains($message, 'Cashback coin tidak dapat ditulis')
                && ($logContext['order_id'] ?? null) === $context['order_id']
        )->once();
    }

    private function purchasablePackage(Product $product, int $providerId, string $code, int $priceIdr): void
    {
        // Markup patok 0: harga kartu = modal Digiflazz, angka lencana bisa diuji persis.
        $package = $product->packages()->create([
            'code' => $code, 'name' => 'Paket '.$code, 'nominal_value' => $priceIdr,
            'pricing_mode' => 'FIXED', 'margin_fixed_idr' => 0, 'is_active' => true,
        ]);
        ProviderMapping::create([
            'product_package_id' => $package->id, 'provider_id' => $providerId,
            'external_sku' => 'coin-'.strtolower($code).'-'.bin2hex(random_bytes(3)),
            'cost_idr' => $priceIdr, 'store_cost_idr' => $priceIdr, 'is_active' => true,
        ]);
        $this->seedCheckoutDigiflazzCatalog($package->id);
    }

    public function test_catalog_package_payload_carries_the_cashback_coin_badge_value(): void
    {
        DB::table('providers')->where('code', 'DIGIFLAZZ')->update(['is_active' => true]);
        $providerId = (int) DB::table('providers')->where('code', 'DIGIFLAZZ')->value('id');
        $product = Product::create([
            'category_id' => Category::where('slug', 'game')->firstOrFail()->id,
            'name' => 'Katalog Coin', 'slug' => 'katalog-coin-'.bin2hex(random_bytes(4)),
            'fulfillment_mode' => 'AUTO_PROVIDER', 'margin_percent' => 10, 'is_active' => true,
        ]);
        $this->purchasablePackage($product, $providerId, 'CB153908', 153908);
        $this->purchasablePackage($product, $providerId, 'CB149000', 149000);
        $this->purchasablePackage($product, $providerId, 'CB150000', 150000);

        $this->get('/catalog/'.$product->slug)->assertOk()->assertInertia(function (Assert $page): void {
            $page->component('Catalog/Show')->has('packages', 3);
            $byName = collect($page->toArray()['props']['packages'])->keyBy('name');
            $this->assertSame(153908, $byName['Paket CB153908']['price_idr']);
            $this->assertSame(153, $byName['Paket CB153908']['cashback_coin']);
            $this->assertSame(149000, $byName['Paket CB149000']['price_idr']);
            $this->assertSame(0, $byName['Paket CB149000']['cashback_coin']);
            $this->assertSame(150, $byName['Paket CB150000']['cashback_coin']);
        });
    }
}
