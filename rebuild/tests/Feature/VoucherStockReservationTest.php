<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Services\CheckoutService;
use App\Services\FulfillmentService;
use App\Services\PaymentStateService;
use App\Services\VoucherStockService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * BATCH 02 — stok kode digital.
 *
 * Satu kode tidak boleh terjual dua kali, kode dipesan saat order dibuat,
 * dan hanya kode yang belum terkirim yang kembali ke stok.
 */
class VoucherStockReservationTest extends TestCase
{
    use DatabaseTransactions;

    private array $ctx = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        DB::table('providers')->whereIn('code', ['VOUCHER_STOCK', 'MANUAL'])->update(['is_active' => true]);
        DB::table('payment_gateways')->where('code', 'MANUAL_QRIS')->update(['is_active' => true]);
        DB::table('payment_channels')->where('code', 'manual_qris')->update(['is_active' => true]);

        $category = Category::where('slug', 'game')->firstOrFail();
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Stok Kode '.bin2hex(random_bytes(3)),
            'slug' => 'stok-kode-'.bin2hex(random_bytes(5)),
            'margin_percent' => 10,
            'fulfillment_mode' => 'AUTO_PROVIDER',
            'is_active' => true,
        ]);
        $product->fields()->create([
            'field_key' => 'user_id', 'label' => 'User ID', 'type' => 'text',
            'is_required' => true, 'sort_order' => 0,
        ]);
        $package = $product->packages()->create([
            'code' => 'STK'.bin2hex(random_bytes(3)),
            'name' => 'Kode 10', 'nominal_value' => 10, 'is_active' => true,
        ]);

        $stockKey = 'batch02.'.bin2hex(random_bytes(4));
        DB::table('provider_mappings')->insert([
            'product_package_id' => $package->id,
            'provider_id' => DB::table('providers')->where('code', 'VOUCHER_STOCK')->value('id'),
            'external_sku' => 'stock-package-'.$package->id,
            'cost_idr' => 5000,
            'store_cost_idr' => 5000,
            'fulfillment_config' => json_encode(['stock_key' => $stockKey], JSON_THROW_ON_ERROR),
            'priority' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->ctx = [
            'package' => $package->id,
            'product' => $product->id,
            'stock_key' => $stockKey,
        ];
    }

    private function payload(string $suffix): array
    {
        return [
            'package_id' => $this->ctx['package'],
            'payment_channel_code' => 'manual_qris',
            'customer_input' => ['user_id' => 'STK-'.$suffix],
            'guest_email' => 'stock-'.$suffix.'@example.test',
            'guest_phone' => '0812'.random_int(10000000, 99999999),
            'idempotency_key' => 'batch02-'.$suffix.'-'.bin2hex(random_bytes(4)),
        ];
    }

    private function importCodes(int $count): void
    {
        $codes = [];
        for ($i = 1; $i <= $count; $i++) {
            $codes[] = 'CODE-'.$this->ctx['stock_key'].'-'.$i;
        }
        app(VoucherStockService::class)->import($this->ctx['stock_key'], implode("\n", $codes));
    }

    public function test_last_code_cannot_be_sold_twice(): void
    {
        $this->importCodes(1);

        $first = app(CheckoutService::class)->create($this->payload('one'), null);
        $this->assertTrue($first['created']);

        try {
            app(CheckoutService::class)->create($this->payload('two'), null);
            $this->fail('Checkout kedua seharusnya ditolak karena stok habis.');
        } catch (ValidationException $exception) {
            // Pesan berasal dari lapisan harga: "Nominal sedang tidak tersedia".
            $this->assertStringContainsString('tidak tersedia', strtolower($exception->getMessage()));
        }

        $this->assertSame(1, DB::table('voucher_stock_codes')->where('stock_key', $this->ctx['stock_key'])->count());
        $this->assertSame(1, DB::table('voucher_stock_codes')->where('status', 'RESERVED')->count());
        $this->assertSame((int) $first['order']->id, (int) DB::table('voucher_stock_codes')->value('order_id'));
        $this->assertSame(1, DB::table('orders')->where('product_id', $this->ctx['product'])->count());
    }

    public function test_reserved_code_returns_to_stock_when_order_expires(): void
    {
        $this->importCodes(1);
        $order = app(CheckoutService::class)->create($this->payload('expire'), null)['order'];
        $this->assertSame('RESERVED', DB::table('voucher_stock_codes')->value('status'));

        app(PaymentStateService::class)->expireOrder($order);

        $this->assertSame('AVAILABLE', DB::table('voucher_stock_codes')->value('status'));
        $this->assertNull(DB::table('voucher_stock_codes')->value('order_id'));
        $this->assertDatabaseHas('order_events', [
            'order_id' => $order->id, 'event_type' => 'VOUCHER_STOCK_RELEASED',
        ]);

        // Kode yang sama kini bisa dijual lagi.
        $replacement = app(CheckoutService::class)->create($this->payload('retry'), null);
        $this->assertTrue($replacement['created']);
        $this->assertSame('RESERVED', DB::table('voucher_stock_codes')->value('status'));
    }

    public function test_checkout_payment_then_fulfillment_delivers_the_reserved_code(): void
    {
        $this->importCodes(1);
        $order = app(CheckoutService::class)->create($this->payload('e2e'), null)['order'];
        $this->assertSame('RESERVED', DB::table('voucher_stock_codes')->value('status'));

        // Pembayaran terverifikasi → order PAID, kode tetap milik order ini.
        DB::table('orders')->where('id', $order->id)->update([
            'status' => 'PAID',
            'paid_at' => now(),
        ]);

        app(FulfillmentService::class)->startOrder((int) $order->id);
        $attempt = DB::table('fulfillment_attempts')->where('order_id', $order->id)->first();
        $this->assertNotNull($attempt, 'Fulfillment tidak boleh memblokir order yang sudah memesan kode.');
        $this->assertSame('CREATED', $attempt->status, 'Order yang sudah memesan kode tidak boleh berstatus BLOCKED.');

        DB::table('fulfillment_attempts')->where('id', $attempt->id)->update(['status' => 'SENDING']);
        app(FulfillmentService::class)->reconcileAttempt((int) $attempt->id);

        $delivered = DB::table('orders')->where('id', $order->id)->first();
        $this->assertSame('SUCCESS', $delivered->status);
        $this->assertStringContainsString('CODE-'.$this->ctx['stock_key'].'-1', (string) $delivered->delivery_payload);
        $this->assertSame('DELIVERED', DB::table('voucher_stock_codes')->value('status'));
        $this->assertSame((int) $order->id, (int) DB::table('voucher_stock_codes')->value('order_id'));
    }

    public function test_reservation_survives_admin_changing_the_stock_key(): void
    {
        $this->importCodes(1);
        $order = app(CheckoutService::class)->create($this->payload('rekey'), null)['order'];

        // Admin mengganti stock_key setelah checkout; kode lama tetap milik order.
        DB::table('provider_mappings')
            ->where('product_package_id', $this->ctx['package'])
            ->update(['fulfillment_config' => json_encode(['stock_key' => 'batch02.rekeyed'], JSON_THROW_ON_ERROR)]);

        DB::table('orders')->where('id', $order->id)->update(['status' => 'PAID', 'paid_at' => now()]);

        app(FulfillmentService::class)->startOrder((int) $order->id);
        $attempt = DB::table('fulfillment_attempts')->where('order_id', $order->id)->first();
        $this->assertSame('CREATED', $attempt?->status, 'Reservasi order tidak boleh hilang karena stock_key diganti.');

        DB::table('fulfillment_attempts')->where('id', $attempt->id)->update(['status' => 'SENDING']);
        app(FulfillmentService::class)->reconcileAttempt((int) $attempt->id);

        $this->assertSame('SUCCESS', DB::table('orders')->where('id', $order->id)->value('status'));
        $this->assertSame('DELIVERED', DB::table('voucher_stock_codes')->value('status'));
    }

    public function test_reserved_code_is_released_when_fulfillment_switches_source(): void
    {
        $this->importCodes(1);
        $order = app(CheckoutService::class)->create($this->payload('skip'), null)['order'];
        $this->assertSame('RESERVED', DB::table('voucher_stock_codes')->value('status'));

        // Admin menonaktifkan sumber stok kode dan menyiapkan sumber Digiflazz.
        DB::table('provider_mappings')
            ->where('product_package_id', $this->ctx['package'])
            ->update(['is_active' => false]);
        $sku = 'skip-'.$this->ctx['package'];
        DB::table('provider_mappings')->insert([
            'product_package_id' => $this->ctx['package'],
            'provider_id' => DB::table('providers')->where('code', 'DIGIFLAZZ')->value('id'),
            'external_sku' => $sku,
            'cost_idr' => 5000,
            'max_price_idr' => 5000,
            'priority' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('digiflazz_catalog_items')->insert([
            'buyer_sku_code' => $sku, 'product_name' => 'Provider '.$sku, 'category' => 'Games',
            'brand' => 'Brand', 'type' => 'Umum', 'seller_name' => 'Seller A',
            'price_idr' => 5000, 'baseline_price_idr' => 5000,
            'buyer_active' => true, 'seller_active' => true, 'unlimited_stock' => true,
            'stock' => 0, 'multi' => false, 'start_cut_off' => '00:00', 'end_cut_off' => '00:00',
            'synced_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('orders')->where('id', $order->id)->update(['status' => 'PAID', 'paid_at' => now()]);

        app(FulfillmentService::class)->startOrder((int) $order->id);

        // Order dialihkan ke Digiflazz, dan kode yang tak akan terkirim kembali dijual.
        $code = DB::table('voucher_stock_codes')->first();
        $this->assertSame('AVAILABLE', $code->status, 'Kode harus dilepas saat sumber stok ditinggalkan.');
        $this->assertNull($code->order_id);
        $this->assertSame(
            'DIGIFLAZZ',
            DB::table('providers')->where('id', DB::table('fulfillment_attempts')
                ->where('order_id', $order->id)->orderByDesc('id')->value('provider_id'))->value('code')
        );
    }

    public function test_delivered_code_is_never_released(): void
    {
        $this->importCodes(1);
        $order = app(CheckoutService::class)->create($this->payload('delivered'), null)['order'];
        $codeId = (int) DB::table('voucher_stock_codes')->value('id');
        app(VoucherStockService::class)->markDelivered($codeId, (int) $order->id);

        $this->assertSame(0, app(VoucherStockService::class)->release((int) $order->id));
        $this->assertSame('DELIVERED', DB::table('voucher_stock_codes')->value('status'));
    }

    public function test_stock_shortage_rolls_back_the_whole_order(): void
    {
        $this->importCodes(1);
        app(CheckoutService::class)->create($this->payload('holder'), null);
        $ordersBefore = DB::table('orders')->count();
        $eventsBefore = DB::table('order_events')->count();

        try {
            app(CheckoutService::class)->create($this->payload('blocked'), null);
            $this->fail('Checkout tanpa stok seharusnya gagal.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame($ordersBefore, DB::table('orders')->count());
        $this->assertSame($eventsBefore, DB::table('order_events')->count());
        $this->assertSame(1, DB::table('voucher_stock_codes')->where('status', 'RESERVED')->count());
    }
}
