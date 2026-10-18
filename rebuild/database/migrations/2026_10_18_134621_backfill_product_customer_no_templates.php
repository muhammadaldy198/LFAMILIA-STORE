<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $mappingRows = DB::table('provider_mappings as m')
            ->join('providers as pr', 'pr.id', '=', 'm.provider_id')
            ->join('product_packages as pp', 'pp.id', '=', 'm.product_package_id')
            ->where('pr.code', 'DIGIFLAZZ')
            ->where('m.is_active', true)
            ->get(['pp.product_id', 'm.fulfillment_config']);

        $templates = [];
        foreach ($mappingRows as $row) {
            $config = is_string($row->fulfillment_config)
                ? (json_decode($row->fulfillment_config, true) ?: [])
                : ((array) $row->fulfillment_config);
            $template = trim((string) ($config['customer_no_template'] ?? ''));
            $templates[$row->product_id][$template] = true;
        }

        foreach ($templates as $productId => $values) {
            if (count($values) === 1 && ! isset($values[''])) {
                DB::table('products')->where('id', $productId)->where(function ($query): void {
                    $query->whereNull('customer_no_template')->orWhere('customer_no_template', '');
                })->update([
                    'customer_no_template' => array_key_first($values),
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $products = DB::table('products')->whereNotNull('customer_no_template')
            ->where('customer_no_template', '<>', '')
            ->get(['id', 'customer_no_template']);

        foreach ($products as $product) {
            $mappingRows = DB::table('provider_mappings as m')
                ->join('providers as pr', 'pr.id', '=', 'm.provider_id')
                ->join('product_packages as pp', 'pp.id', '=', 'm.product_package_id')
                ->where('pr.code', 'DIGIFLAZZ')
                ->where('pp.product_id', $product->id)
                ->get(['m.id', 'm.fulfillment_config']);

            foreach ($mappingRows as $mapping) {
                $config = is_string($mapping->fulfillment_config)
                    ? (json_decode($mapping->fulfillment_config, true) ?: [])
                    : (array) $mapping->fulfillment_config;
                if (trim((string) ($config['customer_no_template'] ?? '')) !== '') {
                    continue;
                }

                $config['customer_no_template'] = $product->customer_no_template;
                DB::table('provider_mappings')->where('id', $mapping->id)->update([
                    'fulfillment_config' => json_encode($config, JSON_THROW_ON_ERROR),
                ]);
            }
        }
    }
};
