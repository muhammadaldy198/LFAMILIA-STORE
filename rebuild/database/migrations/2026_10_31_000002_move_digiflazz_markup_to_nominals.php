<?php

use App\Models\ProductPackage;
use App\Services\CheckoutPricing;
use App\Services\DigiflazzPriceOwnership;
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
        Schema::table('product_packages', function (Blueprint $table): void {
            $table->unsignedBigInteger('legacy_voucher_sell_price_idr')->nullable();
        });

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
                $models = ProductPackage::with('product')->whereIn('id', $ids)->get()->keyBy('id');
                $quotes = app(CheckoutPricing::class)->forPackages($ids, true);
                $ownership = app(DigiflazzPriceOwnership::class);
                $voucherPackageIds = DB::table('provider_mappings as mappings')
                    ->join('providers', 'providers.id', '=', 'mappings.provider_id')
                    ->whereIn('mappings.product_package_id', $ids)
                    ->where('providers.code', 'VOUCHER_STOCK')
                    ->pluck('mappings.product_package_id')->flip()->all();

                foreach ($packages as $package) {
                    $model = $models->get($package->id);
                    if ($model === null || ! $ownership->usesDigiflazz($model, $quotes[$package->id] ?? null)) {
                        // Preserve local voucher prices even if a historical
                        // Digiflazz mapping still belongs to this nominal.
                        continue;
                    }
                    $fixed = $package->price_markup_type === 'FIXED';
                    DB::table('product_packages')->where('id', $package->id)->update([
                        // Only a legacy SELL_PRICE voucher needs its own cap.
                        'legacy_voucher_sell_price_idr' => $package->pricing_mode === 'SELL_PRICE'
                            && isset($voucherPackageIds[$package->id]) ? $package->sell_price_idr : null,
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
