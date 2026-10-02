<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\IntegrationCredential;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
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
