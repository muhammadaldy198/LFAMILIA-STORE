<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PaymentRecoveryTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function order(string $number, array $overrides = []): int
    {
        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'Recovery '.$number,
            'slug' => 'recovery-'.strtolower($number),
        ]);
        $productId = DB::table('products')->insertGetId([
            'category_id' => $categoryId, 'name' => 'Recovery Game',
            'slug' => 'recovery-product-'.strtolower($number),
        ]);
        $packageId = DB::table('product_packages')->insertGetId([
            'product_id' => $productId, 'code' => $number, 'name' => '10 Unit',
        ]);

        return DB::table('orders')->insertGetId(array_merge([
            'order_number' => $number,
            'guest_email' => 'recovery@example.test',
            'guest_phone' => '081234567890',
            'guest_phone_normalized' => '081234567890',
            'status' => 'PENDING_PAYMENT',
            'product_id' => $productId,
            'product_package_id' => $packageId,
            'customer_input' => json_encode(['id' => '123456']),
            'snapshot' => json_encode(['product' => 'Recovery Game']),
            'cost_idr' => 9000,
            'margin_idr' => 1000,
            'total_idr' => 10000,
            'idempotency_key' => $number,
            'expires_at' => now()->addMinutes(25),
        ], $overrides));
    }

    private function recover(string $number, string $phone = '081234567890'): TestResponse
    {
        return $this->postJson('/orders/track/recover', ['invoice' => $number, 'whatsapp' => $phone]);
    }

    public function test_valid_signed_page_opens_in_new_session_and_contains_qr_instructions(): void
    {
        $id = $this->order('LFRECOVERY-1');
        DB::table('payment_transactions')->insert([
            'order_id' => $id,
            'gateway_code' => 'MANUAL_QRIS',
            'channel_code' => 'manual_qris',
            'merchant_reference' => 'RECOVERY-1',
            'amount_idr' => 10000,
            'status' => 'PENDING',
            'public_payload' => json_encode(['kind' => 'manual_qris', 'qr_url' => 'https://lfamilia.com/qr.png']),
            'idempotency_key' => 'recovery-payment-1',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $url = $this->recover('LFRECOVERY-1')->assertOk()->assertJsonStructure(['payment_url'])
            ->json('payment_url');
        $this->assertStringContainsString('expires=', $url);
        $this->assertStringContainsString('signature=', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $params);
        $this->assertSame(
            Carbon::parse(DB::table('orders')->where('id', $id)->value('expires_at'))->getTimestamp(),
            (int) $params['expires'],
        );
        $this->withSession(['guest_order_id' => null])->get($url)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Payment/Show')
                ->where('order.order_number', 'LFRECOVERY-1')
                ->where('payment.instructions.kind', 'manual_qris')
                ->where('payment.instructions.qr_url', 'https://lfamilia.com/qr.png'));
    }

    public function test_bad_or_expired_signatures_and_missing_invoice_have_branded_errors(): void
    {
        $id = $this->order('LFRECOVERY-2');
        $this->get('/payment?invoice=LFRECOVERY-2')
            ->assertForbidden()->assertSee('Tautan tidak dapat digunakan')->assertSee('Cek pesanan');

        $valid = $this->recover('LFRECOVERY-2')->assertOk()->json('payment_url');
        $this->get(str_replace('LFRECOVERY-2', 'LFOTHER-ORDER', $valid))
            ->assertNotFound()->assertSee('Cek pesanan');
        $this->get($valid.'&signature=WRONG')->assertForbidden();

        $expired = URL::temporarySignedRoute('payment.show', now()->subMinute(), [
            'invoice' => 'LFRECOVERY-2',
        ]);
        $this->get($expired)->assertForbidden()->assertSee('Cek pesanan');
        $this->withSession(['guest_order_id' => $id])->get($expired)->assertForbidden();
        $this->get('/payment?invoice=LFRECOVERY-2')->assertOk();
        $this->get('/payment?invoice=LFUNKNOWN')->assertNotFound()->assertSee('Cek pesanan');
        $this->get('/nonexistent-recovery-path')->assertNotFound()->assertSee('Kembali ke beranda');
    }

    public function test_full_invoice_and_matching_whatsapp_are_required_for_recovery(): void
    {
        $this->order('LFRECOVERY-3');
        $this->postJson('/orders/track/search', ['query' => 'LFRECOVERY-3'])
            ->assertOk()->assertJsonPath('order.paymentRecoverable', true)
            ->assertJsonMissingPath('order.payment_url');

        $this->recover('LFRECOVERY-3', '081299999999')
            ->assertNotFound()->assertJsonMissingPath('payment_url');
        $url = $this->recover('LFRECOVERY-3')->assertOk()->json('payment_url');
        $this->withSession(['guest_order_id' => null])->get($url)->assertOk();
        $this->recover('LFRECOVERY-3', '+6281234567890')->assertOk();

        $this->postJson('/orders/track/recover', [
            'invoice' => 'LFRECOVERY-3', 'whatsapp' => '1234',
        ])->assertUnprocessable()->assertJsonMissingPath('payment_url');
    }

    public function test_paid_expired_and_elapsed_deadline_never_issue_signed_payment_links(): void
    {
        $this->order('LFRECOVERY-4', ['status' => 'PAID', 'paid_at' => now()]);
        $this->order('LFRECOVERY-5', ['status' => 'EXPIRED', 'expires_at' => now()->subMinute()]);
        $this->order('LFRECOVERY-6', ['status' => 'PENDING_PAYMENT', 'expires_at' => now()->subMinute()]);

        foreach (['LFRECOVERY-4', 'LFRECOVERY-5', 'LFRECOVERY-6'] as $invoice) {
            $this->recover($invoice)->assertStatus(409)->assertJsonMissingPath('payment_url');
            $this->postJson('/orders/track/search', ['query' => $invoice])
                ->assertOk()->assertJsonPath('order.paymentRecoverable', false);
        }
        $this->postJson('/orders/track/search', ['query' => 'LFRECOVERY-6'])
            ->assertJsonPath('order.paymentExpired', true);
    }
}
