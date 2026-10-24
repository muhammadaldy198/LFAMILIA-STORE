<?php

namespace Tests\Feature;

use App\Jobs\SendTransactionalEmailJob;
use App\Models\Category;
use App\Models\IntegrationCredential;
use App\Models\Product;
use App\Models\StoreAsset;
use App\Models\User;
use App\Services\FulfillmentService;
use App\Services\PaymentStateService;
use App\Services\TransactionalEmailService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Keputusan D3 (grand opening): email customer hanya 3 momen —
 * order dibuat, pembayaran berhasil, pesanan selesai ATAU gagal.
 * Momen lain (diproses / bayar gagal / kedaluwarsa) TIDAK ditambah email.
 * Repo memakai PHPUnit (bukan Pest) — test ini mengikuti pola repo.
 */
class CustomerEmailMomentsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->withoutMiddleware(ThrottleRequests::class);
        Queue::fake();
    }

    /**
     * @return array{product:Product,package_id:int,mapping_id:int}
     */
    private function catalog(): array
    {
        $category = Category::where('slug', 'game')->firstOrFail();
        DB::table('providers')->where('code', 'DIGIFLAZZ')->update(['is_active' => true]);
        $providerId = DB::table('providers')->where('code', 'DIGIFLAZZ')->value('id');

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Email Moments Game '.bin2hex(random_bytes(3)),
            'slug' => 'email-moments-game-'.bin2hex(random_bytes(4)),
            'margin_percent' => 10,
            'fulfillment_mode' => 'AUTO_PROVIDER',
            'nickname_check_enabled' => false,
            'is_active' => true,
        ]);
        $product->fields()->create([
            'field_key' => 'user_id',
            'label' => 'User ID',
            'type' => 'text',
            'is_required' => true,
            'sort_order' => 0,
        ]);
        $product->fields()->create([
            'field_key' => 'zone_id',
            'label' => 'Zone ID',
            'type' => 'text',
            'is_required' => false,
            'sort_order' => 1,
        ]);
        $package = $product->packages()->create([
            'code' => 'EML'.bin2hex(random_bytes(3)),
            'name' => '100 Diamonds',
            'nominal_value' => 100,
            'is_active' => true,
        ]);
        $mappingId = DB::table('provider_mappings')->insertGetId([
            'product_package_id' => $package->id,
            'provider_id' => $providerId,
            'external_sku' => 'EML-SKU-'.bin2hex(random_bytes(4)),
            'cost_idr' => 10000,
            'max_price_idr' => 12000,
            'fulfillment_config' => json_encode(['customer_no_template' => '{{user_id}}{{zone_id}}'], JSON_THROW_ON_ERROR),
            'priority' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['product' => $product, 'package_id' => $package->id, 'mapping_id' => $mappingId];
    }

    private function enableManualQrisRoute(): void
    {
        // Pola yang sama dengan CheckoutTest: QRIS manual (gateway MANUAL,
        // bukan eksternal) — MIDTRANS tanpa kredensial integrasi selalu
        // masuk daftar gateway unavailable sehingga route-nya terfilter.
        DB::table('payment_gateways')->where('code', 'MANUAL_QRIS')->update([
            'is_active' => true,
            'is_maintenance' => false,
            'updated_at' => now(),
        ]);
        DB::table('payment_channels')->where('code', 'manual_qris')->update([
            'is_active' => true,
            'updated_at' => now(),
        ]);
    }

    public function test_order_created_email_is_queued_with_full_details(): void
    {
        $catalog = $this->catalog();
        $this->seedCheckoutDigiflazzCatalog($catalog['package_id']);
        $this->enableManualQrisRoute();

        $order = $this->postJson('/checkout/orders', [
            'package_id' => $catalog['package_id'],
            'payment_channel_code' => 'manual_qris',
            'customer_input' => ['user_id' => '123456', 'zone_id' => '9876'],
            'guest_email' => 'moments-created@example.test',
            'guest_phone' => '081234567890',
            'idempotency_key' => 'email-moments-created-'.bin2hex(random_bytes(6)),
        ])->assertCreated();

        $invoice = (string) $order->json('order_number');
        $channelName = json_decode((string) DB::table('orders')->where('order_number', $invoice)->value('snapshot'), true)['payment']['channel_name'] ?? '';

        Queue::assertPushed(SendTransactionalEmailJob::class, function (SendTransactionalEmailJob $job) use ($invoice, $channelName, $order): bool {
            return $job->recipient === 'moments-created@example.test'
                && str_contains($job->subject, $invoice)
                && str_contains($job->subject, 'dibuat')
                && str_contains($job->text, $invoice)
                && str_contains($job->text, '100 Diamonds')
                && str_contains($job->text, '123456')
                && str_contains($job->text, '9876')
                && str_contains($job->text, (string) $channelName)
                && str_contains($job->text, 'Rp 11.000')
                && str_contains($job->text, 'Rp 0')
                && str_contains($job->text, 'Rp 11.000')
                && str_contains($job->text, 'Selesaikan pembayaran sebelum')
                && str_contains($job->text, 'WIB')
                && str_contains($job->text, 'invoice='.$invoice)
                && str_contains($job->text, '/orders/check')
                && str_contains($job->text, 'support@lfamiliastore.my.id')
                && str_contains($job->text, 'Kode akses pesanan: '.(string) $order->json('access_code'))
                && str_contains($job->text, 'Jangan bagikan ke siapa pun');
        });
    }

    /**
     * Link "Bayar sekarang" di email harus jalan lintas sesi: guest yang
     * membuka email di browser/HP lain (tanpa session checkout) dan
     * member yang belum login tetap bisa membuka halaman pembayaran,
     * karena link-nya signed URL. Tanpa signature, jalur lama tetap
     * menolak (403) — link tanpa signature bukan jalan pintas.
     */
    /**
     * QRIS manual butuh aset StoreAsset manual_qris aktif + gambar QR
     * (ManualQrisGateway::create menolak tanpa itu). Pola yang sama
     * dengan test media di CatalogTest.
     */
    private function seedManualQrisAsset(): void
    {
        Storage::fake('public');
        $asset = StoreAsset::where('key', 'manual_qris')->firstOrFail();
        $asset->update(['is_active' => true]);
        $asset->addMedia(UploadedFile::fake()->image('qris.png'))->toMediaCollection('image', 'public');
    }

    public function test_payment_link_from_email_opens_without_checkout_session(): void
    {
        $this->seedManualQrisAsset();
        $catalog = $this->catalog();
        $this->seedCheckoutDigiflazzCatalog($catalog['package_id']);
        $this->enableManualQrisRoute();

        $order = $this->postJson('/checkout/orders', [
            'package_id' => $catalog['package_id'],
            'payment_channel_code' => 'manual_qris',
            'customer_input' => ['user_id' => '123456', 'zone_id' => '9876'],
            'guest_email' => 'moments-paylink@example.test',
            'guest_phone' => '081234567890',
            'idempotency_key' => 'email-moments-paylink-'.bin2hex(random_bytes(6)),
        ])->assertCreated();

        $invoice = (string) $order->json('order_number');
        $payUrl = null;
        Queue::assertPushed(SendTransactionalEmailJob::class, function (SendTransactionalEmailJob $job) use (&$payUrl): bool {
            if (preg_match('/Bayar sekarang: (\S+)/', $job->text, $matches) === 1) {
                $payUrl = $matches[1];
            }

            return $job->recipient === 'moments-paylink@example.test';
        });
        $this->assertIsString($payUrl);
        $this->assertStringContainsString('signature=', $payUrl);

        // Simulasi browser lain: sesi checkout dibuang.
        session()->flush();

        // Tanpa signature tetap ditolak.
        $this->get('/payment?invoice='.$invoice)->assertForbidden();
        // Dengan signature dari email: halaman pembayaran terbuka.
        // Respons HTML awal membawa page Inertia di viewData('page')
        // — baca createPaymentUrl dari sana (tanpa header X-Inertia,
        // yang butuh X-Inertia-Version agar tidak 409).
        $page = $this->get($payUrl)->assertOk()->viewData('page');
        $createUrl = $page['props']['createPaymentUrl'] ?? null;

        // Sesi dibuang lagi SETELAH GET (GET tadi sempat menanam
        // guest_order_id di sesi) — POST harus lolos berkat signature
        // di createPaymentUrl, bukan berkat sesi.
        session()->flush();

        $this->assertIsString($createUrl);
        $this->assertStringContainsString('signature=', $createUrl);
        $this->postJson($createUrl, [
            'idempotency_key' => 'paylink-create-guest-'.bin2hex(random_bytes(6)),
        ])->assertOk();
    }

    public function test_payment_link_from_email_opens_for_logged_out_member(): void
    {
        $this->seedManualQrisAsset();
        $catalog = $this->catalog();
        $this->seedCheckoutDigiflazzCatalog($catalog['package_id']);
        $this->enableManualQrisRoute();

        $user = User::create([
            'name' => 'Member Paylink',
            'email' => 'moments-paylink-member-'.bin2hex(random_bytes(4)).'@example.test',
            'phone' => '081234567890',
            'password' => 'password',
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        $order = $this->actingAs($user)->postJson('/checkout/orders', [
            'package_id' => $catalog['package_id'],
            'payment_channel_code' => 'manual_qris',
            'customer_input' => ['user_id' => '123456', 'zone_id' => '9876'],
            'idempotency_key' => 'email-moments-paylink-member-'.bin2hex(random_bytes(6)),
        ])->assertCreated();

        $invoice = (string) $order->json('order_number');
        $payUrl = null;
        Queue::assertPushed(SendTransactionalEmailJob::class, function (SendTransactionalEmailJob $job) use ($user, &$payUrl): bool {
            if ($job->recipient === $user->email && preg_match('/Bayar sekarang: (\S+)/', $job->text, $matches) === 1) {
                $payUrl = $matches[1];
            }

            return $job->recipient === $user->email;
        });
        $this->assertIsString($payUrl);

        // Simulasi member membuka email tanpa login.
        auth()->logout();
        session()->flush();

        $this->get('/payment?invoice='.$invoice)->assertForbidden();
        // Respons HTML awal membawa page Inertia di viewData('page')
        // — baca createPaymentUrl dari sana (tanpa header X-Inertia,
        // yang butuh X-Inertia-Version agar tidak 409).
        $page = $this->get($payUrl)->assertOk()->viewData('page');
        $createUrl = $page['props']['createPaymentUrl'] ?? null;
        $this->assertSame($user->id, (int) DB::table('orders')->where('order_number', $invoice)->value('user_id'));

        // Halaman GET terbuka lewat signature SAJA belum cukup: tombol
        // "Siapkan pembayaran" POST ke /payments/orders/{invoice}, dan
        // PaymentController::create sebelumnya mensyaratkan owner yang
        // login -> 403. Halaman kini meneruskan otorisasi yang sama
        // sebagai createPaymentUrl (signed POST URL) — POST lewat URL
        // itu harus berhasil menyiapkan pembayaran, tanpa login.

        $this->assertIsString($createUrl);
        $this->assertStringContainsString('signature=', $createUrl);
        $this->postJson($createUrl, [
            'idempotency_key' => 'paylink-create-'.bin2hex(random_bytes(6)),
        ])->assertOk();
    }

    public function test_payment_confirmed_email_contains_amount_and_details(): void
    {
        $catalog = $this->catalog();
        $channelId = DB::table('payment_channels')->where('code', 'qris')->value('id');
        $orderId = DB::table('orders')->insertGetId([
            'order_number' => 'ORD-EMAIL-PAID-'.bin2hex(random_bytes(4)),
            'guest_email' => 'moments-paid@example.test',
            'guest_phone' => '081234567890',
            'product_id' => $catalog['product']->id,
            'product_package_id' => $catalog['package_id'],
            'provider_mapping_id' => $catalog['mapping_id'],
            'payment_channel_id' => $channelId,
            'status' => 'PENDING_PAYMENT',
            'currency' => 'IDR',
            'customer_input' => json_encode(['user_id' => '123456', 'zone_id' => '7890'], JSON_THROW_ON_ERROR),
            'snapshot' => json_encode([]),
            'cost_idr' => 10000,
            'margin_idr' => 1000,
            'discount_idr' => 0,
            'fee_idr' => 78,
            'total_idr' => 11078,
            'idempotency_key' => 'email-moments-paid-'.bin2hex(random_bytes(6)),
            'expires_at' => now()->addMinutes(30),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $paymentId = DB::table('payment_transactions')->insertGetId([
            'order_id' => $orderId,
            'gateway_code' => 'MIDTRANS',
            'channel_code' => 'qris',
            'merchant_reference' => 'EMAIL-PAID-TEST-1',
            'amount_idr' => 11078,
            'status' => 'PENDING',
            'idempotency_key' => 'email-moments-paid-tx-'.bin2hex(random_bytes(6)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = app(PaymentStateService::class)->apply($paymentId, 'PAID');

        $this->assertSame('PAID', $result['status']);
        $this->assertSame('PAID', DB::table('orders')->where('id', $orderId)->value('status'));
        $invoice = (string) DB::table('orders')->where('id', $orderId)->value('order_number');
        Queue::assertPushed(SendTransactionalEmailJob::class, function (SendTransactionalEmailJob $job) use ($invoice): bool {
            return $job->recipient === 'moments-paid@example.test'
                && str_contains($job->subject, 'Pembayaran diterima')
                && str_contains($job->subject, $invoice)
                && str_contains($job->text, $invoice)
                && str_contains($job->text, '100 Diamonds')
                && str_contains($job->text, '123456')
                && str_contains($job->text, 'Rp 11.078')
                && str_contains($job->subject, 'sedang diproses')
                && str_contains($job->text, '/orders/check')
                && str_contains($job->text, 'support@lfamiliastore.my.id');
        });
    }

    public function test_order_completed_email_contains_delivery_reference(): void
    {
        IntegrationCredential::updateOrCreate(['code' => 'digiflazz'], [
            'config_ciphertext' => [
                'username' => 'buyer-test',
                'api_key' => 'api-secret',
                'webhook_secret' => 'webhook-secret',
                'base_url' => 'https://digiflazz.test',
                'testing' => true,
            ],
            'is_active' => true,
        ]);
        $catalog = $this->catalog();
        $orderId = DB::table('orders')->insertGetId([
            'order_number' => 'ORD-EMAIL-DONE-'.bin2hex(random_bytes(4)),
            'guest_email' => 'moments-done@example.test',
            'guest_phone' => '081234567890',
            'product_id' => $catalog['product']->id,
            'product_package_id' => $catalog['package_id'],
            'provider_mapping_id' => $catalog['mapping_id'],
            'status' => 'PAID',
            'currency' => 'IDR',
            'customer_input' => json_encode(['user_id' => '123456', 'zone_id' => '7890'], JSON_THROW_ON_ERROR),
            'snapshot' => json_encode([
                'product' => ['id' => $catalog['product']->id, 'name' => $catalog['product']->name, 'fulfillment_mode' => 'AUTO_PROVIDER'],
                'package' => ['id' => $catalog['package_id'], 'name' => '100 Diamonds'],
                'provider' => ['mapping_id' => $catalog['mapping_id'], 'code' => 'DIGIFLAZZ', 'sku' => 'EML-SKU', 'cost_idr' => 10000, 'max_price_idr' => 12000],
                'pricing' => ['cost_idr' => 10000, 'total_idr' => 11000],
                'customer_input' => ['user_id' => '123456', 'zone_id' => '7890'],
            ], JSON_THROW_ON_ERROR),
            'cost_idr' => 10000,
            'margin_idr' => 1000,
            'discount_idr' => 0,
            'fee_idr' => 0,
            'total_idr' => 11000,
            'idempotency_key' => 'email-moments-done-'.bin2hex(random_bytes(6)),
            'paid_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->seedCheckoutDigiflazzCatalog($catalog['package_id']);
        // JANGAN tambah return type `: Response` pada closure ini:
        // Http::response() mengembalikan FulfilledPromise, bukan
        // Response — return type itu melempar TypeError di dalam stub,
        // transact() mengira provider tidak dapat dipastikan
        // (markUnknown) dan order mentok PROCESSING.
        Http::fake(fn ($request) => Http::response(['data' => [
            'ref_id' => $request->data()['ref_id'],
            'customer_no' => $request->data()['customer_no'],
            'buyer_sku_code' => $request->data()['buyer_sku_code'],
            'message' => 'Sukses',
            'status' => 'Sukses',
            'rc' => '00',
            'sn' => 'SERIAL-123',
            'price' => 10000,
        ]]));

        $service = app(FulfillmentService::class);
        $service->startOrder($orderId);
        $attempt = DB::table('fulfillment_attempts')->where('order_id', $orderId)->first();
        $service->sendAttempt((int) $attempt->id);

        $this->assertSame('SUCCESS', DB::table('orders')->where('id', $orderId)->value('status'));
        $invoice = (string) DB::table('orders')->where('id', $orderId)->value('order_number');
        Queue::assertPushed(SendTransactionalEmailJob::class, function (SendTransactionalEmailJob $job) use ($invoice): bool {
            return $job->recipient === 'moments-done@example.test'
                && str_contains($job->subject, 'Pesanan selesai')
                && str_contains($job->text, $invoice)
                && str_contains($job->text, '100 Diamonds')
                && str_contains($job->text, '123456')
                && str_contains($job->text, 'SERIAL-123')
                && str_contains($job->text, 'Rp 11.000')
                && str_contains($job->text, '/orders/check')
                && str_contains($job->text, 'support@lfamiliastore.my.id');
        });
    }

    public function test_order_completed_email_shows_voucher_stock_code_as_redeemable(): void
    {
        $catalog = $this->catalog();
        $orderId = DB::table('orders')->insertGetId([
            'order_number' => 'ORD-EMAIL-CODE-'.bin2hex(random_bytes(4)),
            'guest_email' => 'moments-code@example.test',
            'guest_phone' => '081234567890',
            'product_id' => $catalog['product']->id,
            'product_package_id' => $catalog['package_id'],
            'provider_mapping_id' => $catalog['mapping_id'],
            'status' => 'SUCCESS',
            'currency' => 'IDR',
            'customer_input' => json_encode(['user_id' => '123456', 'zone_id' => '7890'], JSON_THROW_ON_ERROR),
            'snapshot' => json_encode([
                'product' => ['id' => $catalog['product']->id, 'name' => $catalog['product']->name, 'fulfillment_mode' => 'VOUCHER_STOCK'],
                'package' => ['id' => $catalog['package_id'], 'name' => '100 Diamonds'],
                'provider' => ['mapping_id' => $catalog['mapping_id'], 'code' => 'VOUCHER_STOCK', 'sku' => 'EML-SKU', 'cost_idr' => 10000, 'max_price_idr' => 12000],
                'customer_input' => ['user_id' => '123456', 'zone_id' => '7890'],
            ], JSON_THROW_ON_ERROR),
            // Bentuk payload stok kode: ['code' => kode redeemable].
            'delivery_payload' => json_encode(['code' => 'VOUCHER-ABC-123'], JSON_THROW_ON_ERROR),
            'cost_idr' => 10000,
            'margin_idr' => 1000,
            'discount_idr' => 0,
            'fee_idr' => 0,
            'total_idr' => 11000,
            'idempotency_key' => 'email-moments-code-'.bin2hex(random_bytes(6)),
            'paid_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(TransactionalEmailService::class)->queueOrderCompleted($orderId, 'stock');

        Queue::assertPushed(SendTransactionalEmailJob::class, function (SendTransactionalEmailJob $job): bool {
            return $job->recipient === 'moments-code@example.test'
                && str_contains($job->subject, 'siap ditukarkan')
                && str_contains($job->text, 'Kode voucher: VOUCHER-ABC-123')
                && str_contains($job->text, 'tukarkan sendiri')
                // Kode redeemable bukan nomor referensi diagnostik,
                // dan belum "masuk akun" — customer yang menukarkannya.
                && ! str_contains($job->text, 'No. Referensi Pengiriman')
                && ! str_contains($job->text, 'sudah masuk ke akunmu')
                && ! str_contains($job->text, 'periksa saldo di dalam game');
        });
    }

    public function test_order_completed_email_shows_manual_evidence_not_voucher_code(): void
    {
        $catalog = $this->catalog();
        $orderId = DB::table('orders')->insertGetId([
            'order_number' => 'ORD-EMAIL-MANUAL-'.bin2hex(random_bytes(4)),
            'guest_email' => 'moments-manual@example.test',
            'guest_phone' => '081234567890',
            'product_id' => $catalog['product']->id,
            'product_package_id' => $catalog['package_id'],
            'provider_mapping_id' => $catalog['mapping_id'],
            'status' => 'SUCCESS',
            'currency' => 'IDR',
            'customer_input' => json_encode(['user_id' => '123456', 'zone_id' => '7890'], JSON_THROW_ON_ERROR),
            'snapshot' => json_encode([
                'product' => ['id' => $catalog['product']->id, 'name' => $catalog['product']->name, 'fulfillment_mode' => 'MANUAL'],
                'package' => ['id' => $catalog['package_id'], 'name' => '100 Diamonds'],
                'provider' => ['mapping_id' => $catalog['mapping_id'], 'code' => 'MANUAL', 'sku' => 'EML-SKU', 'cost_idr' => 10000, 'max_price_idr' => 12000],
                'customer_input' => ['user_id' => '123456', 'zone_id' => '7890'],
            ], JSON_THROW_ON_ERROR),
            // Bentuk payload manual: code = teks bukti bebas yang
            // diketik admin (BUKAN kode redeemable) + note admin.
            'delivery_payload' => json_encode([
                'code' => 'Sudah ditransfer manual, bukti: TRX-999',
                'note' => 'Diamond masuk 10 menit setelah bayar.',
            ], JSON_THROW_ON_ERROR),
            'cost_idr' => 10000,
            'margin_idr' => 1000,
            'discount_idr' => 0,
            'fee_idr' => 0,
            'total_idr' => 11000,
            'idempotency_key' => 'email-moments-manual-'.bin2hex(random_bytes(6)),
            'paid_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(TransactionalEmailService::class)->queueOrderCompleted($orderId, 'manual');

        Queue::assertPushed(SendTransactionalEmailJob::class, function (SendTransactionalEmailJob $job): bool {
            return $job->recipient === 'moments-manual@example.test'
                && str_contains($job->subject, 'sudah dikirim')
                && str_contains($job->text, 'Bukti pengiriman: Sudah ditransfer manual, bukti: TRX-999')
                && str_contains($job->text, 'Catatan: Diamond masuk 10 menit setelah bayar.')
                // Bukti manual BUKAN kode redeemable: jangan suruh
                // customer menukarkan apa pun, jangan labeli voucher.
                && ! str_contains($job->text, 'Kode voucher')
                && ! str_contains($job->text, 'tukarkan sendiri')
                && ! str_contains($job->text, 'siap ditukarkan');
        });
    }

    public function test_order_completed_email_shows_manual_note_without_code(): void
    {
        $catalog = $this->catalog();
        $orderId = DB::table('orders')->insertGetId([
            'order_number' => 'ORD-EMAIL-NOTE-'.bin2hex(random_bytes(4)),
            'guest_email' => 'moments-note@example.test',
            'guest_phone' => '081234567890',
            'product_id' => $catalog['product']->id,
            'product_package_id' => $catalog['package_id'],
            'provider_mapping_id' => $catalog['mapping_id'],
            'status' => 'SUCCESS',
            'currency' => 'IDR',
            'customer_input' => json_encode(['user_id' => '123456', 'zone_id' => '7890'], JSON_THROW_ON_ERROR),
            'snapshot' => json_encode([
                'product' => ['id' => $catalog['product']->id, 'name' => $catalog['product']->name, 'fulfillment_mode' => 'MANUAL'],
                'package' => ['id' => $catalog['package_id'], 'name' => '100 Diamonds'],
                'provider' => ['mapping_id' => $catalog['mapping_id'], 'code' => 'MANUAL', 'sku' => 'EML-SKU', 'cost_idr' => 10000, 'max_price_idr' => 12000],
                'customer_input' => ['user_id' => '123456', 'zone_id' => '7890'],
            ], JSON_THROW_ON_ERROR),
            // Penyelesaian note-only: code null, note terisi. Email
            // lama masuk cabang non-code dan note TIDAK PERNAH tampil.
            'delivery_payload' => json_encode(['note' => 'Sudah dikirim via WA admin.'], JSON_THROW_ON_ERROR),
            'cost_idr' => 10000,
            'margin_idr' => 1000,
            'discount_idr' => 0,
            'fee_idr' => 0,
            'total_idr' => 11000,
            'idempotency_key' => 'email-moments-note-'.bin2hex(random_bytes(6)),
            'paid_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(TransactionalEmailService::class)->queueOrderCompleted($orderId, 'manual');

        Queue::assertPushed(SendTransactionalEmailJob::class, function (SendTransactionalEmailJob $job): bool {
            return $job->recipient === 'moments-note@example.test'
                && str_contains($job->text, 'Catatan: Sudah dikirim via WA admin.')
                && ! str_contains($job->text, 'Kode voucher')
                && ! str_contains($job->text, 'tukarkan sendiri');
        });
    }

    public function test_order_failed_email_directs_to_cs_without_refund_promise(): void
    {
        $catalog = $this->catalog();
        $orderId = DB::table('orders')->insertGetId([
            'order_number' => 'ORD-EMAIL-FAIL-'.bin2hex(random_bytes(4)),
            'guest_email' => 'moments-failed@example.test',
            'guest_phone' => '081234567890',
            'product_id' => $catalog['product']->id,
            'product_package_id' => $catalog['package_id'],
            'provider_mapping_id' => $catalog['mapping_id'],
            'status' => 'PAID',
            'currency' => 'IDR',
            'customer_input' => json_encode(['user_id' => '123456', 'zone_id' => '7890'], JSON_THROW_ON_ERROR),
            'snapshot' => json_encode([
                'product' => ['id' => $catalog['product']->id, 'name' => $catalog['product']->name, 'fulfillment_mode' => 'AUTO_PROVIDER'],
                'package' => ['id' => $catalog['package_id'], 'name' => '100 Diamonds'],
                'provider' => ['mapping_id' => $catalog['mapping_id'], 'code' => 'DIGIFLAZZ', 'sku' => 'EML-SKU', 'cost_idr' => 10000, 'max_price_idr' => 12000],
                'customer_input' => ['user_id' => '123456', 'zone_id' => '7890'],
            ], JSON_THROW_ON_ERROR),
            'cost_idr' => 10000,
            'margin_idr' => 1000,
            'discount_idr' => 0,
            'fee_idr' => 0,
            'total_idr' => 11000,
            'idempotency_key' => 'email-moments-failed-'.bin2hex(random_bytes(6)),
            'paid_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $attemptId = DB::table('fulfillment_attempts')->insertGetId([
            'order_id' => $orderId,
            'provider_mapping_id' => $catalog['mapping_id'],
            'attempt_no' => 1,
            'external_reference' => 'FUL-'.bin2hex(random_bytes(8)),
            'status' => 'MANUAL_PENDING',
            'correlation_id' => 'email-moments-failed-'.bin2hex(random_bytes(6)),
            'safe_to_failover' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(FulfillmentService::class)->failManual($attemptId, 'Penyedia menolak pengiriman.', 1);

        $this->assertSame('FAILED', DB::table('orders')->where('id', $orderId)->value('status'));
        $invoice = (string) DB::table('orders')->where('id', $orderId)->value('order_number');
        Queue::assertPushed(SendTransactionalEmailJob::class, function (SendTransactionalEmailJob $job) use ($invoice): bool {
            $lower = mb_strtolower($job->text);

            return $job->recipient === 'moments-failed@example.test'
                && str_contains($job->subject, $invoice)
                && str_contains($job->subject, 'gagal diproses')
                && str_contains($job->text, $invoice)
                && str_contains($job->text, '100 Diamonds')
                && str_contains($job->text, 'Rp 11.000')
                && str_contains($job->text, 'support@lfamiliastore.my.id')
                && str_contains($job->text, 'bukti bayar')
                && ! str_contains($lower, 'refund')
                && ! str_contains($lower, 'pengembalian dana')
                && ! str_contains($lower, 'dana kembali');
        });
    }

    public function test_exhausted_fulfillment_queues_failed_email(): void
    {
        IntegrationCredential::updateOrCreate(['code' => 'digiflazz'], [
            'config_ciphertext' => [
                'username' => 'buyer-test',
                'api_key' => 'api-secret',
                'webhook_secret' => 'webhook-secret',
                'base_url' => 'https://digiflazz.test',
                'testing' => true,
            ],
            'is_active' => true,
        ]);
        $catalog = $this->catalog();
        $orderId = DB::table('orders')->insertGetId([
            'order_number' => 'ORD-EMAIL-BLOCK-'.bin2hex(random_bytes(4)),
            'guest_email' => 'moments-blocked@example.test',
            'guest_phone' => '081234567890',
            'product_id' => $catalog['product']->id,
            'product_package_id' => $catalog['package_id'],
            'provider_mapping_id' => $catalog['mapping_id'],
            'status' => 'PAID',
            'currency' => 'IDR',
            'customer_input' => json_encode(['user_id' => '123456', 'zone_id' => '7890'], JSON_THROW_ON_ERROR),
            'snapshot' => json_encode([
                'product' => ['id' => $catalog['product']->id, 'name' => $catalog['product']->name, 'fulfillment_mode' => 'AUTO_PROVIDER'],
                'package' => ['id' => $catalog['package_id'], 'name' => '100 Diamonds'],
                'provider' => ['mapping_id' => $catalog['mapping_id'], 'code' => 'DIGIFLAZZ', 'sku' => 'EML-SKU', 'cost_idr' => 10000, 'max_price_idr' => 12000],
                'customer_input' => ['user_id' => '123456', 'zone_id' => '7890'],
            ], JSON_THROW_ON_ERROR),
            'cost_idr' => 10000,
            'margin_idr' => 1000,
            'discount_idr' => 0,
            'fee_idr' => 0,
            'total_idr' => 11000,
            'idempotency_key' => 'email-moments-blocked-'.bin2hex(random_bytes(6)),
            'paid_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->seedCheckoutDigiflazzCatalog($catalog['package_id']);
        Http::fake(fn ($request) => Http::response(['data' => [
            'ref_id' => $request->data()['ref_id'],
            'customer_no' => $request->data()['customer_no'],
            'buyer_sku_code' => $request->data()['buyer_sku_code'],
            'status' => 'Gagal',
            'rc' => '44',
            'sn' => '',
            'message' => 'Gagal definitif',
            'price' => 10000,
        ]]));

        $service = app(FulfillmentService::class);
        $service->startOrder($orderId);
        $attempt = DB::table('fulfillment_attempts')->where('order_id', $orderId)->first();
        $service->sendAttempt((int) $attempt->id);

        $this->assertSame('BLOCKED', DB::table('orders')->where('id', $orderId)->value('status'));
        $invoice = (string) DB::table('orders')->where('id', $orderId)->value('order_number');
        Queue::assertPushed(SendTransactionalEmailJob::class, function (SendTransactionalEmailJob $job) use ($invoice): bool {
            $lower = mb_strtolower($job->text);

            return $job->recipient === 'moments-blocked@example.test'
                && str_contains($job->subject, $invoice)
                && str_contains($job->subject, 'gagal diproses')
                && str_contains($job->text, $invoice)
                && str_contains($job->text, 'bukti bayar')
                && ! str_contains($lower, 'blocked')
                && ! str_contains($lower, 'refund')
                && ! str_contains($lower, 'pengembalian dana');
        });
    }

    public function test_invalid_guest_email_skips_email_silently(): void
    {
        $catalog = $this->catalog();
        $orderId = DB::table('orders')->insertGetId([
            'order_number' => 'ORD-EMAIL-BAD-'.bin2hex(random_bytes(4)),
            'guest_email' => 'bukan-email',
            'guest_phone' => '081234567890',
            'product_id' => $catalog['product']->id,
            'product_package_id' => $catalog['package_id'],
            'provider_mapping_id' => $catalog['mapping_id'],
            'status' => 'PENDING_PAYMENT',
            'currency' => 'IDR',
            'customer_input' => json_encode(['user_id' => '123456'], JSON_THROW_ON_ERROR),
            'snapshot' => json_encode([]),
            'cost_idr' => 10000,
            'margin_idr' => 1000,
            'discount_idr' => 0,
            'fee_idr' => 78,
            'total_idr' => 11078,
            'idempotency_key' => 'email-moments-bad-'.bin2hex(random_bytes(6)),
            'expires_at' => now()->addMinutes(30),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(TransactionalEmailService::class)->queueOrderCreated($orderId);

        Queue::assertNothingPushed();
    }

    public function test_order_without_any_email_skips_silently(): void
    {
        $catalog = $this->catalog();
        $orderId = DB::table('orders')->insertGetId([
            'order_number' => 'ORD-EMAIL-NONE-'.bin2hex(random_bytes(4)),
            'guest_email' => null,
            'guest_phone' => null,
            'product_id' => $catalog['product']->id,
            'product_package_id' => $catalog['package_id'],
            'provider_mapping_id' => $catalog['mapping_id'],
            'status' => 'PENDING_PAYMENT',
            'currency' => 'IDR',
            'customer_input' => json_encode(['user_id' => '123456'], JSON_THROW_ON_ERROR),
            'snapshot' => json_encode([]),
            'cost_idr' => 10000,
            'margin_idr' => 1000,
            'discount_idr' => 0,
            'fee_idr' => 78,
            'total_idr' => 11078,
            'idempotency_key' => 'email-moments-none-'.bin2hex(random_bytes(6)),
            'expires_at' => now()->addMinutes(30),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(TransactionalEmailService::class)->queueOrderCreated($orderId);

        Queue::assertNothingPushed();
    }
}
