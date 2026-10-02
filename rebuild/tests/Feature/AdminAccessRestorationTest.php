<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminAccessRestorationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    private function admin(string $role = 'SUPER_ADMIN', array $overrides = []): AdminUser
    {
        return AdminUser::create(array_replace([
            'name' => $role === 'SUPER_ADMIN' ? 'Owner Test' : 'Admin Test',
            'email' => bin2hex(random_bytes(8)).'@example.test',
            'password' => Hash::make('access-restoration-password'),
            'role' => $role,
            'permissions' => $role === 'ADMIN' ? ['dashboard.view', 'orders.view'] : null,
            'is_active' => true,
        ], $overrides));
    }

    private function loginSuper(array $overrides = []): AdminUser
    {
        $admin = $this->admin('SUPER_ADMIN', $overrides);
        $this->actingAs($admin, 'admin');

        return $admin;
    }

    public function test_access_workspace_has_summary_filters_pagination_permissions_and_recent_activity(): void
    {
        $owner = $this->loginSuper(['name' => 'Owner Workspace']);
        $target = $this->admin('ADMIN', [
            'name' => 'Finance Operator',
            'email' => 'finance-operator@example.test',
            'permissions' => ['dashboard.view', 'reports.view'],
        ]);
        $this->admin('ADMIN', [
            'name' => 'Hidden Operator',
            'is_active' => false,
        ]);

        DB::table('audit_logs')->insert([
            'actor_type' => 'admin_user',
            'actor_id' => (string) $owner->id,
            'actor_role' => 'SUPER_ADMIN',
            'action' => 'admin.updated',
            'target_type' => 'admin_user',
            'target_id' => (string) $target->id,
            'before' => null,
            'after' => null,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'correlation_id' => 'access-workspace-activity',
            'created_at' => now(),
        ]);

        $this->get('/admin/access?q=Finance&role=ADMIN&status=active&per_page=10')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Access')
                ->where('currentAdminId', $owner->id)
                ->where('summary.total', 3)
                ->where('summary.super_admins', 1)
                ->where('summary.admins', 2)
                ->where('summary.active', 2)
                ->where('summary.inactive', 1)
                ->where('summary.active_super_admins', 1)
                ->has('admins.data', 1)
                ->where('admins.data.0.id', $target->id)
                ->where('admins.data.0.name', 'Finance Operator')
                ->where('admins.data.0.permissions', ['dashboard.view', 'reports.view'])
                ->where('filters.role', 'ADMIN')
                ->where('filters.status', 'active')
                ->where('filters.per_page', 10)
                ->has('permissions')
                ->has('recentActivities', 1)
                ->where('recentActivities.0.actor_name', 'Owner Workspace')
                ->where('recentActivities.0.action', 'admin.updated'));
    }

    public function test_super_admin_can_create_admin_and_dashboard_permission_is_mandatory(): void
    {
        $owner = $this->loginSuper();

        $this->post('/admin/access', [
            'name' => 'Support Operator',
            'email' => 'support-operator@example.test',
            'password' => 'temporary-password-123',
            'role' => 'ADMIN',
            'permissions' => ['support.manage'],
            'is_active' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $created = AdminUser::where('email', 'support-operator@example.test')->firstOrFail();
        $this->assertSame('ADMIN', $created->role);
        $this->assertTrue($created->is_active);
        $this->assertContains('dashboard.view', $created->permissions);
        $this->assertContains('support.manage', $created->permissions);
        $this->assertTrue(Hash::check('temporary-password-123', $created->password));

        $audit = DB::table('audit_logs')
            ->where('action', 'admin.created')
            ->where('target_id', (string) $created->id)
            ->first();
        $this->assertNotNull($audit);
        $this->assertSame((string) $owner->id, $audit->actor_id);
        $this->assertStringNotContainsString('temporary-password-123', (string) $audit->after);
    }

    public function test_staff_role_is_not_reintroduced(): void
    {
        $this->loginSuper();

        $this->post('/admin/access', [
            'name' => 'Legacy Staff',
            'email' => 'legacy-staff@example.test',
            'password' => 'temporary-password-123',
            'role' => 'STAFF',
            'permissions' => ['dashboard.view'],
            'is_active' => true,
        ])->assertSessionHasErrors(['role']);

        $this->assertDatabaseMissing('admin_users', ['email' => 'legacy-staff@example.test']);
    }

    public function test_super_admin_can_update_admin_permissions_status_and_password_with_audit(): void
    {
        $owner = $this->loginSuper();
        $target = $this->admin('ADMIN', [
            'email' => 'operator-update@example.test',
            'permissions' => ['dashboard.view', 'orders.view'],
        ]);

        $this->put('/admin/access/'.$target->id, [
            'name' => 'Updated Operator',
            'email' => 'updated-operator@example.test',
            'password' => 'new-secure-password-456',
            'role' => 'ADMIN',
            'permissions' => ['support.manage', 'reports.view'],
            'is_active' => false,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $target->refresh();
        $this->assertSame('Updated Operator', $target->name);
        $this->assertSame('updated-operator@example.test', $target->email);
        $this->assertFalse($target->is_active);
        $this->assertSame(
            ['support.manage', 'reports.view', 'dashboard.view'],
            $target->permissions
        );
        $this->assertTrue(Hash::check('new-secure-password-456', $target->password));

        $audit = DB::table('audit_logs')
            ->where('action', 'admin.updated')
            ->where('target_id', (string) $target->id)
            ->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertSame((string) $owner->id, $audit->actor_id);
        $this->assertStringNotContainsString('new-secure-password-456', (string) $audit->after);
    }

    public function test_current_account_cannot_be_disabled_demoted_or_deleted(): void
    {
        $owner = $this->loginSuper();

        $this->put('/admin/access/'.$owner->id, [
            'name' => $owner->name,
            'email' => $owner->email,
            'password' => null,
            'role' => 'ADMIN',
            'permissions' => ['dashboard.view'],
            'is_active' => true,
        ])->assertSessionHasErrors(['role']);

        $this->put('/admin/access/'.$owner->id, [
            'name' => $owner->name,
            'email' => $owner->email,
            'password' => null,
            'role' => 'SUPER_ADMIN',
            'permissions' => [],
            'is_active' => false,
        ])->assertSessionHasErrors(['role']);

        $this->delete('/admin/access/'.$owner->id)
            ->assertSessionHasErrors(['admin']);

        $owner->refresh();
        $this->assertSame('SUPER_ADMIN', $owner->role);
        $this->assertTrue($owner->is_active);
    }

    public function test_last_active_super_admin_is_protected_even_when_managed_by_another_session(): void
    {
        $owner = $this->loginSuper();
        $other = $this->admin('SUPER_ADMIN', ['is_active' => false]);

        $this->actingAs($other, 'admin');
        $other->forceFill(['is_active' => true])->save();

        $owner->forceFill(['is_active' => false])->save();

        $this->put('/admin/access/'.$other->id, [
            'name' => $other->name,
            'email' => $other->email,
            'password' => null,
            'role' => 'ADMIN',
            'permissions' => ['dashboard.view'],
            'is_active' => true,
        ])->assertSessionHasErrors(['role']);

        $this->assertSame('SUPER_ADMIN', $other->fresh()->role);
        $this->assertTrue($other->fresh()->is_active);
    }

    public function test_super_admin_can_delete_other_admin_and_audit_survives(): void
    {
        $owner = $this->loginSuper();
        $target = $this->admin('ADMIN', [
            'name' => 'Delete Operator',
            'email' => 'delete-operator@example.test',
        ]);
        $id = $target->id;

        $this->delete('/admin/access/'.$id)
            ->assertRedirect('/admin/access')
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('admin_users', ['id' => $id]);
        $this->assertDatabaseHas('audit_logs', [
            'actor_type' => 'admin_user',
            'actor_id' => (string) $owner->id,
            'action' => 'admin.deleted',
            'target_type' => 'admin_user',
            'target_id' => (string) $id,
        ]);
    }

    public function test_non_super_admin_cannot_open_or_mutate_admin_access(): void
    {
        $admin = $this->admin('ADMIN', [
            'permissions' => ['dashboard.view', 'settings.manage'],
        ]);
        $this->actingAs($admin, 'admin');

        $this->get('/admin/access')->assertForbidden();
        $this->post('/admin/access', [
            'name' => 'No Access',
            'email' => 'no-access@example.test',
            'password' => 'temporary-password-123',
            'role' => 'ADMIN',
            'permissions' => ['dashboard.view'],
            'is_active' => true,
        ])->assertForbidden();
        $this->delete('/admin/access/'.$admin->id)->assertForbidden();
    }
}
