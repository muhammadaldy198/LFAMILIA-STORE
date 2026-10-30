<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Previously Digiflazz checkout always used product markup, even when
        // a nominal had different legacy pricing fields. Transfer the actual
        // effective price rule to each nominal before switching checkout logic.
        $providerId = DB::table('providers')->where('code', 'DIGIFLAZZ')->value('id');
        if ($providerId === null) {
            return;
        }

        DB::table('product_packages as packages')
            ->join('products', 'products.id', '=', 'packages.product_id')
            ->whereExists(function ($query) use ($providerId): void {
                $query->selectRaw('1')
                    ->from('provider_mappings as mappings')
                    ->whereColumn('mappings.product_package_id', 'packages.id')
                    ->where('mappings.provider_id', $providerId);
            })
            ->select(
                'packages.id',
                'products.margin_percent',
                'products.price_markup_type',
                'products.price_markup_fixed_idr'
            )
            ->orderBy('packages.id')
            ->chunkById(200, function ($packages): void {
                foreach ($packages as $package) {
                    $fixed = $package->price_markup_type === 'FIXED';
                    DB::table('product_packages')->where('id', $package->id)->update([
                        'pricing_mode' => $fixed ? 'FIXED' : 'PERCENT',
                        'margin_percent' => $fixed ? null : ($package->margin_percent ?? 12),
                        'margin_fixed_idr' => $fixed ? (int) $package->price_markup_fixed_idr : null,
                        'sell_price_idr' => null,
                        'sell_markup_percent' => null,
                    ]);
                }
            }, 'packages.id', 'id');
    }

    public function down(): void
    {
        // Do not reverse-map independent nominal prices onto one product:
        // that would lose admin edits and silently rewrite selling prices.
    }
};
