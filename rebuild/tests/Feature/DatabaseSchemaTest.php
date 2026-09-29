<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DatabaseSchemaTest extends TestCase
{
    use DatabaseTransactions;

    public function test_membership_order_and_initial_provider_status_match_the_prd(): void
    {
        $this->assertSame(
            ['BASIC', 'SILVER', 'GOLD', 'DIAMOND', 'PLATINUM', 'MAFIA'],
            DB::table('membership_tiers')->orderBy('rank')->pluck('code')->all()
        );

        $this->assertSame(
            ['DIGIFLAZZ', 'MANUAL'],
            DB::table('providers')->orderBy('id')->pluck('code')->all()
        );

        $this->assertSame(0, DB::table('providers')->where('is_active', true)->count());
    }

    public function test_wallet_ledger_accepts_a_valid_debit_and_rejects_an_invalid_balance(): void
    {
        $userId = DB::table('users')->insertGetId(['name' => 'Customer']);
        $walletId = DB::table('wallets')->insertGetId(['user_id' => $userId, 'balance_minor' => 900]);

        DB::table('wallet_ledger')->insert([
            'wallet_id' => $walletId,
            'amount_minor' => -100,
            'balance_before_minor' => 1000,
            'balance_after_minor' => 900,
            'source' => 'CHECKOUT',
            'reference_type' => 'ORDER',
            'reference_id' => 'test-order-1',
            'idempotency_key' => 'test-debit-1',
        ]);

        $this->expectException(QueryException::class);
        DB::table('wallet_ledger')->insert([
            'wallet_id' => $walletId,
            'amount_minor' => -100,
            'balance_before_minor' => 900,
            'balance_after_minor' => 900,
            'source' => 'CHECKOUT',
            'reference_type' => 'ORDER',
            'reference_id' => 'test-order-2',
            'idempotency_key' => 'test-debit-2',
        ]);
    }

    public function test_guest_cannot_have_a_wallet(): void
    {
        $this->expectException(QueryException::class);
        DB::table('wallets')->insert(['user_id' => 999999]);
    }

    public function test_zero_total_order_is_rejected_by_mysql(): void
    {
        $categoryId = DB::table('categories')->insertGetId(['name' => 'Game', 'slug' => 'game']);
        $productId = DB::table('products')->insertGetId([
            'category_id' => $categoryId,
            'name' => 'Test Product',
            'slug' => 'test-product',
        ]);
        $packageId = DB::table('product_packages')->insertGetId([
            'product_id' => $productId,
            'code' => 'TEST1',
            'name' => '1 Unit',
        ]);

        $this->expectException(QueryException::class);
        DB::table('orders')->insert([
            'order_number' => 'test-zero-order',
            'product_id' => $productId,
            'product_package_id' => $packageId,
            'customer_input' => json_encode(['user_id' => '123']),
            'snapshot' => json_encode(['package' => 'TEST1']),
            'cost_minor' => 0,
            'margin_minor' => 0,
            'total_minor' => 0,
            'idempotency_key' => 'test-zero-order',
        ]);
    }
}
