<?php

namespace Tests\Feature;

use App\Jobs\SendTransactionalEmailJob;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\PaymentStateService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Tes siklus topup dompet: kredit dari callback yang
 * idempoten, topup masuk tinjauan pengembalian, penolakan
 * sesudah berakhir, perintah kedaluwarsa, dan pembayaran telat
 * sesudah topup berakhir. Tes pemulihan topup ada di
 * PaymentTopupRecoveryTest.
 */
class PaymentTopupTest extends TestCase
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

    public function test_wallet_topup_paid_callback_credit_is_idempotent_and_fee_is_not_credited(): void
    {
        $user = User::create([
            'name' => 'Topup Buyer',
            'email' => 'topup@example.test',
            'phone' => '081234567892',
            'password' => Hash::make('StrongPassword123!'),
            'email_verified_at' => now(),
        ]);
        $wallet = DB::table('wallets')->where('user_id', $user->id)->first();
        $channelId = DB::table('payment_channels')->where('code', 'qris')->value('id');

        $topupId = DB::table('wallet_topups')->insertGetId([
            'wallet_id' => $wallet->id,
            'user_id' => $user->id,
            'payment_channel_id' => $channelId,
            'amount_idr' => 10000,
            'fee_idr' => 700,
            'total_idr' => 10700,
            'status' => 'PENDING_PAYMENT',
            'idempotency_key' => 'm7-topup-direct-0001',
            'request_fingerprint' => hash('sha256', 'topup'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $paymentId = DB::table('payment_transactions')->insertGetId([
            'wallet_topup_id' => $topupId,
            'gateway_code' => 'MIDTRANS',
            'channel_code' => 'qris',
            'merchant_reference' => 'TOPUP-TEST-1',
            'amount_idr' => 10700,
            'status' => 'PENDING',
            'idempotency_key' => 'm7-topup-payment-0001',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $states = app(PaymentStateService::class);
        $states->apply($paymentId, 'PAID');
        $states->apply($paymentId, 'PAID');

        $this->assertSame(10000, (int) DB::table('wallets')->where('id', $wallet->id)->value('balance_idr'));
        $this->assertSame(1, DB::table('wallet_ledger')->where('source', 'TOPUP')->count());
        $this->assertSame('PAID', DB::table('wallet_topups')->where('id', $topupId)->value('status'));

        $states->apply($paymentId, 'REFUNDED');
        Log::spy();
        $ignored = $states->apply($paymentId, 'PAID');

        $this->assertSame('IGNORED_FINAL', $ignored['result']);
        Log::shouldHaveReceived('warning')->once()->with(
            'Payment webhook PAID arrived after REFUNDED',
            [
                'payment_transaction_id' => $paymentId,
                'order_id' => null,
                'gateway_code' => 'MIDTRANS',
            ]
        );

        $this->assertSame(10000, (int) DB::table('wallets')->where('id', $wallet->id)->value('balance_idr'));
        $this->assertSame(0, DB::table('wallet_ledger')->where('source', 'REFUND')->count());
        $this->assertSame('REFUND_REVIEW', DB::table('wallet_topups')->where('id', $topupId)->value('status'));
        $this->assertSame('REFUNDED', DB::table('payment_transactions')->where('id', $paymentId)->value('status'));
    }

    public function test_refunded_topup_with_spent_balance_enters_review_and_can_be_reconciled_safely(): void
    {
        $user = User::create([
            'name' => 'Refund Review Buyer',
            'email' => 'refund-review@example.test',
            'phone' => '081234567894',
            'password' => Hash::make('StrongPassword123!'),
            'email_verified_at' => now(),
        ]);
        $wallet = DB::table('wallets')->where('user_id', $user->id)->firstOrFail();
        $channelId = DB::table('payment_channels')->where('code', 'qris')->value('id');

        $topupId = DB::table('wallet_topups')->insertGetId([
            'wallet_id' => $wallet->id,
            'user_id' => $user->id,
            'payment_channel_id' => $channelId,
            'amount_idr' => 10000,
            'fee_idr' => 700,
            'total_idr' => 10700,
            'status' => 'PENDING_PAYMENT',
            'idempotency_key' => 'refund-review-topup-0001',
            'request_fingerprint' => hash('sha256', 'refund-review-topup'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $paymentId = DB::table('payment_transactions')->insertGetId([
            'wallet_topup_id' => $topupId,
            'gateway_code' => 'MIDTRANS',
            'channel_code' => 'qris',
            'merchant_reference' => 'REFUND-REVIEW-TEST-1',
            'amount_idr' => 10700,
            'status' => 'PENDING',
            'idempotency_key' => 'refund-review-payment-0001',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $states = app(PaymentStateService::class);
        $states->apply($paymentId, 'PAID');
        DB::table('wallets')->where('id', $wallet->id)->update([
            'balance_idr' => 3000,
            'updated_at' => now(),
        ]);

        $result = $states->apply($paymentId, 'REFUNDED');
        $this->assertSame('REFUND_REVIEW', $result['status']);
        $this->assertSame('REFUND_REVIEW', DB::table('wallet_topups')->where('id', $topupId)->value('status'));
        $this->assertSame('REFUNDED', DB::table('payment_transactions')->where('id', $paymentId)->value('status'));
        $this->assertSame(0, DB::table('wallet_ledger')
            ->where('idempotency_key', 'wallet-topup-refund:'.$paymentId)->count());

        try {
            $states->closeTopupRefundReview($topupId, '   ');
            $this->fail('Alasan penutupan wajib diisi.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reason', $exception->errors());
        }

        // Menutup tinjauan TIDAK menggerakkan uang: saldo tetap 3.000.
        $closed = $states->closeTopupRefundReview($topupId, 'Dana sudah dibalik gateway, saldo disesuaikan manual');
        try {
            $states->closeTopupRefundReview($topupId, 'Ditutup dua kali');
            $this->fail('Tinjauan yang sudah ditutup tidak boleh ditutup lagi.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('topup', $exception->errors());
        }

        $this->assertSame('CLOSED', $closed['result']);
        $this->assertSame('REFUNDED', $closed['status']);
        $this->assertSame(3000, $closed['balance_idr']);
        $this->assertSame(3000, (int) DB::table('wallets')->where('id', $wallet->id)->value('balance_idr'));
        $this->assertSame('REFUNDED', DB::table('wallet_topups')->where('id', $topupId)->value('status'));
        $this->assertSame(0, DB::table('wallet_ledger')
            ->where('idempotency_key', 'wallet-topup-refund:'.$paymentId)->count());
        $this->assertDatabaseHas('admin_notifications', [
            'event_type' => 'wallet.topup_refund_review_closed',
            'target_id' => (string) $topupId,
        ]);

        // Klik kedua: ditolak, bukan menambah audit atau mengubah saldo.
        try {
            $states->closeTopupRefundReview($topupId, 'Percobaan kedua');
            $this->fail('Tinjauan yang sudah ditutup tidak boleh ditutup lagi.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('topup', $exception->errors());
        }

        $this->assertSame(3000, (int) DB::table('wallets')->where('id', $wallet->id)->value('balance_idr'));
        $this->assertSame(1, DB::table('admin_notifications')
            ->where('event_type', 'wallet.topup_refund_review_closed')->count());
    }

    public function test_topup_payment_start_is_rejected_after_expiry_and_marks_topup_expired(): void
    {
        $routeId = $this->route('qris', 'MIDTRANS', 0, 70, 'qris');
        $user = User::create([
            'name' => 'Expired Topup Buyer',
            'email' => 'expired-topup@example.test',
            'phone' => '081234567801',
            'password' => Hash::make('StrongPassword123!'),
            'email_verified_at' => now(),
        ]);
        $wallet = DB::table('wallets')->where('user_id', $user->id)->firstOrFail();
        $channelId = DB::table('payment_channels')->where('code', 'qris')->value('id');
        $topupId = DB::table('wallet_topups')->insertGetId([
            'wallet_id' => $wallet->id,
            'user_id' => $user->id,
            'payment_channel_id' => $channelId,
            'payment_route_id' => $routeId,
            'amount_idr' => 10000,
            'fee_idr' => 700,
            'total_idr' => 10700,
            'status' => 'PENDING_PAYMENT',
            'idempotency_key' => 'pr5-expired-topup-0001',
            'request_fingerprint' => hash('sha256', 'pr5-expired-topup'),
            'expires_at' => now()->subMinute(),
            'created_at' => now()->subMinutes(31),
            'updated_at' => now()->subMinute(),
        ]);
        $topup = DB::table('wallet_topups')->where('id', $topupId)->firstOrFail();

        try {
            app(PaymentService::class)->startTopup(
                $topup, 'payment-pr5-expired-topup-0001', $user->name, $user->email, (string) $user->phone
            );
            $this->fail('Top up kedaluwarsa tidak boleh dimulai pembayarannya.');
        } catch (ValidationException $exception) {
            $this->assertSame('Top up sudah kedaluwarsa.', $exception->errors()['payment'][0]);
        }

        $this->assertSame(0, DB::table('payment_transactions')->where('wallet_topup_id', $topupId)->count());
        $this->assertSame('EXPIRED', DB::table('wallet_topups')->where('id', $topupId)->value('status'));
    }

    public function test_expire_unpaid_topups_command_expires_only_overdue_pending_topups(): void
    {
        $user = User::create([
            'name' => 'Topup Expiry Buyer',
            'email' => 'topup-expiry@example.test',
            'phone' => '081234567802',
            'password' => Hash::make('StrongPassword123!'),
            'email_verified_at' => now(),
        ]);
        $wallet = DB::table('wallets')->where('user_id', $user->id)->firstOrFail();
        $channelId = DB::table('payment_channels')->where('code', 'qris')->value('id');
        $seed = function (string $key, string $status, $expiresAt) use ($wallet, $user, $channelId): int {
            return DB::table('wallet_topups')->insertGetId([
                'wallet_id' => $wallet->id,
                'user_id' => $user->id,
                'payment_channel_id' => $channelId,
                'amount_idr' => 10000,
                'fee_idr' => 700,
                'total_idr' => 10700,
                'status' => $status,
                'idempotency_key' => $key,
                'request_fingerprint' => hash('sha256', $key),
                'expires_at' => $expiresAt,
                'created_at' => now()->subHour(),
                'updated_at' => now()->subHour(),
            ]);
        };
        $overdue = $seed('pr5-expiry-overdue', 'PENDING_PAYMENT', now()->subMinute());
        $fresh = $seed('pr5-expiry-fresh', 'PENDING_PAYMENT', now()->addMinutes(10));
        $paidOverdue = $seed('pr5-expiry-paid', 'PAID', now()->subMinute());

        Artisan::call('lfamilia:expire-unpaid-topups');

        $this->assertSame('EXPIRED', DB::table('wallet_topups')->where('id', $overdue)->value('status'));
        $this->assertSame('PENDING_PAYMENT', DB::table('wallet_topups')->where('id', $fresh)->value('status'));
        $this->assertSame('PAID', DB::table('wallet_topups')->where('id', $paidOverdue)->value('status'));
    }

    public function test_late_paid_for_terminal_topup_goes_to_review_without_crediting(): void
    {
        $states = app(PaymentStateService::class);
        foreach (['EXPIRED', 'FAILED', 'CANCELLED'] as $index => $terminalStatus) {
            $user = User::create([
                'name' => 'Late Paid Buyer '.$terminalStatus,
                'email' => 'late-paid-'.strtolower($terminalStatus).'@example.test',
                'phone' => '08123456781'.$index,
                'password' => Hash::make('StrongPassword123!'),
                'email_verified_at' => now(),
            ]);
            $wallet = DB::table('wallets')->where('user_id', $user->id)->firstOrFail();
            $channelId = DB::table('payment_channels')->where('code', 'qris')->value('id');
            $topupId = DB::table('wallet_topups')->insertGetId([
                'wallet_id' => $wallet->id,
                'user_id' => $user->id,
                'payment_channel_id' => $channelId,
                'amount_idr' => 10000,
                'fee_idr' => 700,
                'total_idr' => 10700,
                'status' => $terminalStatus,
                'idempotency_key' => 'pr5-late-paid-'.strtolower($terminalStatus),
                'request_fingerprint' => hash('sha256', 'pr5-late-paid-'.strtolower($terminalStatus)),
                'expires_at' => now()->subMinutes(40),
                'created_at' => now()->subHour(),
                'updated_at' => now()->subMinutes(30),
            ]);
            $paymentId = DB::table('payment_transactions')->insertGetId([
                'wallet_topup_id' => $topupId,
                'gateway_code' => 'MIDTRANS',
                'channel_code' => 'qris',
                'merchant_reference' => 'TOPUP-LATE-'.$terminalStatus,
                'amount_idr' => 10700,
                'status' => 'PENDING',
                'idempotency_key' => 'pr5-late-paid-payment-'.strtolower($terminalStatus),
                'created_at' => now()->subHour(),
                'updated_at' => now()->subMinutes(30),
            ]);

            $result = $states->apply($paymentId, 'PAID');

            $this->assertSame('LATE_PAID_REVIEW', $result['result'], $terminalStatus);
            $this->assertSame($terminalStatus, $result['status'], $terminalStatus);
            $this->assertSame('PAID', DB::table('payment_transactions')->where('id', $paymentId)->value('status'));
            $this->assertSame($terminalStatus, DB::table('wallet_topups')->where('id', $topupId)->value('status'));
            $this->assertSame(0, (int) DB::table('wallets')->where('id', $wallet->id)->value('balance_idr'));
            $this->assertSame(0, DB::table('wallet_ledger')->where('wallet_id', $wallet->id)->where('source', 'TOPUP')->count());
            $this->assertDatabaseHas('admin_notifications', [
                'event_type' => 'payment.late_verified',
                'target_id' => (string) $topupId,
            ]);
        }
    }

    public function test_paid_callback_after_topup_deadline_expires_topup_without_crediting(): void
    {
        $user = User::create([
            'name' => 'Deadline Paid Buyer',
            'email' => 'deadline-paid@example.test',
            'phone' => '081234567803',
            'password' => Hash::make('StrongPassword123!'),
            'email_verified_at' => now(),
        ]);
        $wallet = DB::table('wallets')->where('user_id', $user->id)->firstOrFail();
        $channelId = DB::table('payment_channels')->where('code', 'qris')->value('id');
        $topupId = DB::table('wallet_topups')->insertGetId([
            'wallet_id' => $wallet->id,
            'user_id' => $user->id,
            'payment_channel_id' => $channelId,
            'amount_idr' => 10000,
            'fee_idr' => 700,
            'total_idr' => 10700,
            'status' => 'PENDING_PAYMENT',
            'idempotency_key' => 'pr5-deadline-topup-0001',
            'request_fingerprint' => hash('sha256', 'pr5-deadline-topup'),
            'expires_at' => now()->subMinute(),
            'created_at' => now()->subMinutes(31),
            'updated_at' => now()->subMinutes(2),
        ]);
        $paymentId = DB::table('payment_transactions')->insertGetId([
            'wallet_topup_id' => $topupId,
            'gateway_code' => 'MIDTRANS',
            'channel_code' => 'qris',
            'merchant_reference' => 'TOPUP-DEADLINE-1',
            'amount_idr' => 10700,
            'status' => 'PENDING',
            'idempotency_key' => 'pr5-deadline-payment-0001',
            'created_at' => now()->subMinutes(31),
            'updated_at' => now()->subMinutes(2),
        ]);

        $result = app(PaymentStateService::class)->apply($paymentId, 'PAID');

        $this->assertSame('LATE_PAID_REVIEW', $result['result']);
        $this->assertSame('EXPIRED', $result['status']);
        $this->assertSame('EXPIRED', DB::table('wallet_topups')->where('id', $topupId)->value('status'));
        $this->assertSame('PAID', DB::table('payment_transactions')->where('id', $paymentId)->value('status'));
        $this->assertSame(0, (int) DB::table('wallets')->where('id', $wallet->id)->value('balance_idr'));
        $this->assertSame(0, DB::table('wallet_ledger')->where('wallet_id', $wallet->id)->where('source', 'TOPUP')->count());
    }
}
