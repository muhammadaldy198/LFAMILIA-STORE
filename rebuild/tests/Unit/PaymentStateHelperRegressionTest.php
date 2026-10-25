<?php

namespace Tests\Unit;

use App\Models\Category;
use App\Models\Product;
use App\Services\PaymentStateService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PaymentStateHelperRegressionTest extends TestCase
{
    use DatabaseTransactions;

    public function test_apply_reaches_order_topup_expiry_paid_and_voucher_helpers(): void
    {
        Queue::fake();
        $category = Category::where('slug', 'game')->firstOrFail();
        $product = Product::create([
            'category_id' => $category->id, 'name' => 'Payment helper fixture',
            'slug' => 'payment-helper-'.bin2hex(random_bytes(5)), 'margin_percent' => 10,
            'fulfillment_mode' => 'MANUAL', 'is_active' => true,
        ]);
        $packageId = $product->packages()->create([
            'code' => 'HELP'.bin2hex(random_bytes(3)), 'name' => 'Fixture',
            'nominal_value' => 1, 'is_active' => true,
        ])->id;
        $orderId = DB::table('orders')->insertGetId([
            'order_number' => 'HELP-'.bin2hex(random_bytes(5)), 'product_id' => $product->id,
            'product_package_id' => $packageId, 'status' => 'PENDING_PAYMENT', 'currency' => 'IDR',
            'customer_input' => '{}', 'snapshot' => '{}', 'cost_idr' => 100, 'margin_idr' => 100,
            'total_idr' => 200, 'idempotency_key' => 'help-'.bin2hex(random_bytes(8)),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $paymentId = DB::table('payment_transactions')->insertGetId([
            'order_id' => $orderId, 'gateway_code' => 'MANUAL_QRIS', 'channel_code' => 'manual_qris',
            'amount_idr' => 200, 'status' => 'PENDING', 'idempotency_key' => 'help-pay-'.bin2hex(random_bytes(8)),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertSame('PAID', app(PaymentStateService::class)->apply($paymentId, 'PAID')['status']);

        $userId = DB::table('users')->insertGetId([
            'name' => 'Payment helper', 'email' => 'helper-'.bin2hex(random_bytes(4)).'@example.test',
            'password' => bcrypt('fixture-only'), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $walletId = DB::table('wallets')->insertGetId([
            'user_id' => $userId, 'balance_idr' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $topupId = DB::table('wallet_topups')->insertGetId([
            'wallet_id' => $walletId, 'user_id' => $userId, 'amount_idr' => 300, 'fee_idr' => 0,
            'total_idr' => 300, 'status' => 'PENDING_PAYMENT', 'idempotency_key' => 'help-topup-'.bin2hex(random_bytes(8)),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $topupPaymentId = DB::table('payment_transactions')->insertGetId([
            'wallet_topup_id' => $topupId, 'gateway_code' => 'MIDTRANS', 'channel_code' => 'qris',
            'amount_idr' => 300, 'status' => 'PENDING', 'idempotency_key' => 'help-topay-'.bin2hex(random_bytes(8)),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertSame('PAID', app(PaymentStateService::class)->apply($topupPaymentId, 'PAID')['status']);
        $this->assertSame(300, (int) DB::table('wallets')->where('id', $walletId)->value('balance_idr'));

        $expiredId = DB::table('orders')->insertGetId([
            'order_number' => 'HELP-'.bin2hex(random_bytes(5)), 'product_id' => $product->id,
            'product_package_id' => $packageId, 'status' => 'PENDING_PAYMENT', 'currency' => 'IDR',
            'customer_input' => '{}', 'snapshot' => '{}', 'cost_idr' => 100, 'margin_idr' => 100,
            'total_idr' => 200, 'idempotency_key' => 'help-'.bin2hex(random_bytes(8)),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $voucherId = DB::table('vouchers')->insertGetId([
            'code' => 'HELP-'.bin2hex(random_bytes(4)), 'discount_type' => 'FIXED', 'discount_value' => 1,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('voucher_redemptions')->insert([
            'voucher_id' => $voucherId, 'order_id' => $expiredId, 'status' => 'RESERVED',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        app(PaymentStateService::class)->expireOrder((object) ['id' => $expiredId, 'status' => 'PENDING_PAYMENT']);
        $this->assertSame('RELEASED', DB::table('voucher_redemptions')->where('order_id', $expiredId)->value('status'));
    }
}
