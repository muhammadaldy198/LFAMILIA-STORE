<?php

namespace Tests\Feature;

use App\Jobs\ReconcileFulfillmentJob;
use App\Jobs\SendFulfillmentJob;
use App\Jobs\SendTransactionalEmailJob;
use App\Jobs\StartFulfillmentJob;
use App\Models\AdminUser;
use App\Models\IntegrationCredential;
use App\Services\AdminManualOrderService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminOrdersRestorationTest extends TestCase
{
    use DatabaseTransactions;

    private function login(string $role = 'SUPER_ADMIN', array $permissions = []): AdminUser
    {
        $admin = $this->createAdmin([
            'name' => 'Order regression', 'email' => bin2hex(random_bytes(8)).'@example.test',
            'password' => bcrypt('order-regression-only'), 'role' => $role,
            'permissions' => $permissions, 'is_active' => true,
        ]);
        $this->actingAs($admin, 'admin');

        return $admin;
    }

    private function data(): array
    {
        return [
            'customer_name' => '=CUSTOMER', 'phone' => '081234567890', 'email' => 'orders@example.test',
            'product_name' => 'Produk saat dibeli', 'package_name' => 'Paket khusus',
            'destination' => 'TARGET-REGRESSION', 'total_idr' => 15000, 'note' => 'Pembayaran diterima di toko',
            'payment_received' => true, 'idempotency_key' => (string) Str::uuid(),
        ];
    }

    private function order(AdminUser $admin, ?array $data = null): int
    {
        return app(AdminManualOrderService::class)->create($data ?? $this->data(), (int) $admin->id);
    }

    public function test_manual_order_is_paid_queued_once_and_never_sent_to_an_external_provider(): void
    {
        $admin = $this->login();
        Queue::fake();
        Http::swap(new Factory);
        Http::fake();
        $data = $this->data();
        $this->post('/admin/orders/manual', $data)->assertRedirect();
        $order = DB::table('orders')->where('guest_email', $data['email'])->orderByDesc('id')->first();
        $this->post('/admin/orders/manual', $data)->assertRedirect(route('admin.orders.show', $order->id));
        $this->assertSame(1, DB::table('orders')->where('idempotency_key', 'admin-manual:'.$admin->id.':'.$data['idempotency_key'])->count());
        $this->assertSame('PROCESSING', $order->status);
        $this->assertNotNull($order->paid_at);
        $this->assertSame(1, DB::table('fulfillment_attempts')->where('order_id', $order->id)->where('status', 'MANUAL_PENDING')->count());
        $this->assertSame(1, DB::table('order_events')->where('order_id', $order->id)->where('event_type', 'ADMIN_MANUAL_ORDER_CREATED')->count());
        $this->assertSame(0, DB::table('payment_transactions')->where('order_id', $order->id)->count());
        Queue::assertNotPushed(StartFulfillmentJob::class);
        Queue::assertNotPushed(SendFulfillmentJob::class);
        Queue::assertNotPushed(ReconcileFulfillmentJob::class);
        Http::assertNothingSent();
        $this->post('/admin/orders/manual', [...$data, 'total_idr' => 16000])->assertSessionHasErrors('order');
        $this->post('/admin/orders/manual', [...$data, 'idempotency_key' => (string) Str::uuid(), 'payment_received' => false])->assertSessionHasErrors('payment_received');
    }

    public function test_search_snapshot_filters_wib_dates_and_csv_selection_match_the_list(): void
    {
        $admin = $this->login();
        $id = $this->order($admin);
        DB::table('orders')->where('id', $id)->update(['created_at' => '2026-10-01 17:00:00']);
        $row = DB::table('orders')->where('id', $id)->first();
        DB::table('products')->where('id', $row->product_id)->update(['name' => 'Catalog renamed']);
        $params = ['q' => 'TARGET-REGRESSION', 'provider' => 'MANUAL', 'payment' => 'ADMIN_MANUAL', 'from' => '2026-10-02', 'to' => '2026-10-02'];
        $this->get('/admin/orders?'.http_build_query($params))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Orders')->has('orders.data', 1)->where('orders.data.0.id', $id)
            ->where('orders.data.0.product_name', 'Produk saat dibeli')
            ->where('orders.data.0.buyer_phone', '081234567890')->where('orders.data.0.status_label', 'Sedang diproses')
            ->where('orders.data.0.destinations.0.label', 'Tujuan')->where('metrics.0.value', 1));
        $this->get('/admin/orders?'.http_build_query([...$params, 'to' => '2026-10-01']))->assertSessionHasErrors('to');
        $this->get('/admin/orders?'.http_build_query(['q' => 'TARGET-REGRESSION', 'to' => '2026-10-01']))->assertInertia(fn (Assert $page) => $page->has('orders.data', 0));
        $this->get('/admin/orders?q=Produk%20saat%20dibeli')->assertInertia(fn (Assert $page) => $page->where('orders.data.0.id', $id));
        $csv = $this->get('/admin/orders/export?'.http_build_query([...$params, 'selected' => [$id]]))->assertOk()->streamedContent();
        $this->assertStringContainsString("'=CUSTOMER", $csv);
        $this->assertStringContainsString('Produk saat dibeli', $csv);
        $this->assertStringContainsString('Sedang diproses', $csv);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $empty = $this->get('/admin/orders/export?selected[]=999999999')->assertOk()->streamedContent();
        $this->assertStringNotContainsString($row->order_number, $empty);
    }

    public function test_order_list_and_csv_export_batch_load_product_field_labels(): void
    {
        $admin = $this->login();
        $ids = [];
        foreach (['Batch Product Alpha', 'Batch Product Beta', 'Batch Product Gamma'] as $name) {
            $ids[] = $this->order($admin, [
                ...$this->data(),
                'product_name' => $name,
            ]);
        }
        $this->assertSame(3, DB::table('orders')->whereIn('id', $ids)
            ->distinct()->count('product_id'));

        $fieldLabelQueries = [];
        DB::listen(function ($query) use (&$fieldLabelQueries): void {
            if (str_contains(strtolower($query->sql), 'from `product_input_fields`')) {
                $fieldLabelQueries[] = $query->sql;
            }
        });

        $this->get('/admin/orders?per_page=25')->assertOk();
        $this->assertCount(1, $fieldLabelQueries);

        $fieldLabelQueries = [];
        $this->get('/admin/orders/export?'.http_build_query(['selected' => $ids]))
            ->assertOk()->streamedContent();
        $this->assertCount(1, $fieldLabelQueries);
    }

    public function test_detail_includes_guest_contact_nullable_provider_attempt_delivery_and_translated_events(): void
    {
        $admin = $this->login();
        $id = $this->order($admin);
        $attempt = DB::table('fulfillment_attempts')->where('order_id', $id)->first();
        DB::table('fulfillment_attempts')->where('id', $attempt->id)->update(['provider_id' => null]);
        $this->get('/admin/orders/'.$id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/OrderDetail')->where('order.buyer_email', 'orders@example.test')
            ->where('order.buyer_phone', '081234567890')->has('attempts', 1)
            ->where('attempts.0.can_manual', true)->where('canCheckFulfillment', false)
            ->where('events.0.label', 'Pesanan masuk antrean penanganan manual'));
        $this->post('/admin/fulfillment/'.$attempt->id.'/complete', ['delivery_code' => 'CODE-REGRESSION', 'note' => 'Sudah dikirim'])->assertRedirect();
        $this->get('/admin/orders/'.$id)->assertInertia(fn (Assert $page) => $page
            ->where('order.status_label', 'Berhasil')->where('order.delivery.code', 'CODE-REGRESSION')
            ->where('attempts.0.can_manual', false)->where('attempts.0.can_retry', false)
            ->where('canResendDelivery', true));
    }

    public function test_permissions_protect_write_actions_while_order_read_access_remains_available(): void
    {
        $owner = $this->login();
        $id = $this->order($owner);
        $this->login('ADMIN', ['orders.view']);
        $this->get('/admin/orders')->assertInertia(fn (Assert $page) => $page->where('canCreateManual', false));
        $this->get('/admin/orders/'.$id)->assertInertia(fn (Assert $page) => $page
            ->where('attempts.0.can_manual', false)->where('canCheckFulfillment', false)->where('canViewCustomer', false));
        $this->post('/admin/orders/manual', $this->data())->assertForbidden();
        $this->post('/admin/orders/'.$id.'/check-payment')->assertForbidden();
        $this->post('/admin/orders/'.$id.'/check-process')->assertForbidden();
        $this->post('/admin/orders/'.$id.'/resend-delivery')->assertForbidden();
        $this->login('ADMIN', ['dashboard.view']);
        $this->get('/admin/orders')->assertForbidden();
        $this->get('/admin/orders/export')->assertForbidden();
    }

    public function test_check_process_reuses_existing_attempt_and_unknown_result_cannot_be_retried(): void
    {
        $admin = $this->login();
        $id = $this->order($admin);
        $attempt = DB::table('fulfillment_attempts')->where('order_id', $id)->first();
        DB::table('fulfillment_attempts')->where('id', $attempt->id)->update(['status' => 'UNKNOWN']);
        Queue::fake();
        $this->post('/admin/orders/'.$id.'/check-process')->assertRedirect();
        Queue::assertPushed(ReconcileFulfillmentJob::class, fn ($job) => $job->attemptId === (int) $attempt->id);
        $this->post('/admin/fulfillment/'.$attempt->id.'/retry')->assertSessionHasErrors('fulfillment');
        $this->assertSame(1, DB::table('fulfillment_attempts')->where('order_id', $id)->count());
        $this->assertSame('UNKNOWN', DB::table('fulfillment_attempts')->where('id', $attempt->id)->value('status'));
    }

    public function test_resend_delivery_queues_saved_result_without_reprocessing_provider(): void
    {
        $admin = $this->login();
        $id = $this->order($admin);
        $attempt = DB::table('fulfillment_attempts')->where('order_id', $id)->first();
        $this->post('/admin/fulfillment/'.$attempt->id.'/complete', [
            'delivery_code' => 'VOUCHER-RESEND-123',
            'note' => 'Gunakan satu kali.',
        ])->assertRedirect();

        $attemptCount = DB::table('fulfillment_attempts')->where('order_id', $id)->count();
        Queue::fake();
        Http::swap(new Factory);
        Http::fake();

        $this->post('/admin/orders/'.$id.'/resend-delivery')->assertRedirect();
        Queue::assertPushed(SendTransactionalEmailJob::class, function (SendTransactionalEmailJob $job): bool {
            return $job->recipient === 'orders@example.test'
                && str_contains($job->subject, 'Hasil pesanan')
                && str_contains($job->text, 'VOUCHER-RESEND-123')
                && str_contains($job->text, 'Gunakan satu kali.');
        });
        $this->assertSame($attemptCount, DB::table('fulfillment_attempts')->where('order_id', $id)->count());
        $this->assertSame('SUCCESS', DB::table('orders')->where('id', $id)->value('status'));
        Http::assertNothingSent();
    }

    public function test_payment_check_rejects_wrong_reference_or_amount_and_applies_verified_results_once(): void
    {
        $admin = $this->login();
        $id = $this->order($admin);
        DB::table('orders')->where('id', $id)->update(['status' => 'PENDING_PAYMENT', 'paid_at' => null]);
        DB::table('fulfillment_attempts')->where('order_id', $id)->delete();
        $paymentId = DB::table('payment_transactions')->insertGetId([
            'order_id' => $id, 'gateway_code' => 'MIDTRANS', 'channel_code' => 'QRIS', 'amount_idr' => 15000,
            'status' => 'PENDING', 'merchant_reference' => 'CHECK-ORDER-REGRESSION',
            'idempotency_key' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        IntegrationCredential::updateOrCreate(['code' => 'midtrans'], ['is_active' => true, 'config_ciphertext' => ['server_key' => 'regression-fixture-only', 'is_production' => false]]);
        Queue::fake();
        Http::swap(new Factory);
        Http::fake(['*' => Http::response(['order_id' => 'WRONG', 'gross_amount' => '15000.00', 'status_code' => '200', 'transaction_status' => 'settlement'])]);
        $this->post('/admin/orders/'.$id.'/check-payment')->assertSessionHasErrors('payment');
        $this->assertSame('PENDING', DB::table('payment_transactions')->where('id', $paymentId)->value('status'));
        Http::swap(new Factory);
        Http::fake(['*' => Http::response(['order_id' => 'CHECK-ORDER-REGRESSION', 'gross_amount' => '14000.00', 'status_code' => '200', 'transaction_status' => 'settlement'])]);
        $this->post('/admin/orders/'.$id.'/check-payment')->assertSessionHasErrors('payment');
        $this->assertSame('PENDING_PAYMENT', DB::table('orders')->where('id', $id)->value('status'));
        Http::swap(new Factory);
        Http::fake(['*' => Http::response(['order_id' => 'CHECK-ORDER-REGRESSION', 'gross_amount' => '15000.00', 'status_code' => '200', 'transaction_status' => 'settlement'])]);
        $this->post('/admin/orders/'.$id.'/check-payment')->assertRedirect();
        $this->assertSame('PAID', DB::table('orders')->where('id', $id)->value('status'));
        $this->assertSame('PAID', DB::table('payment_transactions')->where('id', $paymentId)->value('status'));
        $this->post('/admin/orders/'.$id.'/check-payment')->assertSessionHasErrors('payment');
        Queue::assertPushed(StartFulfillmentJob::class, 1);
    }
}
