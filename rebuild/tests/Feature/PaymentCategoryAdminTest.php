<?php

namespace Tests\Feature;

use App\Services\PaymentRoutingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PaymentCategoryAdminTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $admin = $this->createAdmin([
            'name' => 'Kategori Pembayaran',
            'email' => 'payment-category-'.bin2hex(random_bytes(5)).'@example.test',
            'password' => bcrypt('category-test-password'),
            'role' => 'SUPER_ADMIN',
            'permissions' => ['payments.manage'],
            'is_active' => true,
        ]);
        $this->actingAs($admin, 'admin');
    }

    public function test_category_crud_and_channel_assignment_do_not_change_gateway_method(): void
    {
        $this->get('/admin/payments')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Payments')->has('categories', 6)->etc());

        $this->post('/admin/payments/categories', [
            'code' => 'transfer_custom', 'name' => 'Transfer Pilihan',
            'description' => 'Pilih bank favorit', 'sort_order' => 12, 'is_active' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $channel = DB::table('payment_channels')->where('code', 'virtual_account')->firstOrFail();
        $this->put('/admin/payments/channels/'.$channel->id, [
            'code' => $channel->code, 'name' => $channel->name,
            'method' => $channel->method, 'category_code' => 'transfer_custom',
            'description' => $channel->description, 'fee_flat_idr' => (int) $channel->fee_flat_idr,
            'fee_percent_bps' => (int) $channel->fee_percent_bps,
            'supports_order' => (bool) $channel->supports_order,
            'supports_wallet_topup' => (bool) $channel->supports_wallet_topup,
            'sort_order' => (int) $channel->sort_order, 'is_active' => (bool) $channel->is_active,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('VIRTUAL_ACCOUNT', DB::table('payment_channels')->where('id', $channel->id)->value('method'));
        $this->assertSame('transfer_custom', DB::table('payment_channels')->where('id', $channel->id)->value('category_code'));

        $this->delete('/admin/payments/categories/transfer_custom')->assertSessionHasErrors('category');
        $this->put('/admin/payments/categories/transfer_custom', [
            'name' => 'Transfer Bank', 'description' => 'Metode transfer',
            'sort_order' => 4, 'is_active' => false,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(0, (int) DB::table('payment_categories')->where('code', 'transfer_custom')->value('is_active'));
        $this->assertNull(collect(app(PaymentRoutingService::class)->publicOrderChannels(null))
            ->firstWhere('code', 'virtual_account'));
    }

    public function test_wallet_category_cannot_be_deleted_or_reassigned(): void
    {
        $this->delete('/admin/payments/categories/wallet')->assertSessionHasErrors('category');
        $wallet = DB::table('payment_channels')->where('code', 'saldo')->firstOrFail();
        $this->put('/admin/payments/channels/'.$wallet->id, [
            'code' => $wallet->code, 'method' => 'WALLET',
            'category_code' => 'qris', 'name' => $wallet->name,
            'description' => $wallet->description, 'fee_flat_idr' => 0,
            'fee_percent_bps' => 0, 'supports_order' => true,
            'supports_wallet_topup' => false, 'sort_order' => (int) $wallet->sort_order,
            'is_active' => (bool) $wallet->is_active,
        ])->assertSessionHasErrors('category_code');
    }

    public function test_sync_restores_payment_methods_under_the_correct_categories(): void
    {
        // Simulate deleting unused preset/bank/e-wallet/retail channels.
        // The sync operation must recreate the category, not rely on the
        // one-time migration backfill or the generic 'other' default.
        $expected = [
            'saldo' => 'wallet',
            'va_bss' => 'va',
            'ewallet_linkaja' => 'ewallet',
            'retail_indomaret' => 'retail',
        ];

        foreach (array_keys($expected) as $code) {
            $id = DB::table('payment_channels')->where('code', $code)->value('id');
            if ($id !== null) {
                DB::table('payment_routes')->where('payment_channel_id', $id)->delete();
                DB::table('payment_channels')->where('id', $id)->delete();
            }
        }

        $this->post('/admin/payments/channels/sync')
            ->assertRedirect()->assertSessionHasNoErrors();

        foreach ($expected as $code => $categoryCode) {
            $this->assertDatabaseHas('payment_channels', [
                'code' => $code,
                'category_code' => $categoryCode,
            ]);
        }

        // A second sync must retain the categories and not overwrite
        // merchant-selected display categories on existing methods.
        DB::table('payment_channels')->where('code', 'va_bss')
            ->update(['category_code' => 'other']);
        $this->post('/admin/payments/channels/sync')
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('other', DB::table('payment_channels')
            ->where('code', 'va_bss')->value('category_code'));
        $this->assertSame('wallet', DB::table('payment_channels')
            ->where('code', 'saldo')->value('category_code'));
    }

}
