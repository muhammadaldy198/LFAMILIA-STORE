<?php

namespace Tests\Feature;

use App\Services\GuestOrderAccess;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GuestOrderTest extends TestCase
{
    use DatabaseTransactions;

    private function guestOrder(string $number): int
    {
        $category = DB::table('categories')->insertGetId([
            'name' => 'Guest Game '.$number, 'slug' => 'guest-game-'.$number,
        ]);
        $product = DB::table('products')->insertGetId([
            'category_id' => $category, 'name' => 'Guest Game', 'slug' => 'guest-product-'.$number,
        ]);
        $package = DB::table('product_packages')->insertGetId([
            'product_id' => $product, 'code' => $number, 'name' => '10 Unit',
        ]);

        return DB::table('orders')->insertGetId([
            'order_number' => $number,
            'guest_email' => 'guest@example.test',
            'product_id' => $product,
            'product_package_id' => $package,
            'customer_input' => json_encode(['id' => '123']),
            'snapshot' => json_encode(['product' => 'Guest Game']),
            'cost_idr' => 9000,
            'margin_idr' => 1000,
            'total_idr' => 10000,
            'idempotency_key' => $number,
        ]);
    }

    public function test_guest_requires_correct_order_number_and_secret_code(): void
    {
        $first = $this->guestOrder('GUEST-100');
        $second = $this->guestOrder('GUEST-200');
        $code = app(GuestOrderAccess::class)->issue($first);

        $this->assertSame(0, DB::table('wallets')->count());
        $this->assertSame(hash('sha256', $code), DB::table('guest_order_tokens')
            ->where('order_id', $first)->value('token_hash'));
        $this->get('/orders/guest/GUEST-100')->assertNotFound();

        $this->post('/orders/check', [
            'order_number' => 'GUEST-200', 'access_code' => $code,
        ])->assertSessionHasErrors('order_number');
        $this->post('/orders/check', [
            'order_number' => 'GUEST-100', 'access_code' => $code,
        ])->assertRedirect(route('guest.orders.show', 'GUEST-100'));

        $this->get('/orders/guest/GUEST-100')->assertOk();
        $this->get('/orders/guest/GUEST-200')->assertNotFound();

        $this->assertFalse(app(GuestOrderAccess::class)->matches($second, $code));
    }

    public function test_reissuing_code_invalidates_old_code(): void
    {
        $id = $this->guestOrder('GUEST-300');
        $access = app(GuestOrderAccess::class);
        $old = $access->issue($id);
        $new = $access->issue($id);

        $this->assertFalse($access->matches($id, $old));
        $this->assertTrue($access->matches($id, $new));
    }
}
