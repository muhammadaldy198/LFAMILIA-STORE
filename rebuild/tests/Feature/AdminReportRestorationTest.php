<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminReportRestorationTest extends TestCase
{
    use DatabaseTransactions;

    private function login(string $role = 'SUPER_ADMIN', array $permissions = ['reports.view']): AdminUser
    {
        $admin = AdminUser::create([
            'name' => 'Report Admin',
            'email' => bin2hex(random_bytes(8)).'@example.test',
            'password' => Hash::make('report-restoration-password'),
            'role' => $role,
            'permissions' => $role === 'SUPER_ADMIN' ? null : $permissions,
            'is_active' => true,
        ]);
        $this->actingAs($admin, 'admin');

        return $admin;
    }

    /**
     * @return array{product_id:int,package_id:int,mapping_id:int,provider_id:int}
     */
    private function catalog(string $suffix): array
    {
        $categoryId = (int) DB::table('categories')->where('slug', 'game')->value('id');
        $productId = DB::table('products')->insertGetId([
            'category_id' => $categoryId,
            'name' => 'Report Product '.$suffix,
            'slug' => 'report-product-'.strtolower($suffix).'-'.bin2hex(random_bytes(3)),
            'margin_percent' => 10,
            'sort_order' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $packageId = DB::table('product_packages')->insertGetId([
            'product_id' => $productId,
            'code' => 'REPORT-'.$suffix,
            'name' => '100 Unit '.$suffix,
            'nominal_value' => 100,
            'sort_order' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $providerId = (int) DB::table('providers')->where('code', 'DIGIFLAZZ')->value('id');
        DB::table('providers')->where('id', $providerId)->update([
            'display_name' => 'Digiflazz Test',
            'is_active' => true,
        ]);
        $mappingId = DB::table('provider_mappings')->insertGetId([
            'product_package_id' => $packageId,
            'provider_id' => $providerId,
            'external_sku' => 'REPORT-SKU-'.$suffix,
            'cost_idr' => 10000,
            'max_price_idr' => 12000,
            'priority' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'product_id' => $productId,
            'package_id' => $packageId,
            'mapping_id' => $mappingId,
            'provider_id' => $providerId,
        ];
    }

    private function order(array $catalog, string $suffix, string $status, $createdAt, bool $paid = false): int
    {
        return DB::table('orders')->insertGetId([
            'order_number' => 'REPORT-'.$suffix.'-'.bin2hex(random_bytes(3)),
            'user_id' => null,
            'guest_email' => strtolower($suffix).'@example.test',
            'guest_phone' => '081234567890',
            'product_id' => $catalog['product_id'],
            'product_package_id' => $catalog['package_id'],
            'provider_mapping_id' => $catalog['mapping_id'],
            'voucher_id' => null,
            'status' => $status,
            'currency' => 'IDR',
            'customer_input' => json_encode(['user_id' => '123456'], JSON_THROW_ON_ERROR),
            'snapshot' => '{}',
            'cost_idr' => 10000,
            'margin_idr' => 3000,
            'discount_idr' => 500,
            'fee_idr' => 500,
            'total_idr' => 13000,
            'idempotency_key' => 'report-'.strtolower($suffix).'-'.bin2hex(random_bytes(8)),
            'expires_at' => null,
            'paid_at' => $paid ? $createdAt : null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    private function attempt(array $catalog, int $orderId, string $status, $createdAt): void
    {
        DB::table('fulfillment_attempts')->insert([
            'order_id' => $orderId,
            'provider_mapping_id' => $catalog['mapping_id'],
            'provider_id' => $catalog['provider_id'],
            'attempt_no' => 1,
            'external_reference' => 'FUL-REPORT-'.bin2hex(random_bytes(6)),
            'status' => $status,
            'correlation_id' => 'report-'.bin2hex(random_bytes(8)),
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    public function test_super_admin_reports_restore_finance_product_category_provider_and_status_data(): void
    {
        $this->login();
        $catalog = $this->catalog('MAIN');
        $success = $this->order($catalog, 'SUCCESS', 'SUCCESS', now()->subDay(), true);
        $failed = $this->order($catalog, 'FAILED', 'FAILED', now()->subDay(), false);
        $this->attempt($catalog, $success, 'SUCCESS', now()->subDay());
        $this->attempt($catalog, $failed, 'FAILED_CONFIRMED', now()->subDay());

        DB::table('payment_transactions')->insert([
            'order_id' => $success,
            'wallet_topup_id' => null,
            'gateway_code' => 'MANUAL_QRIS',
            'channel_code' => 'manual_qris',
            'external_reference' => null,
            'amount_idr' => 13000,
            'status' => 'PAID',
            'idempotency_key' => 'report-payment-'.bin2hex(random_bytes(8)),
            'verified_at' => now()->subDay(),
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        $this->get('/admin/reports?range=30d')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Reports')
                ->where('canViewFinance', true)
                ->where('metrics.orders_total', 2)
                ->where('metrics.success_total', 1)
                ->where('metrics.failed_total', 1)
                ->where('metrics.paid_revenue_idr', 13000)
                ->where('metrics.gross_profit_idr', 2500)
                ->where('metrics.discount_idr', 500)
                ->where('metrics.payment_fee_idr', 500)
                ->where('metrics.provider_errors', 1)
                ->has('daily', 1)
                ->where('daily.0.orders_count', 2)
                ->where('daily.0.revenue_idr', 13000)
                ->has('topProducts', 1)
                ->where('topProducts.0.success_orders', 1)
                ->has('topCategories', 1)
                ->has('providerReport', 1)
                ->where('providerReport.0.name', 'Digiflazz Test')
                ->where('providerReport.0.attempts_count', 2)
                ->where('providerReport.0.success_count', 1)
                ->where('providerReport.0.errors_count', 1)
                ->where('providerReport.0.error_rate', 50.0)
                ->has('orderStatuses')
                ->has('paymentStatuses')
                ->has('fulfillmentStatuses'));
    }

    public function test_regular_admin_can_view_operational_report_but_finance_is_not_sent(): void
    {
        $this->login('ADMIN', ['reports.view']);
        $catalog = $this->catalog('ADMIN');
        $this->order($catalog, 'ADMIN', 'SUCCESS', now()->subDay(), true);

        $this->get('/admin/reports?range=30d')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Reports')
                ->where('canViewFinance', false)
                ->where('metrics.orders_total', 1)
                ->where('metrics.success_total', 1)
                ->missing('metrics.paid_revenue_idr')
                ->missing('metrics.gross_profit_idr')
                ->missing('metrics.wallet_liability_idr')
                ->missing('daily.0.revenue_idr')
                ->missing('topProducts.0.revenue_idr')
                ->missing('topProducts.0.profit_idr')
                ->missing('topCategories.0.revenue_idr'));
    }

    public function test_report_range_excludes_orders_outside_selected_period(): void
    {
        $this->login();
        $catalog = $this->catalog('RANGE');
        $this->order($catalog, 'RECENT', 'SUCCESS', now()->subDays(2), true);
        $this->order($catalog, 'OLD', 'SUCCESS', now()->subDays(120), true);

        $this->get('/admin/reports?range=7d')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Reports')
                ->where('metrics.orders_total', 1)
                ->where('metrics.paid_revenue_idr', 13000));

        $this->get('/admin/reports?range=all')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Reports')
                ->where('metrics.orders_total', 2)
                ->where('metrics.paid_revenue_idr', 26000));
    }

    public function test_report_csv_export_respects_finance_visibility_and_is_audited(): void
    {
        $admin = $this->login();
        $catalog = $this->catalog('EXPORT');
        $this->order($catalog, 'EXPORT', 'SUCCESS', now()->subDay(), true);

        $response = $this->get('/admin/reports/export?range=30d');
        $response->assertOk()->assertDownload();

        $content = $response->streamedContent();
        $this->assertStringContainsString('Omzet', $content);
        $this->assertStringContainsString('Laba Kotor', $content);
        $this->assertStringContainsString('13000', $content);
        $this->assertDatabaseHas('audit_logs', [
            'actor_type' => 'admin_user',
            'actor_id' => (string) $admin->id,
            'action' => 'report.exported',
            'target_type' => 'report',
            'target_id' => 'sales',
        ]);

        auth('admin')->logout();
        $this->login('ADMIN', ['reports.view']);

        $adminResponse = $this->get('/admin/reports/export?range=30d');
        $adminResponse->assertOk()->assertDownload();
        $adminContent = $adminResponse->streamedContent();
        $this->assertStringNotContainsString('Omzet', $adminContent);
        $this->assertStringNotContainsString('Laba Kotor', $adminContent);
    }

    public function test_reports_permission_is_enforced_server_side(): void
    {
        $this->login('ADMIN', ['dashboard.view']);

        $this->get('/admin/reports')->assertForbidden();
        $this->get('/admin/reports/export?range=30d')->assertForbidden();
    }
}
