<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\MembershipTier;
use App\Models\User;
use App\Services\AdminManualOrderService;
use App\Services\MembershipService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminSecurityPr3Test extends TestCase
{
    use DatabaseTransactions;

    private function admin(string $role = 'SUPER_ADMIN', array $permissions = []): AdminUser
    {
        return $this->createAdmin([
            'name' => 'Security PR3',
            'email' => 'pr3-admin-'.bin2hex(random_bytes(5)).'@example.test',
            'password' => Hash::make('PR3-secure-test-password'),
            'role' => $role,
            'permissions' => $role === 'SUPER_ADMIN' ? null : $permissions,
            'is_active' => true,
        ]);
    }

    private function customer(): User
    {
        return User::create([
            'name' => 'Security membership customer',
            'email' => 'pr3-member-'.bin2hex(random_bytes(5)).'@example.test',
            'password' => Hash::make('PR3-member-test-password'),
            'membership_tier_code' => 'BASIC',
            'membership_mode' => 'AUTO',
        ]);
    }

    public function test_successive_super_admin_mutations_never_leave_zero_active(): void
    {
        $owner = $this->admin();
        $first = $this->admin();
        $second = $this->admin();
        $lockQueries = [];
        DB::listen(function ($query) use (&$lockQueries): void {
            if (str_contains(strtolower($query->sql), 'for update')) {
                $lockQueries[] = $query->sql;
            }
        });

        $this->actingAs($owner, 'admin')->withSession(['admin.password_confirmed_at' => time()])
            ->put('/admin/access/'.$first->id, [
                'name' => $first->name, 'email' => $first->email, 'role' => 'ADMIN',
                'permissions' => ['dashboard.view'], 'is_active' => true,
            ])->assertSessionHasNoErrors();

        $this->delete('/admin/access/'.$second->id)->assertSessionHasNoErrors();
        $this->put('/admin/access/'.$owner->id, [
            'name' => $owner->name, 'email' => $owner->email, 'role' => 'ADMIN',
            'permissions' => ['dashboard.view'], 'is_active' => true,
        ])->assertSessionHasErrors('role');

        $this->assertSame(1, AdminUser::where('role', 'SUPER_ADMIN')->where('is_active', true)->count());
        if (DB::getDriverName() === 'mysql') {
            $this->assertNotEmpty($lockQueries, 'Akses admin harus memakai SELECT ... FOR UPDATE.');
        }
    }

    public function test_bootstrap_rejects_active_super_admin_but_recovers_inactive_or_empty_state(): void
    {
        AdminUser::query()->delete();
        $existing = $this->admin();
        $this->artisan('lfamilia:bootstrap-super-admin')
            ->expectsOutput('Super Admin sudah ada. Kelola akses berikutnya dari panel.')
            ->assertExitCode(1);

        $existing->forceFill(['is_active' => false])->save();
        $this->artisan('lfamilia:bootstrap-super-admin')
            ->expectsQuestion('Nama Super Admin', 'Recovered PR3')
            ->expectsQuestion('Email Super Admin', 'pr3-recovered@example.test')
            ->expectsQuestion('Kata sandi (minimal 12 karakter)', 'Recovered-password-123!')
            ->expectsQuestion('Ulangi kata sandi', 'Recovered-password-123!')
            ->expectsOutput('Super Admin dibuat.')
            ->assertExitCode(0);
        $this->assertDatabaseHas('admin_users', [
            'email' => 'pr3-recovered@example.test', 'role' => 'SUPER_ADMIN', 'is_active' => true,
        ]);

        AdminUser::query()->delete();
        $this->artisan('lfamilia:bootstrap-super-admin')
            ->expectsQuestion('Nama Super Admin', 'First PR3')
            ->expectsQuestion('Email Super Admin', 'pr3-first@example.test')
            ->expectsQuestion('Kata sandi (minimal 12 karakter)', 'Recovered-password-123!')
            ->expectsQuestion('Ulangi kata sandi', 'Recovered-password-123!')
            ->expectsOutput('Super Admin dibuat.')
            ->assertExitCode(0);
    }

    public function test_manual_order_requires_fresh_admin_password_and_preserves_permissions(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'admin');
        $payload = [
            'customer_name' => 'Manual PR3',
            'phone' => '081234567890',
            'email' => 'pr3-manual@example.test',
            'product_name' => 'Produk PR3',
            'package_name' => 'Paket PR3',
            'destination' => 'USER-PR3',
            'total_idr' => 17500,
            'payment_received' => true,
            'idempotency_key' => (string) Str::uuid(),
        ];

        $this->post('/admin/orders/manual', $payload)->assertRedirect(route('admin.password.confirm'));
        $this->assertDatabaseMissing('orders', [
            'idempotency_key' => 'admin-manual:'.$admin->id.':'.$payload['idempotency_key'],
        ]);

        $this->withSession(['admin.password_confirmed_at' => time()])
            ->post('/admin/orders/manual', $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('orders', [
            'idempotency_key' => 'admin-manual:'.$admin->id.':'.$payload['idempotency_key'],
        ]);

        $staff = $this->admin('ADMIN', ['orders.view']);
        $this->actingAs($staff, 'admin')->withSession(['admin.password_confirmed_at' => time()])
            ->post('/admin/orders/manual', [...$payload, 'idempotency_key' => (string) Str::uuid()])
            ->assertForbidden();
    }

    public function test_auto_membership_never_selects_internal_mafia_even_when_minimum_is_zero(): void
    {
        $user = $this->customer();
        $mafia = MembershipTier::where('code', 'MAFIA')->firstOrFail();
        $mafia->forceFill(['requirements' => ['minimum_spend_idr' => 0]])->save();
        $platinum = MembershipTier::where('code', 'PLATINUM')->firstOrFail();
        $platinum->forceFill(['requirements' => ['minimum_spend_idr' => 1000000], 'is_active' => true])->save();

        $service = app(MembershipService::class);
        $this->assertSame('BASIC', $service->sync($user)['code']);
        $this->assertSame('BASIC', $user->fresh()->membership_tier_code);

        $user->forceFill(['membership_progress_bonus_idr' => 2000000])->save();
        $profile = $service->sync($user);
        $this->assertSame('PLATINUM', $profile['code']);
        $this->assertSame('PLATINUM', $user->fresh()->membership_tier_code);
        $this->assertNull($profile['next_code']);
    }

    public function test_auto_floor_preserves_saved_tier_until_silver_minimum_is_reached(): void
    {
        $user = $this->customer();
        MembershipTier::where('code', 'BASIC')->firstOrFail()
            ->forceFill(['is_active' => false])->save();
        MembershipTier::where('code', 'SILVER')->firstOrFail()
            ->forceFill(['requirements' => ['minimum_spend_idr' => 100000], 'is_active' => true])->save();
        $service = app(MembershipService::class);

        $this->assertSame('BASIC', $service->sync($user)['code']);
        $this->assertSame('BASIC', $user->fresh()->membership_tier_code);
        $user->forceFill(['membership_progress_bonus_idr' => 100000])->save();
        $this->assertSame('SILVER', $service->sync($user)['code']);
    }

    public function test_internal_tier_rejects_both_minimum_inputs_and_allows_other_updates(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'admin');

        $this->put('/admin/settings/membership/MAFIA', [
            'is_active' => true, 'minimum_spend_idr' => 0,
            'discount_percent' => 0, 'benefit_notes' => 'Manual only',
        ])->assertSessionHasErrors('minimum_spend_idr');

        $this->put('/admin/settings/membership/MAFIA', [
            'is_active' => true, 'requirements' => '{"minimum_spend_idr":0}',
            'benefits' => '{"benefit_notes":"manual"}',
        ])->assertSessionHasErrors('requirements');

        $this->put('/admin/settings/membership/MAFIA', [
            'is_active' => true, 'discount_percent' => 0, 'benefit_notes' => 'Internal only',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $tier = MembershipTier::where('code', 'MAFIA')->firstOrFail();
        $this->assertSame('Internal only', $tier->benefits['benefit_notes']);

        $this->put('/admin/settings/membership/MAFIA', [
            'is_active' => false,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertFalse(MembershipTier::where('code', 'MAFIA')->firstOrFail()->is_active);

        $this->put('/admin/settings/membership/MAFIA', [
            'is_active' => true, 'requirements' => '{"notes":"manual only"}',
            'benefits' => '{"benefit_notes":"still internal"}',
        ])->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_manual_mafia_assignment_keeps_its_existing_discount(): void
    {
        $user = $this->customer();
        $service = app(MembershipService::class);
        $service->setMode($user, 'MAFIA');
        $this->assertSame('MAFIA', $service->sync($user)['code']);
        $this->assertSame('MAFIA', $service->discount($user, 100000)['tier_code']);
    }

    public function test_orders_view_without_customers_view_can_see_contact_on_order_detail_only(): void
    {
        $owner = $this->admin();
        $id = app(AdminManualOrderService::class)->create([
            'customer_name' => 'Kontak PR3', 'phone' => '081234567890',
            'email' => 'pr3-buyer@example.test', 'product_name' => 'Produk internal',
            'package_name' => 'Paket internal', 'destination' => 'ID-PR3',
            'total_idr' => 12000, 'payment_received' => true,
            'idempotency_key' => (string) Str::uuid(),
        ], $owner->id);

        $staff = $this->admin('ADMIN', ['orders.view']);
        $this->actingAs($staff, 'admin')
            ->get('/admin/orders/'.$id)->assertOk()->assertInertia(fn (Assert $page) => $page
                ->component('Admin/OrderDetail')
                ->where('canViewCustomer', false)
                ->where('order.buyer_phone', '081234567890')
                ->where('order.buyer_email', 'pr3-buyer@example.test'));

        $vue = file_get_contents(resource_path('js/Pages/Admin/OrderDetail.vue'));
        $this->assertStringContainsString("{{ order.buyer_phone || '—' }}", $vue);
        $this->assertStringContainsString("{{ order.buyer_email || '—' }}", $vue);

        $this->get('/admin/customers')->assertForbidden();
        $this->get('/admin/customers/'.$id)->assertForbidden();
    }
}
