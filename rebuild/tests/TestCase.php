<?php

namespace Tests;

use App\Models\AdminUser;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    /** Explicitly seed fresh supplier rows for checkout feature fixtures. */
    protected function seedCheckoutDigiflazzCatalog(int $packageId): void
    {
        $mappings = DB::table('provider_mappings as mappings')
            ->join('providers', 'providers.id', '=', 'mappings.provider_id')
            ->where('mappings.product_package_id', $packageId)
            ->where('providers.code', 'DIGIFLAZZ')
            ->whereNotNull('mappings.external_sku')
            ->get(['mappings.external_sku', 'mappings.cost_idr']);

        foreach ($mappings as $mapping) {
            DB::table('digiflazz_catalog_items')->updateOrInsert(
                ['buyer_sku_code' => $mapping->external_sku],
                [
                    'product_name' => 'Fixture '.$mapping->external_sku,
                    'category' => 'Games', 'brand' => 'Fixture', 'type' => 'Umum',
                    'seller_name' => 'Fixture Seller',
                    'price_idr' => (int) $mapping->cost_idr,
                    'baseline_price_idr' => (int) $mapping->cost_idr,
                    'buyer_active' => true, 'seller_active' => true,
                    'unlimited_stock' => true, 'stock' => 0,
                    'is_present' => true, 'start_cut_off' => '00:00', 'end_cut_off' => '00:00',
                    'synced_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]
            );
        }
    }

    protected function createAdmin(array $attributes): AdminUser
    {
        $trusted = array_intersect_key($attributes, array_flip(['role', 'permissions', 'is_active']));
        $admin = AdminUser::create(array_diff_key($attributes, $trusted));

        if ($trusted !== []) {
            $admin->forceFill($trusted)->save();
        }

        return $admin;
    }
}
