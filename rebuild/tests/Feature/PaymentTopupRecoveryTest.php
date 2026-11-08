<?php

namespace Tests\Feature;

use App\Jobs\SendTransactionalEmailJob;
use App\Models\Category;
use App\Models\IntegrationCredential;
use App\Models\Product;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\PaymentStateService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Tes pemulihan dan pengulangan topup: pembuatan ulang topup
 * yang tersangkut, pembekuan tinjauan pengembalian pada kunci
 * dompet, penghentian sesudah tenggat, dan percobaan ulang
 * sesudah kedaluwarsa. Tes siklus topup normal ada di
 * PaymentTopupTest.
 */
class PaymentTopupRecoveryTest extends TestCase
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

    public function test_recovery_redrives_topup_creation_when_gateway_has_no_transaction(): void
    {
        $routeId = $this->route('qris', 'MIDTRANS', 0, 70, 'qris');
        $this->seedMidtransCredential();
        $user = User::create([
            'name' => 'Recovery Topup Buyer',
            'email' => 'recovery-topup@example.test',
            'phone' => '081234567893',
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
            'idempotency_key' => 'pr4-topup-direct-0001',
            'request_fingerprint' => hash('sha256', 'pr4-topup'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $reference = 'TOP-PR4-REDRIVE-'.strtoupper(bin2hex(random_bytes(3)));
        $paymentId = $this->creatingPaymentRow($routeId, null, $reference, 10700, 'MIDTRANS', 'qris', (int) $topupId);

        $creates = [];
        Http::fake(function ($request) use (&$creates) {
            if (str_contains($request->url(), '/status')) {
                return Http::response([
                    'status_code' => '404',
                    'status_message' => "Transaction doesn't exist.",
                ], 404);
            }
            if (str_contains($request->url(), 'snap/v1/transactions')) {
                $creates[] = json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR);

                return Http::response(['token' => 'topup-recovered-token', 'redirect_url' => 'https://example.test/topup']);
            }

            return Http::response([], 404);
        });

        Artisan::call('lfamilia:recover-creating-payments');

        $this->assertCount(1, $creates);
        $this->assertSame($reference, $creates[0]['transaction_details']['order_id']);
        $this->assertSame('PENDING', DB::table('payment_transactions')->where('id', $paymentId)->value('status'));
    }

    public function test_refund_review_freeze_is_serialized_on_the_wallet_lock(): void
    {
        $user = User::create([
            'name' => 'Freeze Lock Buyer',
            'email' => 'freeze-lock@example.test',
            'phone' => '081234567804',
            'password' => Hash::make('StrongPassword123!'),
        ]);
        // email_verified_at is not fillable; member checkout requires it.
        $user->forceFill(['email_verified_at' => now()])->save();
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
            'idempotency_key' => 'pr5-freeze-topup-0001',
            'request_fingerprint' => hash('sha256', 'pr5-freeze-topup'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $paymentId = DB::table('payment_transactions')->insertGetId([
            'wallet_topup_id' => $topupId,
            'gateway_code' => 'MIDTRANS',
            'channel_code' => 'qris',
            'merchant_reference' => 'TOPUP-FREEZE-1',
            'amount_idr' => 10700,
            'status' => 'PENDING',
            'idempotency_key' => 'pr5-freeze-payment-0001',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $states = app(PaymentStateService::class);
        $states->apply($paymentId, 'PAID');

        // During the REFUNDED transition the wallet row lock must be taken
        // BEFORE the top-up status update writes the freeze; without that
        // shared lock the freeze is not serialized against wallet spending.
        $refundQueries = [];
        DB::listen(function ($query) use (&$refundQueries): void {
            $refundQueries[] = strtolower($query->sql);
        });
        $states->apply($paymentId, 'REFUNDED');

        $walletLockAt = null;
        $topupUpdateAt = null;
        foreach ($refundQueries as $i => $sql) {
            if ($walletLockAt === null && str_contains($sql, 'from `wallets`') && str_contains($sql, 'for update')) {
                $walletLockAt = $i;
            }
            if ($topupUpdateAt === null && str_contains($sql, 'update `wallet_topups`')) {
                $topupUpdateAt = $i;
            }
        }
        $this->assertNotNull($walletLockAt, 'REFUNDED transition must lock the wallet row');
        $this->assertNotNull($topupUpdateAt);
        $this->assertLessThan($topupUpdateAt, $walletLockAt);
        $this->assertSame('REFUND_REVIEW', DB::table('wallet_topups')->where('id', $topupId)->value('status'));

        // During a wallet checkout the freeze check must run AFTER the
        // wallet row lock is taken, so it reads the freeze state serialized
        // by that lock instead of a pre-lock snapshot.
        $catalog = $this->catalog();
        $this->route('saldo', 'WALLET');
        DB::table('wallets')->where('id', $wallet->id)->update(['balance_idr' => 50000]);
        DB::table('wallet_topups')->where('id', $topupId)->update(['status' => 'REFUNDED']);
        $checkout = $this->actingAs($user)->postJson('/checkout/orders', [
            'package_id' => $catalog['package_id'],
            'payment_channel_code' => 'saldo',
            'customer_input' => ['user_id' => '777777'],
            'voucher_code' => null,
            'idempotency_key' => 'pr5-freeze-order-0001',
        ])->assertCreated();

        $payQueries = [];
        DB::listen(function ($query) use (&$payQueries): void {
            $payQueries[] = strtolower($query->sql);
        });
        $this->actingAs($user)->postJson('/payments/orders/'.$checkout->json('order_number'), [
            'idempotency_key' => 'pr5-freeze-payment-0002',
        ])->assertOk()->assertJsonPath('status', 'PAID');

        $payWalletLockAt = null;
        $freezeCheckAt = null;
        foreach ($payQueries as $i => $sql) {
            if ($payWalletLockAt === null && str_contains($sql, 'from `wallets`') && str_contains($sql, 'for update')) {
                $payWalletLockAt = $i;
            }
            if ($freezeCheckAt === null && str_contains($sql, 'from `wallet_topups`') && str_contains($sql, '`status`')) {
                $freezeCheckAt = $i;
            }
        }
        $this->assertNotNull($payWalletLockAt, 'wallet checkout must lock the wallet row');
        $this->assertNotNull($freezeCheckAt, 'wallet checkout must check the refund-review freeze');
        $this->assertLessThan($freezeCheckAt, $payWalletLockAt);
    }

    public function test_recovery_terminates_creating_topup_payment_after_the_topup_deadline(): void
    {
        $routeId = $this->route('qris', 'MIDTRANS', 0, 70, 'qris');
        $this->seedMidtransCredential();
        $user = User::create([
            'name' => 'Recovery Expired Topup Buyer',
            'email' => 'recovery-expired-topup@example.test',
            'phone' => '081234567805',
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
            'idempotency_key' => 'pr5-topup-expired-recovery-0001',
            'request_fingerprint' => hash('sha256', 'pr5-topup-expired-recovery'),
            'expires_at' => now()->subMinute(),
            'created_at' => now()->subMinutes(31),
            'updated_at' => now()->subMinutes(6),
        ]);
        $reference = 'TOP-PR5-EXPIRED-'.strtoupper(bin2hex(random_bytes(3)));
        $paymentId = $this->creatingPaymentRow($routeId, null, $reference, 10700, 'MIDTRANS', 'qris', (int) $topupId);

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

                return Http::response(['token' => 'must-not-happen', 'redirect_url' => 'https://example.test/nope']);
            }

            return Http::response([], 404);
        });

        Artisan::call('lfamilia:recover-creating-payments');

        $this->assertSame(0, $creates);
        $this->assertSame('FAILED', DB::table('payment_transactions')->where('id', $paymentId)->value('status'));
    }

    public function test_topup_retry_after_expiry_does_not_hand_back_existing_payment_instructions(): void
    {
        $routeId = $this->route('qris', 'MIDTRANS', 0, 70, 'qris');
        $user = User::create([
            'name' => 'Expired Retry Buyer',
            'email' => 'expired-retry@example.test',
            'phone' => '081234567806',
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
            'idempotency_key' => 'pr5-retry-topup-0001',
            'request_fingerprint' => hash('sha256', 'pr5-retry-topup'),
            'expires_at' => now()->subMinute(),
            'created_at' => now()->subMinutes(31),
            'updated_at' => now()->subMinutes(2),
        ]);
        // The payment created before the deadline, still pending: the
        // findExisting fast path must not return it as payable instructions.
        $fingerprint = hash('sha256', implode('|', ['topup', $topupId, 'qris', $routeId, 10700]));
        $paymentId = DB::table('payment_transactions')->insertGetId([
            'wallet_topup_id' => $topupId,
            'payment_route_id' => $routeId,
            'gateway_code' => 'MIDTRANS',
            'channel_code' => 'qris',
            'merchant_reference' => 'TOP-PR5-RETRY-1',
            'amount_idr' => 10700,
            'status' => 'PENDING',
            'request_fingerprint' => $fingerprint,
            'idempotency_key' => 'payment-pr5-retry-0001',
            'expires_at' => now()->addMinutes(5),
            'created_at' => now()->subMinutes(30),
            'updated_at' => now()->subMinutes(2),
        ]);
        $topup = DB::table('wallet_topups')->where('id', $topupId)->firstOrFail();

        try {
            app(PaymentService::class)->startTopup(
                $topup, 'payment-pr5-retry-0001', $user->name, $user->email, (string) $user->phone
            );
            $this->fail('Retry sesudah tenggat tidak boleh mengembalikan instruksi pembayaran lama.');
        } catch (ValidationException $exception) {
            $this->assertSame('Top up sudah kedaluwarsa.', $exception->errors()['payment'][0]);
        }

        $this->assertSame('EXPIRED', DB::table('wallet_topups')->where('id', $topupId)->value('status'));
        $this->assertSame('PENDING', DB::table('payment_transactions')->where('id', $paymentId)->value('status'));
    }

    public function test_topup_retry_for_already_expired_topup_does_not_hand_back_payment_instructions(): void
    {
        $routeId = $this->route('qris', 'MIDTRANS', 0, 70, 'qris');
        $user = User::create([
            'name' => 'Already Expired Buyer',
            'email' => 'already-expired@example.test',
            'phone' => '081234567807',
            'password' => Hash::make('StrongPassword123!'),
            'email_verified_at' => now(),
        ]);
        $wallet = DB::table('wallets')->where('user_id', $user->id)->firstOrFail();
        $channelId = DB::table('payment_channels')->where('code', 'qris')->value('id');
        // Already transitioned to EXPIRED by the scheduled command, which
        // deliberately does not touch payment rows: the payment below is
        // still PENDING and must not leak back as live instructions.
        $topupId = DB::table('wallet_topups')->insertGetId([
            'wallet_id' => $wallet->id,
            'user_id' => $user->id,
            'payment_channel_id' => $channelId,
            'payment_route_id' => $routeId,
            'amount_idr' => 10000,
            'fee_idr' => 700,
            'total_idr' => 10700,
            'status' => 'EXPIRED',
            'idempotency_key' => 'pr5-already-expired-topup-0001',
            'request_fingerprint' => hash('sha256', 'pr5-already-expired-topup'),
            'expires_at' => now()->subMinutes(10),
            'created_at' => now()->subMinutes(40),
            'updated_at' => now()->subMinutes(9),
        ]);
        $fingerprint = hash('sha256', implode('|', ['topup', $topupId, 'qris', $routeId, 10700]));
        $paymentId = DB::table('payment_transactions')->insertGetId([
            'wallet_topup_id' => $topupId,
            'payment_route_id' => $routeId,
            'gateway_code' => 'MIDTRANS',
            'channel_code' => 'qris',
            'merchant_reference' => 'TOP-PR5-EXPIRED-1',
            'amount_idr' => 10700,
            'status' => 'PENDING',
            'request_fingerprint' => $fingerprint,
            'idempotency_key' => 'payment-pr5-already-expired-0001',
            'expires_at' => now()->subMinutes(10),
            'created_at' => now()->subMinutes(39),
            'updated_at' => now()->subMinutes(9),
        ]);
        $topup = DB::table('wallet_topups')->where('id', $topupId)->firstOrFail();

        try {
            app(PaymentService::class)->startTopup(
                $topup, 'payment-pr5-already-expired-0001', $user->name, $user->email, (string) $user->phone
            );
            $this->fail('Retry untuk top up yang sudah EXPIRED tidak boleh mengembalikan instruksi pembayaran.');
        } catch (ValidationException $exception) {
            $this->assertSame('Top up sudah kedaluwarsa.', $exception->errors()['payment'][0]);
        }

        $this->assertSame('EXPIRED', DB::table('wallet_topups')->where('id', $topupId)->value('status'));
        $this->assertSame('PENDING', DB::table('payment_transactions')->where('id', $paymentId)->value('status'));
    }
}
