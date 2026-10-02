<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\IntegrationCredential;
use App\Services\AdminManualOrderService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminHealthRestorationTest extends TestCase
{
    use DatabaseTransactions;

    private function login(string $role = 'SUPER_ADMIN'): AdminUser
    {
        $admin = AdminUser::create([
            'name' => 'Health Test',
            'email' => 'health-'.bin2hex(random_bytes(6)).'@example.test',
            'password' => Hash::make('VeryStrongPassword123!'),
            'role' => $role,
            'permissions' => $role === 'SUPER_ADMIN' ? null : ['dashboard.view'],
            'is_active' => true,
        ]);

        $this->actingAs($admin, 'admin');

        return $admin;
    }

    private function setSetting(string $key, mixed $value): void
    {
        DB::table('system_settings')->updateOrInsert(
            ['key' => $key],
            [
                'value' => json_encode($value, JSON_THROW_ON_ERROR),
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function test_system_health_is_a_dedicated_super_admin_workspace_without_secret_payloads(): void
    {
        $this->login();
        Http::fake();

        $this->setSetting('system.queue_worker_heartbeat', now()->toIso8601String());
        $this->setSetting('system.scheduler_heartbeat', now()->toIso8601String());

        IntegrationCredential::updateOrCreate(['code' => 'digiflazz'], [
            'is_active' => true,
            'config_ciphertext' => [
                'username' => 'health-buyer',
                'api_key' => 'health-secret-must-not-render',
            ],
        ]);
        $this->setSetting('integration.health.digiflazz', [
            'status' => 'HEALTHY',
            'message' => 'health-secret-must-not-render',
            'tested_at' => now()->subMinute()->toIso8601String(),
        ]);

        $response = $this->get('/admin/health')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Health')
            ->has('checks', 8)
            ->has('integrations', 9)
            ->has('gateways', 4)
            ->where('integrations.0.code', 'digiflazz')
            ->where('integrations.0.status', 'HEALTHY')
            ->where('integrations.0.message', 'Tes koneksi terakhir berhasil.')
            ->has('summary')
            ->has('environment')
            ->has('checkedAt'));

        $this->assertStringNotContainsString('health-secret-must-not-render', $response->getContent());
        Http::assertNothingSent();
    }

    public function test_regular_admin_cannot_open_system_health(): void
    {
        $this->login('ADMIN');

        $this->get('/admin/health')->assertForbidden();
    }

    public function test_invalid_or_stale_heartbeats_degrade_without_breaking_the_page(): void
    {
        $this->login();

        $this->setSetting('system.queue_worker_heartbeat', 'not-a-timestamp');
        $this->setSetting('system.scheduler_heartbeat', now()->subMinutes(10)->toIso8601String());

        $this->get('/admin/health')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Health')
                ->where('checks.4.key', 'queue')
                ->where('checks.4.status', 'DEGRADED')
                ->where('checks.5.key', 'scheduler')
                ->where('checks.5.status', 'DEGRADED'));
    }

    public function test_old_healthy_integration_check_is_marked_stale(): void
    {
        $this->login();
        DB::table('failed_jobs')->delete();
        $this->setSetting('system.queue_worker_heartbeat', now()->toIso8601String());
        $this->setSetting('system.scheduler_heartbeat', now()->toIso8601String());

        IntegrationCredential::updateOrCreate(['code' => 'digiflazz'], [
            'is_active' => true,
            'config_ciphertext' => [
                'username' => 'stale-health-buyer',
                'api_key' => 'stale-health-secret',
            ],
        ]);
        $this->setSetting('integration.health.digiflazz', [
            'status' => 'HEALTHY',
            'message' => 'Old healthy provider response',
            'tested_at' => now()->subMinutes(20)->toIso8601String(),
        ]);

        $response = $this->get('/admin/health')->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->where('summary.overall', 'DEGRADED')
            ->where('integrations.0.code', 'digiflazz')
            ->where('integrations.0.status', 'STALE')
            ->where('integrations.0.message', 'Tes koneksi terakhir sudah lebih dari 15 menit.'));

        $this->assertStringNotContainsString('stale-health-secret', $response->getContent());
    }

    public function test_gateway_maintenance_overrides_stored_integration_health(): void
    {
        $this->login();

        IntegrationCredential::updateOrCreate(['code' => 'doku'], [
            'is_active' => true,
            'config_ciphertext' => [
                'client_id' => 'health-client-id',
                'secret_key' => 'health-doku-secret',
            ],
        ]);
        $this->setSetting('integration.health.doku', [
            'status' => 'HEALTHY',
            'message' => 'Stored healthy',
            'tested_at' => now()->toIso8601String(),
        ]);
        DB::table('payment_gateways')->where('code', 'DOKU')->update([
            'is_active' => true,
            'is_maintenance' => true,
        ]);

        $response = $this->get('/admin/health')->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->where('integrations.3.code', 'doku')
            ->where('integrations.3.status', 'MAINTENANCE')
            ->where('integrations.3.message', 'Gateway sedang dalam mode maintenance.'));

        $this->assertStringNotContainsString('health-doku-secret', $response->getContent());
    }

    public function test_active_down_integration_makes_overall_health_down(): void
    {
        $this->login();
        $this->setSetting('system.queue_worker_heartbeat', now()->toIso8601String());
        $this->setSetting('system.scheduler_heartbeat', now()->toIso8601String());

        IntegrationCredential::updateOrCreate(['code' => 'digiflazz'], [
            'is_active' => true,
            'config_ciphertext' => [
                'username' => 'health-down-buyer',
                'api_key' => 'health-down-secret',
            ],
        ]);
        $this->setSetting('integration.health.digiflazz', [
            'status' => 'DOWN',
            'message' => 'Provider rejected secret health-down-secret',
            'tested_at' => now()->toIso8601String(),
        ]);

        $response = $this->get('/admin/health')->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->where('summary.overall', 'DOWN')
            ->where('integrations.0.code', 'digiflazz')
            ->where('integrations.0.status', 'DOWN'));

        $this->assertStringNotContainsString('health-down-secret', $response->getContent());
    }

    public function test_stale_fulfillment_count_only_uses_latest_attempt_per_order(): void
    {
        $admin = $this->login();

        $orderId = app(AdminManualOrderService::class)->create([
            'customer_name' => 'Health Customer',
            'phone' => '081234567890',
            'email' => 'health-customer@example.test',
            'product_name' => 'Health Manual Product',
            'package_name' => 'Health Manual Package',
            'destination' => 'HEALTH-TARGET',
            'total_idr' => 15000,
            'note' => 'Health regression',
            'payment_received' => true,
            'idempotency_key' => (string) Str::uuid(),
        ], (int) $admin->id);

        $oldAttempt = DB::table('fulfillment_attempts')->where('order_id', $orderId)->firstOrFail();
        DB::table('fulfillment_attempts')->where('id', $oldAttempt->id)->update([
            'status' => 'UNKNOWN',
            'updated_at' => now()->subMinutes(20),
        ]);

        $order = DB::table('orders')->where('id', $orderId)->firstOrFail();
        $providerId = (int) DB::table('providers')->where('code', 'DIGIFLAZZ')->value('id');
        $mappingId = DB::table('provider_mappings')->insertGetId([
            'product_package_id' => $order->product_package_id,
            'provider_id' => $providerId,
            'external_sku' => 'health-latest-'.bin2hex(random_bytes(4)),
            'cost_idr' => 10000,
            'max_price_idr' => 10000,
            'priority' => 50,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('fulfillment_attempts')->insert([
            'order_id' => $orderId,
            'provider_mapping_id' => $mappingId,
            'provider_id' => $providerId,
            'attempt_no' => 2,
            'external_reference' => 'health-latest-'.bin2hex(random_bytes(8)),
            'status' => 'CREATED',
            'correlation_id' => (string) Str::uuid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->get('/admin/health')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.stale_fulfillment', 0)
                ->where('checks.7.key', 'fulfillment')
                ->where('checks.7.count', 0)
                ->where('checks.7.status', 'HEALTHY'));
    }

    public function test_reconciliation_poll_does_not_hide_old_unresolved_latest_attempt(): void
    {
        $admin = $this->login();

        $orderId = app(AdminManualOrderService::class)->create([
            'customer_name' => 'Health Pending Customer',
            'phone' => '081234567891',
            'email' => 'health-pending@example.test',
            'product_name' => 'Health Pending Product',
            'package_name' => 'Health Pending Package',
            'destination' => 'HEALTH-PENDING',
            'total_idr' => 16000,
            'note' => 'Health pending regression',
            'payment_received' => true,
            'idempotency_key' => (string) Str::uuid(),
        ], (int) $admin->id);

        $attempt = DB::table('fulfillment_attempts')->where('order_id', $orderId)->firstOrFail();
        DB::table('fulfillment_attempts')->where('id', $attempt->id)->update([
            'status' => 'PENDING',
            'created_at' => now()->subMinutes(20),
            'updated_at' => now(),
            'last_checked_at' => now(),
        ]);

        $this->get('/admin/health')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.stale_fulfillment', 1)
                ->where('checks.7.key', 'fulfillment')
                ->where('checks.7.count', 1)
                ->where('checks.7.status', 'DEGRADED'));
    }

    public function test_active_gateway_maintenance_is_reflected_in_overall_health_without_double_counting_integrations(): void
    {
        $this->login();
        DB::table('failed_jobs')->delete();
        $this->setSetting('system.queue_worker_heartbeat', now()->toIso8601String());
        $this->setSetting('system.scheduler_heartbeat', now()->toIso8601String());

        DB::table('payment_gateways')->where('code', 'MANUAL_QRIS')->update([
            'is_active' => true,
            'is_maintenance' => true,
        ]);

        $this->get('/admin/health')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.overall', 'DEGRADED')
                ->where('summary.attention', fn ($value): bool => (int) $value >= 1)
                ->where('gateways.2.code', 'MANUAL_QRIS')
                ->where('gateways.2.status', 'MAINTENANCE'));
    }

    public function test_active_external_gateway_with_inactive_credentials_is_degraded(): void
    {
        $this->login();
        DB::table('failed_jobs')->delete();
        $this->setSetting('system.queue_worker_heartbeat', now()->toIso8601String());
        $this->setSetting('system.scheduler_heartbeat', now()->toIso8601String());

        IntegrationCredential::updateOrCreate(['code' => 'doku'], [
            'is_active' => false,
            'config_ciphertext' => [
                'client_id' => 'inactive-health-client',
                'secret_key' => 'inactive-health-secret',
            ],
        ]);
        DB::table('payment_gateways')->where('code', 'DOKU')->update([
            'is_active' => true,
            'is_maintenance' => false,
        ]);

        $response = $this->get('/admin/health')->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->where('summary.overall', 'DEGRADED')
            ->where('gateways.1.code', 'DOKU')
            ->where('gateways.1.status', 'DEGRADED'));

        $this->assertStringNotContainsString('inactive-health-secret', $response->getContent());
    }

    public function test_failed_jobs_are_reported_as_attention_without_exposing_job_payload(): void
    {
        $this->login();

        DB::table('failed_jobs')->delete();
        DB::table('failed_jobs')->insert([
            'uuid' => 'health-failed-job-'.bin2hex(random_bytes(4)),
            'connection' => 'redis',
            'queue' => 'default',
            'payload' => '{"secret":"must-not-render-job-payload"}',
            'exception' => 'Test failure detail that must stay outside the health page.',
            'failed_at' => now(),
        ]);

        $response = $this->get('/admin/health')->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->where('summary.failed_jobs', 1)
            ->where('checks.6.key', 'failed_jobs')
            ->where('checks.6.status', 'DEGRADED')
            ->where('checks.6.count', 1));

        $this->assertStringNotContainsString('must-not-render-job-payload', $response->getContent());
        $this->assertStringNotContainsString('Test failure detail', $response->getContent());
    }
}
