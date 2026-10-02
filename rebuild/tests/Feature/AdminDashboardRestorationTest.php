<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Category;
use App\Models\IntegrationCredential;
use App\Models\Product;
use App\Models\ProductPackage;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminDashboardRestorationTest extends TestCase
{
    use DatabaseTransactions;

    private function login(string $role = 'SUPER_ADMIN', array $permissions = []): void
    {
        $this->actingAs(AdminUser::create([
            'name' => 'Dashboard regression', 'email' => bin2hex(random_bytes(8)).'@example.test',
            'password' => bcrypt('dashboard-regression-only'), 'role' => $role,
            'permissions' => $permissions, 'is_active' => true,
        ]), 'admin');
    }

    private function order(string $createdAt, string $status, int $total = 10000): int
    {
        $product = Product::firstOrCreate(['slug' => 'dashboard-regression'], [
            'name' => 'Dashboard Game', 'category_id' => Category::where('slug', 'game')->value('id'),
            'fulfillment_mode' => 'MANUAL', 'is_active' => true, 'margin_percent' => 10,
        ]);
        $package = ProductPackage::firstOrCreate(['product_id' => $product->id, 'code' => 'D10'], ['name' => '10 Diamonds', 'is_active' => true]);
        $id = DB::table('orders')->insertGetId([
            'order_number' => 'DASH-'.bin2hex(random_bytes(6)), 'product_id' => $product->id,
            'product_package_id' => $package->id, 'status' => $status, 'currency' => 'IDR',
            'customer_input' => '{}', 'snapshot' => json_encode(['product_name' => 'Snapshot Game', 'package_name' => 'Snapshot Diamonds', 'buyer_name' => 'Dashboard Guest']),
            'cost_idr' => $total - 1000, 'margin_idr' => 1000, 'total_idr' => $total,
            'paid_at' => in_array($status, ['SUCCESS', 'PAID', 'PROCESSING']) ? $createdAt : null,
            'idempotency_key' => bin2hex(random_bytes(16)), 'created_at' => $createdAt, 'updated_at' => $createdAt,
        ]);

        return $id;
    }

    public function test_wib_day_boundaries_and_zero_filled_chart_use_paid_orders_only(): void
    {
        $this->login();
        $this->travelTo(Carbon::parse('2026-10-02 01:00:00', 'UTC'));
        $this->order('2026-10-01 16:59:59', 'SUCCESS', 11000);
        $today = $this->order('2026-10-01 17:00:00', 'SUCCESS', 12000);
        $this->order('2026-10-02 10:00:00', 'PENDING_PAYMENT', 13000);
        $this->order('2026-10-02 17:00:00', 'SUCCESS', 14000);
        $this->get('/admin/panel?range=today')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Dashboard')->where('metrics.orders_today', 2)
            ->where('metrics.revenue_today', 12000)->where('metrics.success_period', 1)
            ->has('chart', 1)->where('chart.0.day', '2026-10-02')
            ->where('chart.0.orders', 2)->where('chart.0.revenue_idr', 12000)
            ->has('recentOrders', 2)->where('recentOrders.1.id', $today)
            ->where('recentOrders.1.product_name', 'Snapshot Game')
            ->where('recentOrders.1.package_name', 'Snapshot Diamonds')
            ->where('recentOrders.1.buyer_name', 'Dashboard Guest')
            ->where('topProducts.0.fulfilled_orders', fn ($value) => (int) $value === 1));
        $this->get('/admin/panel?range=7d')->assertInertia(fn (Assert $page) => $page
            ->has('chart', 7)->where('chart.0.orders', 0)->where('chart.0.revenue_idr', 0));
        $this->travelBack();
    }

    public function test_dashboard_does_not_leak_financial_data_or_restricted_sections_to_admin(): void
    {
        $this->login('ADMIN', ['dashboard.view']);
        IntegrationCredential::updateOrCreate(['code' => 'digiflazz'], ['is_active' => true, 'config_ciphertext' => ['username' => 'private-user', 'api_key' => 'PRIVATE-KEY']]);
        Http::swap(new Factory);
        Http::fake();
        $this->get('/admin/panel')->assertInertia(fn (Assert $page) => $page
            ->where('canViewFinance', false)->where('metrics', [])
            ->where('chart', [])->where('recentOrders', [])->where('integrations', [])
            ->where('activities', [])->where('notifications', [])->where('digiflazzBalance', null));
        Http::assertNothingSent();
        $this->login('ADMIN', ['dashboard.view', 'orders.view', 'catalog.manage']);
        $this->order(now()->format('Y-m-d H:i:s'), 'SUCCESS');
        $this->get('/admin/panel')->assertInertia(fn (Assert $page) => $page
            ->missing('metrics.revenue_today')->missing('metrics.wallet_balance')->missing('metrics.digiflazz_balance')
            ->has('recentOrders', 1)->missing('recentOrders.0.total_idr')->has('topProducts', 1));
    }

    public function test_read_only_balance_probe_is_cached_and_failure_keeps_dashboard_available(): void
    {
        $this->login();
        Cache::flush();
        IntegrationCredential::updateOrCreate(['code' => 'digiflazz'], ['is_active' => true, 'config_ciphertext' => ['username' => 'fixture', 'api_key' => 'fixture', 'base_url' => 'https://dashboard-provider.test']]);
        Http::swap(new Factory);
        Http::fake(['https://dashboard-provider.test/v1/cek-saldo' => Http::response(['data' => ['deposit' => 345000]])]);
        $this->get('/admin/panel')->assertInertia(fn (Assert $page) => $page->where('metrics.digiflazz_balance', 345000));
        $this->get('/admin/panel')->assertOk();
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === 'https://dashboard-provider.test/v1/cek-saldo' && $request['cmd'] === 'deposit');
        Cache::flush();
        Http::swap(new Factory);
        Http::fake(['*' => Http::response(['error' => 'provider unavailable'], 503)]);
        $this->get('/admin/panel')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('metrics.digiflazz_balance', null)->where('digiflazzBalance.status', 'DOWN'));
    }

    public function test_integration_status_never_claims_configured_or_stale_connections_are_online(): void
    {
        $this->login();
        IntegrationCredential::updateOrCreate(['code' => 'doku'], ['is_active' => true, 'config_ciphertext' => ['secret_key' => 'never-display']]);
        DB::table('system_settings')->updateOrInsert(['key' => 'integration.health.doku'], [
            'value' => json_encode(['status' => 'HEALTHY', 'tested_at' => now()->subHour()->toIso8601String(), 'message' => 'never-display']), 'updated_at' => now(),
        ]);
        $response = $this->get('/admin/panel')->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->where('integrations', fn ($rows) => collect($rows)->firstWhere('code', 'doku')['status'] === 'STALE'));
        $this->assertStringNotContainsString('never-display', $response->getContent());
        $this->get('/admin/panel?range=invalid')->assertSessionHasErrors('range');
    }

    public function test_recent_notification_read_status_belongs_to_current_admin(): void
    {
        $this->login();
        $id = DB::table('admin_notifications')->insertGetId(['event_type' => 'dashboard.test', 'severity' => 'INFO', 'title' => 'Dashboard test notice', 'message' => 'Review this order', 'created_at' => now()]);
        $this->get('/admin/panel')->assertInertia(fn (Assert $page) => $page->where('notifications.0.id', $id)->where('notifications.0.read_at', null));
        $this->post('/admin/notifications/'.$id.'/read')->assertRedirect();
        $this->get('/admin/panel')->assertInertia(fn (Assert $page) => $page->where('notifications.0.read_at', fn ($value) => $value !== null));
        $this->login();
        $this->get('/admin/panel')->assertInertia(fn (Assert $page) => $page->where('notifications.0.read_at', null));
    }
}
