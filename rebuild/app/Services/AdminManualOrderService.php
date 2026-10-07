<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductPackage;
use App\Models\ProviderMapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdminManualOrderService
{
    public function create(array $data, int $adminId): int
    {
        return DB::transaction(function () use ($data, $adminId): int {
            // Serialize requests by the same operator before checking the persistent request key.
            DB::table('admin_users')->where('id', $adminId)->lockForUpdate()->firstOrFail();
            $key = 'admin-manual:'.$adminId.':'.$data['idempotency_key'];
            $fingerprint = hash('sha256', json_encode(collect($data)->except('idempotency_key')->sortKeys()->all(), JSON_THROW_ON_ERROR));
            $existing = DB::table('orders')->where('idempotency_key', $key)->first();
            if ($existing) {
                $snapshot = json_decode($existing->snapshot, true);
                if (! hash_equals($snapshot['manual_request_hash'] ?? '', $fingerprint)) {
                    throw ValidationException::withMessages(['order' => 'Permintaan ini sudah digunakan untuk pesanan berbeda. Buka formulir baru.']);
                }

                return (int) $existing->id;
            }
            // Private references satisfy catalog foreign keys without offering these ad-hoc orders in the shop.
            $category = Category::firstOrCreate(['slug' => 'admin-manual-internal'], ['name' => 'Catatan pesanan manual', 'is_active' => false]);
            $product = Product::firstOrCreate(['slug' => 'admin-manual-internal'], [
                'category_id' => $category->id, 'name' => 'Catatan pesanan manual',
                'fulfillment_mode' => 'MANUAL', 'is_active' => false,
            ]);
            $package = ProductPackage::firstOrCreate(['product_id' => $product->id, 'code' => 'ADMIN_MANUAL'], ['name' => 'Pesanan khusus', 'is_active' => false]);
            $providerId = DB::table('providers')->where('code', 'MANUAL')->value('id');
            if (! $providerId) {
                throw ValidationException::withMessages(['order' => 'Penanganan manual belum tersedia.']);
            }
            $mapping = ProviderMapping::firstOrCreate(['product_package_id' => $package->id, 'provider_id' => $providerId], ['is_active' => false]);
            $snapshot = [
                'product' => ['id' => $product->id, 'name' => $data['product_name'], 'fulfillment_mode' => 'MANUAL'],
                'package' => ['id' => $package->id, 'name' => $data['package_name']],
                'provider' => ['mapping_id' => $mapping->id, 'code' => 'MANUAL'],
                'payment' => ['channel_code' => 'ADMIN_MANUAL', 'channel_name' => 'Pembayaran dicatat admin'],
                'buyer_name' => $data['customer_name'], 'manual_request_hash' => $fingerprint,
                'manual_admin_id' => $adminId, 'manual_note' => $data['note'] ?? null,
            ];
            $id = DB::table('orders')->insertGetId([
                'order_number' => 'LF-M-'.strtoupper((string) Str::ulid()),
                'guest_email' => $data['email'] ?: null, 'guest_phone' => $data['phone'],
                'guest_phone_normalized' => preg_replace('/\\D+/', '', (string) $data['phone']) ?: null,
                'product_id' => $product->id, 'product_package_id' => $package->id,
                'provider_mapping_id' => $mapping->id, 'status' => 'PAID', 'currency' => 'IDR',
                'customer_input' => json_encode(['destination' => $data['destination']], JSON_THROW_ON_ERROR),
                'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'cost_idr' => $data['total_idr'],
                'margin_idr' => 0, 'total_idr' => $data['total_idr'], 'idempotency_key' => $key,
                'paid_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('order_events')->insert([
                'order_id' => $id, 'event_type' => 'ADMIN_MANUAL_ORDER_CREATED',
                'from_status' => null, 'to_status' => 'PAID',
                'correlation_id' => (string) Str::uuid(), 'metadata' => json_encode(['admin_id' => $adminId, 'note' => $data['note'] ?? null]),
                'created_at' => now(),
            ]);
            app(FulfillmentService::class)->startOrder($id);

            return $id;
        }, 3);
    }
}
