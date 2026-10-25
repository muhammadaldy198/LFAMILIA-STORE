<?php

namespace Tests\Unit;

use App\Models\Category;
use App\Models\Product;
use App\Services\FulfillmentService;
use App\Support\ServiceHours;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AuditDecisionBackendTest extends TestCase
{
    use DatabaseTransactions;

    public function test_manual_service_hours_count_only_inside_the_product_window(): void
    {
        $this->assertSame(90, ServiceHours::elapsedMinutes('2026-10-25 09:00:00+07:00', '2026-10-25 10:30:00+07:00', '08:00', '17:00', 'Asia/Jakarta'));
        $this->assertSame(0, ServiceHours::elapsedMinutes('2026-10-25 18:00:00+07:00', '2026-10-25 19:30:00+07:00', '08:00', '17:00', 'Asia/Jakarta'));
    }

    public function test_overnight_service_hours_include_previous_days_open_window(): void
    {
        $this->assertSame(90, ServiceHours::elapsedMinutes('2026-10-26 01:00:00+07:00', '2026-10-26 02:30:00+07:00', '22:00', '06:00', 'Asia/Jakarta'));
        $this->assertSame(30, ServiceHours::elapsedMinutes('2026-10-26 05:30:00+07:00', '2026-10-26 07:30:00+07:00', '22:00', '06:00', 'Asia/Jakarta'));
        $this->assertSame(480, ServiceHours::elapsedMinutes('2026-10-25 20:00:00+07:00', '2026-10-26 10:00:00+07:00', '22:00', '06:00', 'Asia/Jakarta'));
        $this->assertSame(0, ServiceHours::elapsedMinutes('2026-10-26 07:00:00+07:00', '2026-10-26 08:00:00+07:00', '22:00', '06:00', 'Asia/Jakarta'));
    }

    public function test_guest_data_purge_cleans_old_guests_but_keeps_recent_and_member_orders(): void
    {
        $category = Category::where('slug', 'game')->firstOrFail();
        $product = Product::create([
            'category_id' => $category->id, 'name' => 'Purge fixture '.bin2hex(random_bytes(3)),
            'slug' => 'purge-fixture-'.bin2hex(random_bytes(4)), 'margin_percent' => 10,
            'fulfillment_mode' => 'MANUAL', 'is_active' => true,
        ]);
        $packageId = $product->packages()->create([
            'code' => 'PURGE'.bin2hex(random_bytes(3)), 'name' => 'Fixture', 'nominal_value' => 1,
            'is_active' => true,
        ])->id;
        $userId = DB::table('users')->insertGetId([
            'name' => 'Audit member', 'email' => 'audit-member@example.test',
            'password' => bcrypt('not-a-real-password'), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $oldGuest = $this->insertOrder($product->id, $packageId, null, now()->subDays(31));
        $newGuest = $this->insertOrder($product->id, $packageId, null, now()->subDays(29));
        $member = $this->insertOrder($product->id, $packageId, $userId, now()->subDays(31));

        Artisan::call('lfamilia:purge-guest-data');

        $this->assertDatabaseHas('orders', ['id' => $oldGuest, 'guest_email' => null, 'guest_phone' => null, 'guest_phone_normalized' => null, 'total_idr' => 1000]);
        $this->assertNotNull(DB::table('orders')->where('id', $newGuest)->value('guest_email'));
        $this->assertNotNull(DB::table('orders')->where('id', $member)->value('guest_email'));
    }

    public function test_manual_fulfillment_service_rejects_empty_code_and_note(): void
    {
        $this->expectException(ValidationException::class);
        app(FulfillmentService::class)->completeManual(0, '  ', null, 1);
    }

    private function insertOrder(int $productId, int $packageId, ?int $userId, Carbon $createdAt): int
    {
        return DB::table('orders')->insertGetId([
            'order_number' => 'AUD-'.bin2hex(random_bytes(6)), 'user_id' => $userId,
            'product_id' => $productId, 'product_package_id' => $packageId,
            'status' => 'FAILED', 'currency' => 'IDR', 'customer_input' => '{}', 'snapshot' => '{}',
            'cost_idr' => 500, 'margin_idr' => 500, 'total_idr' => 1000,
            'guest_email' => 'guest@example.test', 'guest_phone' => '+6281234567890',
            'guest_phone_normalized' => '6281234567890', 'idempotency_key' => 'audit-'.bin2hex(random_bytes(8)),
            'created_at' => $createdAt, 'updated_at' => $createdAt,
        ]);
    }
}
