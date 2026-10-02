<?php

namespace Tests\Feature;

use App\Jobs\ReconcileFulfillmentJob;
use App\Jobs\SendFulfillmentJob;
use App\Models\AdminUser;
use App\Services\AdminManualOrderService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminManualRestorationTest extends TestCase
{
    use DatabaseTransactions;

    private function login(array $permissions = [], string $role = 'SUPER_ADMIN'): AdminUser
    {
        $admin = AdminUser::create([
            'name' => 'Manual regression',
            'email' => bin2hex(random_bytes(8)).'@example.test',
            'password' => bcrypt('manual-regression-only'),
            'role' => $role,
            'permissions' => $permissions,
            'is_active' => true,
        ]);
        $this->actingAs($admin, 'admin');

        return $admin;
    }

    private function order(AdminUser $admin, string $destination = 'TARGET-MANUAL'): int
    {
        return app(AdminManualOrderService::class)->create([
            'customer_name' => 'Pelanggan Manual',
            'phone' => '081234567890',
            'email' => 'manual@example.test',
            'product_name' => 'Produk Manual Saat Dibeli',
            'package_name' => 'Paket Manual',
            'destination' => $destination,
            'total_idr' => 17500,
            'note' => 'Catatan operasional',
            'payment_received' => true,
            'idempotency_key' => (string) Str::uuid(),
        ], (int) $admin->id);
    }

    private function addDigiflazzAttempt(int $orderId, string $status = 'UNKNOWN'): int
    {
        $order = DB::table('orders')->where('id', $orderId)->firstOrFail();
        $providerId = DB::table('providers')->where('code', 'DIGIFLAZZ')->value('id');
        $mappingId = DB::table('provider_mappings')->insertGetId([
            'product_package_id' => $order->product_package_id,
            'provider_id' => $providerId,
            'external_sku' => 'manual-regression-sku-'.bin2hex(random_bytes(3)),
            'cost_idr' => 10000,
            'max_price_idr' => 10000,
            'priority' => 50,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('fulfillment_attempts')->insertGetId([
            'order_id' => $orderId,
            'provider_mapping_id' => $mappingId,
            'provider_id' => $providerId,
            'attempt_no' => 2,
            'external_reference' => 'manual-regression-'.bin2hex(random_bytes(8)),
            'status' => $status,
            'correlation_id' => (string) Str::uuid(),
            'request_payload' => json_encode([
                'buyer_sku_code' => 'manual-regression',
                'customer_no' => 'TARGET-MANUAL',
                'ref_id' => 'manual-regression-ref',
                'max_price' => 10000,
            ], JSON_THROW_ON_ERROR),
            'last_error' => $status === 'UNKNOWN' ? 'Provider request tidak dapat dipastikan.' : null,
            'last_checked_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_manual_workspace_shows_only_latest_attempt_with_human_labels_and_search(): void
    {
        $admin = $this->login();
        $orderId = $this->order($admin);
        $oldAttempt = DB::table('fulfillment_attempts')->where('order_id', $orderId)->firstOrFail();
        $newAttemptId = $this->addDigiflazzAttempt($orderId, 'UNKNOWN');

        $this->get('/admin/fulfillment?'.http_build_query([
            'q' => 'TARGET-MANUAL',
            'scope' => 'action',
            'per_page' => 10,
        ]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Fulfillment')
            ->has('attempts.data', 1)
            ->where('attempts.data.0.id', $newAttemptId)
            ->where('attempts.data.0.status_label', 'Perlu pemeriksaan')
            ->where('attempts.data.0.order_status_label', 'Sedang diproses')
            ->where('attempts.data.0.source', 'Digiflazz')
            ->where('attempts.data.0.destinations.0.label', 'Tujuan / ID akun')
            ->where('attempts.data.0.destinations.0.value', 'TARGET-MANUAL')
            ->where('attempts.data.0.last_error', 'Hasil permintaan kepada penyedia belum dapat dipastikan.')
            ->where('attempts.data.0.can_reconcile', true)
            ->where('metrics.0.value', 0)
            ->where('metrics.1.value', 1)
            ->where('filters.scope', 'action'));

        $this->assertNotSame((int) $oldAttempt->id, $newAttemptId);
        $this->get('/admin/fulfillment?scope=manual')->assertInertia(
            fn (Assert $page) => $page->has('attempts.data', 0)
        );
        $this->get('/admin/fulfillment?status=NOT_A_STATUS')->assertSessionHasErrors('status');
        $this->get('/admin/fulfillment?per_page=500')->assertSessionHasErrors('per_page');
    }

    public function test_stale_attempt_actions_are_blocked_and_latest_uncertain_attempt_can_be_reconciled(): void
    {
        Queue::fake();
        $admin = $this->login();
        $orderId = $this->order($admin);
        $oldAttempt = DB::table('fulfillment_attempts')->where('order_id', $orderId)->firstOrFail();
        DB::table('fulfillment_attempts')->where('id', $oldAttempt->id)->update(['status' => 'UNKNOWN']);
        $newAttemptId = $this->addDigiflazzAttempt($orderId, 'UNKNOWN');

        $this->post('/admin/fulfillment/'.$oldAttempt->id.'/reconcile')
            ->assertSessionHasErrors('fulfillment');
        Queue::assertNotPushed(ReconcileFulfillmentJob::class);

        $this->post('/admin/fulfillment/'.$newAttemptId.'/reconcile')
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        Queue::assertPushed(
            ReconcileFulfillmentJob::class,
            fn (ReconcileFulfillmentJob $job): bool => $job->attemptId === $newAttemptId
        );

        DB::table('fulfillment_attempts')->where('id', $oldAttempt->id)->update([
            'status' => 'BLOCKED',
            'last_error' => 'blocked test',
        ]);
        $this->post('/admin/fulfillment/'.$oldAttempt->id.'/retry')
            ->assertSessionHasErrors('fulfillment');
        Queue::assertNotPushed(SendFulfillmentJob::class);
        $this->assertDatabaseHas('fulfillment_attempts', [
            'id' => $oldAttempt->id,
            'status' => 'BLOCKED',
        ]);
    }

    public function test_stale_manual_pending_attempt_cannot_be_completed_after_a_newer_process_exists(): void
    {
        $admin = $this->login();
        $orderId = $this->order($admin, 'TARGET-STALE');
        $oldAttempt = DB::table('fulfillment_attempts')->where('order_id', $orderId)->firstOrFail();
        $this->addDigiflazzAttempt($orderId, 'UNKNOWN');

        $this->post('/admin/fulfillment/'.$oldAttempt->id.'/complete', [
            'delivery_code' => 'SHOULD-NOT-SEND',
            'note' => 'stale',
        ])->assertSessionHasErrors('fulfillment');

        $this->assertDatabaseHas('fulfillment_attempts', [
            'id' => $oldAttempt->id,
            'status' => 'MANUAL_PENDING',
        ]);
        $this->assertNull(DB::table('orders')->where('id', $orderId)->value('delivery_payload'));
    }

    public function test_manual_workspace_permission_is_required_for_read_and_write_actions(): void
    {
        $owner = $this->login();
        $orderId = $this->order($owner);
        $attemptId = DB::table('fulfillment_attempts')->where('order_id', $orderId)->value('id');

        $this->login(['orders.view'], 'ADMIN');
        $this->get('/admin/fulfillment')->assertForbidden();
        $this->post('/admin/fulfillment/'.$attemptId.'/complete')->assertForbidden();

        $this->login(['fulfillment.manage'], 'ADMIN');
        $this->get('/admin/fulfillment')->assertOk();
    }
}
