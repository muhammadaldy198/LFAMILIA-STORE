<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Membersihkan kunci customer_no_template warisan dari konfigurasi
 * pemenuhan mapping Digiflazz milik sebuah produk. Template tujuan yang sah
 * sekarang dibangun dari field input produk; kunci warisan ini dibuang
 * sesudah produk atau fieldnya disimpan agar tidak menimpa template baru.
 */
class LegacyCustomerNoTemplateCleanup
{
    public function clearForProduct(int $productId): void
    {
        $mappings = DB::table('provider_mappings as mappings')
            ->join('providers', 'providers.id', '=', 'mappings.provider_id')
            ->join('product_packages', 'product_packages.id', '=', 'mappings.product_package_id')
            ->where('providers.code', 'DIGIFLAZZ')
            ->where('product_packages.product_id', $productId)
            ->get(['mappings.id', 'mappings.fulfillment_config']);

        foreach ($mappings as $mapping) {
            $config = is_string($mapping->fulfillment_config)
                ? (json_decode($mapping->fulfillment_config, true) ?: [])
                : (array) $mapping->fulfillment_config;
            if (! array_key_exists('customer_no_template', $config)) {
                continue;
            }

            unset($config['customer_no_template']);
            DB::table('provider_mappings')->where('id', $mapping->id)->update([
                'fulfillment_config' => json_encode($config, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
        }
    }
}
