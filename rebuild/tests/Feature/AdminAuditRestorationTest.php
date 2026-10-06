<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminAuditRestorationTest extends TestCase
{
    use DatabaseTransactions;

    private function login(string $role = 'SUPER_ADMIN', array $permissions = []): AdminUser
    {
        $admin = tap(AdminUser::create([
            'name' => $role === 'SUPER_ADMIN' ? 'Audit Owner' : 'Audit Admin',
            'email' => strtolower($role).'-'.bin2hex(random_bytes(5)).'@example.test',
            'password' => Hash::make('VeryStrongPassword123!'),
        ]), fn ($admin) => $admin->forceFill([
            'role' => $role,
            'permissions' => $role === 'SUPER_ADMIN' ? null : $permissions,
            'is_active' => true,
        ])->save());

        $this->actingAs($admin, 'admin');

        return $admin;
    }

    private function insertAudit(
        ?AdminUser $actor,
        string $action,
        string $targetType,
        ?string $targetId,
        mixed $before = null,
        mixed $after = null,
        ?string $createdAt = null,
    ): int {
        return (int) DB::table('audit_logs')->insertGetId([
            'actor_type' => $actor ? 'admin_user' : null,
            'actor_id' => $actor ? (string) $actor->id : null,
            'actor_role' => $actor?->role,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'before' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after' => $after === null ? null : json_encode($after, JSON_THROW_ON_ERROR),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'LFAMILIA Audit Test Browser',
            'correlation_id' => 'audit-'.bin2hex(random_bytes(8)),
            'created_at' => $createdAt ?? now()->format('Y-m-d H:i:s'),
        ]);
    }

    public function test_audit_log_is_a_dedicated_super_admin_workspace_with_filters_and_read_time_redaction(): void
    {
        DB::table('audit_logs')->delete();
        $owner = $this->login();

        $id = $this->insertAudit(
            $owner,
            'integration.updated',
            'integration',
            'midtrans',
            [
                'api_key' => 'legacy-api-secret',
                'nested' => [
                    'password' => 'legacy-password-secret',
                    'apiKey' => 'legacy-camel-api-secret',
                ],
                'mode' => 'sandbox',
            ],
            [
                'client_key' => 'legacy-client-secret',
                'mode' => 'production',
            ],
        );

        $response = $this->get('/admin/audit?'.http_build_query([
            'q' => 'Audit Owner',
            'actor_id' => $owner->id,
            'role' => 'SUPER_ADMIN',
            'action' => 'integration.updated',
            'target_type' => 'integration',
            'date_from' => now()->format('Y-m-d'),
            'date_to' => now()->format('Y-m-d'),
            'per_page' => 25,
        ]))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Audit')
            ->where('logs.total', 1)
            ->where('logs.data.0.id', $id)
            ->where('logs.data.0.actor.name', 'Audit Owner')
            ->where('logs.data.0.actor.role', 'SUPER_ADMIN')
            ->where('logs.data.0.action', 'integration.updated')
            ->where('logs.data.0.action_label', 'Integrasi — diperbarui')
            ->where('logs.data.0.target_label', 'Integrasi')
            ->where('logs.data.0.created_at', fn ($value): bool => is_string($value)
                && str_contains($value, 'T')
                && preg_match('/(?:Z|[+-]\\d{2}:\\d{2})$/', $value) === 1)
            ->where('logs.data.0.before_state.api_key', '[REDACTED]')
            ->where('logs.data.0.before_state.nested.password', '[REDACTED]')
            ->where('logs.data.0.before_state.nested.apiKey', '[REDACTED]')
            ->where('logs.data.0.before_state.mode', 'sandbox')
            ->where('logs.data.0.after_state.client_key', '[REDACTED]')
            ->where('logs.data.0.after_state.mode', 'production')
            ->where('filters.actor_id', $owner->id)
            ->where('filters.per_page', 25)
            ->where('summary.total', 1)
            ->has('actors')
            ->has('actions')
            ->has('targetTypes'));

        $content = $response->getContent();
        $this->assertStringNotContainsString('legacy-api-secret', $content);
        $this->assertStringNotContainsString('legacy-password-secret', $content);
        $this->assertStringNotContainsString('legacy-camel-api-secret', $content);
        $this->assertStringNotContainsString('legacy-client-secret', $content);
    }

    public function test_regular_admin_cannot_open_audit_log(): void
    {
        $this->login('ADMIN', ['dashboard.view']);

        $this->get('/admin/audit')->assertForbidden();
    }

    public function test_search_can_match_admin_identity_and_pagination_keeps_audit_rows_read_only(): void
    {
        DB::table('audit_logs')->delete();
        $owner = $this->login();

        $other = tap(AdminUser::create([
            'name' => 'Operator Pencarian Audit',
            'email' => 'operator-audit-'.bin2hex(random_bytes(4)).'@example.test',
            'password' => Hash::make('VeryStrongPassword123!'),
        ]), fn ($admin) => $admin->forceFill([
            'role' => 'ADMIN',
            'permissions' => ['dashboard.view'],
            'is_active' => true,
        ])->save());

        for ($index = 1; $index <= 30; $index++) {
            $this->insertAudit(
                $index <= 26 ? $other : $owner,
                'provider.updated',
                'provider',
                (string) $index,
                ['display_name' => 'Provider '.$index],
                ['display_name' => 'Provider Baru '.$index],
            );
        }

        $response = $this->get('/admin/audit?q=Operator%20Pencarian%20Audit&per_page=25')->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Audit')
            ->where('logs.total', 26)
            ->where('logs.per_page', 25)
            ->where('logs.last_page', 2)
            ->has('logs.data', 25));

        $this->assertSame(30, DB::table('audit_logs')->count());
    }

    public function test_end_date_filter_works_without_a_start_date(): void
    {
        DB::table('audit_logs')->delete();
        $owner = $this->login();

        $this->insertAudit(
            $owner,
            'provider.updated',
            'provider',
            'old',
            null,
            ['name' => 'Old'],
            now()->subDays(2)->format('Y-m-d H:i:s'),
        );
        $this->insertAudit(
            $owner,
            'provider.updated',
            'provider',
            'today',
            null,
            ['name' => 'Today'],
            now()->format('Y-m-d H:i:s'),
        );

        $this->get('/admin/audit?date_to='.now()->subDay()->format('Y-m-d'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('logs.total', 1)
                ->where('logs.data.0.target_id', 'old')
                ->where('filters.date_from', '')
                ->where('filters.date_to', now()->subDay()->format('Y-m-d')));
    }

    public function test_system_actor_role_is_normalized_and_filterable(): void
    {
        DB::table('audit_logs')->delete();
        $this->login();

        DB::table('audit_logs')->insert([
            'actor_type' => 'system',
            'actor_id' => null,
            'actor_role' => 'PROVIDER_SYNC',
            'action' => 'digiflazz.product.synced',
            'target_type' => 'product',
            'target_id' => '77',
            'before' => null,
            'after' => json_encode(['count' => 3], JSON_THROW_ON_ERROR),
            'ip_address' => null,
            'user_agent' => null,
            'correlation_id' => 'system-provider-sync-test',
            'created_at' => now(),
        ]);

        $this->get('/admin/audit?role=SYSTEM')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('logs.total', 1)
                ->where('logs.data.0.actor.name', 'Sistem')
                ->where('logs.data.0.actor.role', 'SYSTEM')
                ->where('logs.data.0.actor.id', null)
                ->where('filters.role', 'SYSTEM'));
    }

    public function test_legacy_scalar_text_payload_is_not_rendered_back_to_browser(): void
    {
        DB::table('audit_logs')->delete();
        $owner = $this->login();

        DB::table('audit_logs')->insert([
            'actor_type' => 'admin_user',
            'actor_id' => (string) $owner->id,
            'actor_role' => 'SUPER_ADMIN',
            'action' => 'settings.updated',
            'target_type' => 'settings',
            'target_id' => 'legacy',
            'before' => json_encode('legacy-raw-secret-value', JSON_THROW_ON_ERROR),
            'after' => json_encode('legacy-other-secret-value', JSON_THROW_ON_ERROR),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Legacy Browser',
            'correlation_id' => 'legacy-scalar-audit',
            'created_at' => now(),
        ]);

        $response = $this->get('/admin/audit')->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->where('logs.data.0.before_state', '[Nilai teks disembunyikan untuk keamanan]')
            ->where('logs.data.0.after_state', '[Nilai teks disembunyikan untuk keamanan]'));

        $this->assertStringNotContainsString('legacy-raw-secret-value', $response->getContent());
        $this->assertStringNotContainsString('legacy-other-secret-value', $response->getContent());
    }
}
