<?php

namespace Tests\Feature;

use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CustomerAreaTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function customer(string $email): User
    {
        return User::create([
            'name' => 'Customer',
            'email' => $email,
            'phone' => '081234567890',
            'password' => Hash::make('old-password-123'),
        ]);
    }

    private function order(?User $user, string $number): int
    {
        $category = DB::table('categories')->insertGetId([
            'name' => 'Game '.$number, 'slug' => 'game-'.$number,
        ]);
        $product = DB::table('products')->insertGetId([
            'category_id' => $category, 'name' => 'Game '.$number, 'slug' => 'product-'.$number,
        ]);
        $package = DB::table('product_packages')->insertGetId([
            'product_id' => $product, 'code' => $number, 'name' => '10 Unit',
        ]);

        return DB::table('orders')->insertGetId([
            'order_number' => $number,
            'user_id' => $user?->id,
            'guest_email' => $user ? null : 'guest@example.test',
            'product_id' => $product,
            'product_package_id' => $package,
            'customer_input' => json_encode(['id' => '123']),
            'snapshot' => json_encode(['product' => 'Game']),
            'cost_idr' => 9000,
            'margin_idr' => 1000,
            'total_idr' => 10000,
            'idempotency_key' => $number,
        ]);
    }

    public function test_account_wallet_tiers_and_orders_are_scoped_to_customer(): void
    {
        $owner = $this->customer('owner@example.test');
        $other = $this->customer('other@example.test');
        $ownOrder = $this->order($owner, 'OWN-100');
        $otherOrder = $this->order($other, 'OTHER-100');

        $this->get('/account')->assertRedirect(route('login'));
        $this->actingAs($owner, 'web');
        $this->get('/account')->assertOk();
        $this->get('/account/wallet')->assertOk();
        $this->get('/account/membership')->assertOk();
        $this->get('/account/orders')->assertOk();
        $this->get('/account/orders/'.$ownOrder)->assertOk();
        $this->get('/account/orders/'.$otherOrder)->assertNotFound();
        $this->assertSame(0, (int) DB::table('wallets')->where('user_id', $owner->id)->value('balance_idr'));
        $this->assertSame(2, DB::table('wallets')->count());
    }

    public function test_profile_password_and_deletion_checks(): void
    {
        $user = $this->customer('profile@example.test');
        $this->actingAs($user, 'web');

        $this->put('/account/profile', [
            'name' => 'Updated', 'email' => 'new@example.test', 'phone' => '081298765432',
        ])->assertSessionHasErrors('current_password');
        $this->put('/account/profile', [
            'name' => 'Updated', 'email' => 'new@example.test', 'phone' => '081298765432',
            'current_password' => 'old-password-123',
        ])->assertRedirect();
        $this->assertSame('new@example.test', $user->fresh()->email);
        $this->assertNull($user->fresh()->email_verified_at);

        $this->put('/account/password', [
            'current_password' => 'wrong',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertSessionHasErrors('current_password');
        $this->put('/account/password', [
            'current_password' => 'old-password-123',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertRedirect();
        $this->assertTrue(Hash::check('new-password-123', $user->fresh()->password));

        SupportTicket::create([
            'user_id' => $user->id, 'subject' => 'Help', 'message' => 'My account',
        ]);
        $this->delete('/account', [
            'confirmation' => 'HAPUS', 'password' => 'new-password-123',
        ])->assertSessionHasErrors('account');
        $this->assertNull($user->fresh()->deleted_at);

        SupportTicket::where('user_id', $user->id)->delete();
        $this->delete('/account', [
            'confirmation' => 'HAPUS', 'password' => 'new-password-123',
        ])->assertRedirect('/');
        $deleted = User::withTrashed()->findOrFail($user->id);
        $this->assertNull($deleted->email);
        $this->assertNotNull($deleted->deleted_at);
        $this->assertGuest('web');
    }

    public function test_support_ticket_cannot_reference_another_customers_order(): void
    {
        $owner = $this->customer('support-owner@example.test');
        $other = $this->customer('support-other@example.test');
        $otherOrder = $this->order($other, 'OTHER-200');

        $this->actingAs($owner, 'web')->post('/account/tickets', [
            'subject' => 'Help', 'message' => 'Please check', 'order_id' => $otherOrder,
        ])->assertSessionHasErrors('order_id');
        $this->assertSame(0, SupportTicket::count());

        $this->post('/account/tickets', [
            'subject' => 'Help', 'message' => 'Please check',
        ])->assertRedirect();
        $ticket = SupportTicket::firstOrFail();
        $this->get('/account/tickets/'.$ticket->id)->assertOk();
        $this->actingAs($other, 'web')->get('/account/tickets/'.$ticket->id)->assertNotFound();
    }

    public function test_cleanup_keeps_accounts_with_balance_and_deletes_empty_inactive_accounts(): void
    {
        $empty = $this->customer('empty@example.test');
        $funded = $this->customer('funded@example.test');
        foreach ([$empty, $funded] as $user) {
            $user->forceFill(['created_at' => now()->subDays(31), 'last_active_at' => now()->subDays(31)])->save();
        }
        DB::table('wallets')->where('user_id', $funded->id)->update(['balance_idr' => 500]);

        $this->artisan('lfamilia:cleanup-empty-customers')->assertExitCode(0);
        $this->assertNotNull(User::withTrashed()->findOrFail($empty->id)->deleted_at);
        $this->assertNull($funded->fresh()->deleted_at);
        $this->assertSame(500, (int) DB::table('wallets')->where('user_id', $funded->id)->value('balance_idr'));
    }
}
