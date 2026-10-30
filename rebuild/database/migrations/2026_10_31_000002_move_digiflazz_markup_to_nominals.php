<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A previously fixed-price voucher may coexist with an active Digiflazz
        // mapping. Preserve its original cap and selling price independently
        // when the nominal itself moves to percentage/IDR Digiflazz markup.
        if (! Schema::hasColumn('product_packages', 'legacy_voucher_sell_price_idr')) {
            Schema::table('product_packages', function (Blueprint $table): void {
                $table->unsignedBigInteger('legacy_voucher_sell_price_idr')->nullable();
            });
        }

        // A mixed voucher + Digiflazz nominal needs two independent rules:
        // preserve voucher pricing untouched and backfill its Digiflazz markup.
        if (! Schema::hasColumn('product_packages', 'digiflazz_markup_mode')) {
            Schema::table('product_packages', function (Blueprint $table): void {
                $table->string('digiflazz_markup_mode', 10)->nullable();
                $table->decimal('digiflazz_markup_percent', 8, 4)->nullable();
                $table->unsignedBigInteger('digiflazz_markup_fixed_idr')->nullable();
            });
        }

        // Previously Digiflazz checkout always used product markup, even when
        // a nominal had different legacy pricing fields. Transfer the actual
        // effective price rule to each nominal before switching checkout logic.
        $providerId = DB::table('providers')->where('code', 'DIGIFLAZZ')->value('id');
        if ($providerId === null) {
            return;
        }

        DB::table('product_packages as packages')
            ->join('products', 'products.id', '=', 'packages.product_id')
            ->where('products.fulfillment_mode', 'AUTO_PROVIDER')
            ->whereExists(function ($query) use ($providerId): void {
                $query->selectRaw('1')
                    ->from('provider_mappings as mappings')
                    ->whereColumn('mappings.product_package_id', 'packages.id')
                    ->where('mappings.provider_id', $providerId);
            })
            ->select(
                'packages.id', 'packages.pricing_mode', 'packages.sell_price_idr',
                'products.margin_percent',
                'products.price_markup_type',
                'products.price_markup_fixed_idr'
            )
            ->orderBy('packages.id')
            ->chunkById(200, function ($packages): void {
                $ids = $packages->pluck('id')->map(fn ($id): int => (int) $id)->all();
                $voucherPackageIds = DB::table('provider_mappings as mappings')
                    ->join('providers', 'providers.id', '=', 'mappings.provider_id')
                    ->whereIn('mappings.product_package_id', $ids)
                    ->where('providers.code', 'VOUCHER_STOCK')
                    ->pluck('mappings.product_package_id')->flip()->all();

                foreach ($packages as $package) {
                    $fixed = $package->price_markup_type === 'FIXED';
                    // Never overwrite a voucher's existing percentage, fixed
                    // increment or fixed selling price. Store the Digiflazz
                    // rule separately on that same nominal, even if voucher
                    // stock is currently available or Digiflazz is inactive.
                    if (isset($voucherPackageIds[$package->id])) {
                        DB::table('product_packages')->where('id', $package->id)->update([
                            'digiflazz_markup_mode' => $fixed ? 'FIXED' : 'PERCENT',
                            'digiflazz_markup_percent' => $fixed ? null : ($package->margin_percent ?? 12),
                            'digiflazz_markup_fixed_idr' => $fixed ? (int) $package->price_markup_fixed_idr : null,
                        ]);

                        continue;
                    }

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
