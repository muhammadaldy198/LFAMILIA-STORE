<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductPackage;
use App\Models\Provider;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdminPricingPreviewService
{
    private const PREVIEW_TTL_MINUTES = 10;

    public function previewGlobalMargin(Request $request, string $margin, bool $convert): array
    {
        $plan = $this->globalPlan($margin, $convert);
        $token = $this->storePreview($request, 'margin', ['margin' => $margin, 'convert' => $convert], $plan);

        return [
            'token' => $token,
            'summary' => $plan['summary'],
            'mappings' => $plan['mappings'],
            'conversions' => $plan['conversions'],
        ];
    }

    public function executeGlobalMargin(Request $request, string $token, AdminAuditService $audit): void
    {
        $preview = $this->consumePreview($request, 'margin', $token);
        $margin = $preview['input']['margin'];
        $convert = $preview['input']['convert'];

        DB::transaction(function () use ($request, $preview, $margin, $convert, $audit): void {
            // Lock every data source whose values appear in the preview fingerprint.
            // Supplier sync and catalog edits must not change those rows between
            // verification and committing the confirmed global margin.
            Product::where('fulfillment_mode', 'AUTO_PROVIDER')
                ->orderBy('id')->lockForUpdate()->get(['id']);
            ProductPackage::whereHas('product', fn ($query) => $query->where('fulfillment_mode', 'AUTO_PROVIDER'))
                ->orderBy('id')->lockForUpdate()->get(['id']);
            DB::table('provider_mappings as mappings')
                ->join('product_packages as packages', 'packages.id', '=', 'mappings.product_package_id')
                ->join('products', 'products.id', '=', 'packages.product_id')
                ->where('products.fulfillment_mode', 'AUTO_PROVIDER')
                ->orderBy('mappings.id')->lockForUpdate()->get(['mappings.id']);
            DB::table('system_settings')->where('key', 'catalog.default_margin_percent')
                ->lockForUpdate()->first();
            $plan = $this->globalPlan($margin, $convert);
            $this->assertUnchanged($preview['fingerprint'], $plan);
            $before = $plan['products'];
            Product::where('fulfillment_mode', 'AUTO_PROVIDER')->update(['margin_percent' => $margin]);
            if ($plan['conversions'] !== []) {
                ProductPackage::whereIn('id', $plan['conversions'])
                    ->update(['pricing_mode' => 'PRODUCT_MARGIN', 'margin_percent' => null]);
            }
            DB::table('system_settings')->updateOrInsert(['key' => 'catalog.default_margin_percent'], [
                'value' => json_encode($margin, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
                'created_at' => now(),
            ]);
            $audit->record($request, 'catalog.margin.global_updated', 'product', 'all', $before, [
                'margin_percent' => $margin,
                'include_imported_percent' => $convert,
                'converted_imported_nominals' => count($plan['conversions']),
                'affected_mappings' => $plan['summary']['affected_mappings'],
                'preview_confirmed' => true,
            ]);
        }, 3);
    }

    public function previewMaxPrices(Request $request, string $input): array
    {
        $prices = $this->parseMaxPrices($input);
        $plan = $this->maxPlan($prices);
        $token = $this->storePreview($request, 'max_price', ['prices' => $prices], $plan);

        return ['token' => $token, 'summary' => $plan['summary'], 'mappings' => $plan['mappings']];
    }

    public function executeMaxPrices(Request $request, string $token, AdminAuditService $audit): void
    {
        $preview = $this->consumePreview($request, 'max_price', $token);
        DB::transaction(function () use ($request, $preview, $audit): void {
            $prices = $preview['input']['prices'];
            $providerId = Provider::where('code', 'DIGIFLAZZ')->value('id');
            $dependencies = DB::table('provider_mappings as mappings')
                ->join('product_packages as packages', 'packages.id', '=', 'mappings.product_package_id')
                ->where('mappings.provider_id', $providerId)
                ->whereIn('mappings.external_sku', array_keys($prices))
                ->get(['packages.id as package_id', 'packages.product_id']);
            // Match the global-margin confirmation lock order: product, package,
            // then mapping. All selling-price dependencies remain stable until commit.
            Product::whereIn('id', $dependencies->pluck('product_id')->unique()->all())
                ->orderBy('id')->lockForUpdate()->get(['id']);
            ProductPackage::whereIn('id', $dependencies->pluck('package_id')->unique()->all())
                ->orderBy('id')->lockForUpdate()->get(['id']);
            DB::table('provider_mappings')->where('provider_id', $providerId)
                ->whereIn('external_sku', array_keys($prices))
                ->orderBy('id')->lockForUpdate()->get(['id']);
            $plan = $this->maxPlan($prices);
            $this->assertUnchanged($preview['fingerprint'], $plan);
            foreach ($plan['mappings'] as $row) {
                DB::table('provider_mappings')->where('id', $row['mapping_id'])->update([
                    'cost_idr' => $row['new_cost_idr'],
                    'max_price_idr' => $row['new_max_price_idr'],
                    'updated_at' => now(),
                ]);
                $audit->record($request, 'digiflazz.max_price.buyer_area_updated', 'provider_mapping',
                    $row['mapping_id'],
                    ['external_sku' => $row['sku'], 'cost_idr' => $row['old_cost_idr'], 'max_price_idr' => $row['old_max_price_idr']],
                    ['external_sku' => $row['sku'], 'cost_idr' => $row['new_cost_idr'], 'max_price_idr' => $row['new_max_price_idr']]
                );
            }
        }, 3);
    }

    private function globalPlan(string $margin, bool $convert): array
    {
        $products = Product::where('fulfillment_mode', 'AUTO_PROVIDER')
            ->orderBy('id')->pluck('margin_percent', 'id')->all();
        $digiflazzId = Provider::where('code', 'DIGIFLAZZ')->value('id');
        $conversions = $convert && $digiflazzId
            ? ProductPackage::query()->where('pricing_mode', 'PERCENT')
                ->where('code', 'like', 'DF_%')
                ->whereHas('product', fn ($query) => $query->where('fulfillment_mode', 'AUTO_PROVIDER'))
                ->whereHas('mappings', fn ($query) => $query->where('provider_id', $digiflazzId))
                ->orderBy('id')->pluck('id')->all()
            : [];
        $rows = $this->mappingRows()->where('products.fulfillment_mode', 'AUTO_PROVIDER')
            ->orderBy('mappings.id')->get();
        $preview = [];
        $delta = 0;
        $affected = 0;
        foreach ($rows as $row) {
            $old = $this->sellPrice($row, (int) $row->cost_idr, (string) $row->product_margin);
            $converted = in_array((int) $row->package_id, $conversions, true);
            $new = $this->sellPrice($row, (int) $row->cost_idr, $margin, $converted);
            if ($old !== $new) {
                $affected++;
            }
            $delta += $new - $old;
            $preview[] = [
                'mapping_id' => (int) $row->mapping_id,
                'sku' => $row->external_sku,
                'product' => $row->product_name,
                'package' => $row->package_name,
                'cost_idr' => (int) $row->cost_idr,
                'before_sell_idr' => $old,
                'after_sell_idr' => $new,
                'difference_idr' => $new - $old,
            ];
        }

        return [
            'products' => $products,
            'default_margin' => DB::table('system_settings')->where('key', 'catalog.default_margin_percent')->value('value'),
            'conversions' => $conversions,
            'mappings' => $preview,
            'summary' => [
                'products' => count($products),
                'previewed_mappings' => count($preview),
                'affected_mappings' => $affected,
                'converted_packages' => count($conversions),
                'total_sell_difference_idr' => $delta,
            ],
        ];
    }

    private function maxPlan(array $prices): array
    {
        $providerId = Provider::where('code', 'DIGIFLAZZ')->value('id');
        $rows = $this->mappingRows()->where('mappings.provider_id', $providerId)
            ->whereIn('mappings.external_sku', array_keys($prices))
            ->orderBy('mappings.id')->get();
        $mapped = $rows->pluck('external_sku')->unique()->all();
        $missing = array_values(array_diff(array_keys($prices), $mapped));
        if ($missing !== []) {
            throw ValidationException::withMessages([
                'sku_text' => 'SKU tidak mempunyai mapping Digiflazz: '.implode(', ', array_slice($missing, 0, 5)),
            ]);
        }
        $preview = [];
        $delta = 0;
        foreach ($rows as $row) {
            $newCost = (int) $prices[$row->external_sku];
            $oldCost = (int) $row->cost_idr;
            $before = $this->sellPrice($row, $oldCost, (string) $row->product_margin);
            $after = $this->sellPrice($row, $newCost, (string) $row->product_margin);
            $delta += $after - $before;
            $preview[] = [
                'mapping_id' => (int) $row->mapping_id,
                'sku' => $row->external_sku,
                'product' => $row->product_name,
                'package' => $row->package_name,
                'is_manual_price' => (bool) $row->is_manual_price,
                'old_cost_idr' => $oldCost,
                'new_cost_idr' => $newCost,
                'old_max_price_idr' => $row->max_price_idr === null ? null : (int) $row->max_price_idr,
                'new_max_price_idr' => $newCost,
                'before_sell_idr' => $before,
                'after_sell_idr' => $after,
                'difference_idr' => $after - $before,
            ];
        }

        return [
            'prices' => $prices,
            'mappings' => $preview,
            'summary' => [
                'skus' => count($prices),
                'affected_mappings' => count($preview),
                'total_sell_difference_idr' => $delta,
            ],
        ];
    }

    private function mappingRows(): Builder
    {
        return DB::table('provider_mappings as mappings')
            ->join('product_packages as packages', 'packages.id', '=', 'mappings.product_package_id')
            ->join('products', 'products.id', '=', 'packages.product_id')
            ->select([
                'mappings.id as mapping_id', 'mappings.provider_id', 'mappings.external_sku',
                'mappings.cost_idr', 'mappings.max_price_idr', 'mappings.is_manual_price',
                'packages.id as package_id', 'packages.name as package_name',
                'packages.pricing_mode', 'packages.margin_percent as package_margin',
                'packages.margin_fixed_idr', 'packages.sell_price_idr',
                'products.id as product_id', 'products.name as product_name',
                'products.margin_percent as product_margin',
            ]);
    }

    private function sellPrice(object $row, int $cost, string $productMargin, bool $convert = false): int
    {
        $mode = $convert ? 'PRODUCT_MARGIN' : $row->pricing_mode;

        return match ($mode) {
            'SELL_PRICE' => (int) $row->sell_price_idr,
            'FIXED' => $cost + (int) $row->margin_fixed_idr,
            'PERCENT' => $cost + $this->marginIdr($cost, (string) $row->package_margin),
            default => $cost + $this->marginIdr($cost, $productMargin),
        };
    }

    private function marginIdr(int $cost, string $percent): int
    {
        if (! preg_match('/^(\d+)(?:\.(\d{1,4}))?$/', $percent, $parts)) {
            return 0;
        }
        $units = ((int) $parts[1] * 10000) + (int) str_pad($parts[2] ?? '', 4, '0');
        if ($units === 0) {
            return 0;
        }
        if ($cost > intdiv(PHP_INT_MAX - 999999, $units)) {
            throw ValidationException::withMessages(['margin_percent' => 'Nilai margin di luar batas aman.']);
        }

        return intdiv($cost * $units + 999999, 1000000);
    }

    private function parseMaxPrices(string $input): array
    {
        $prices = [];
        foreach (preg_split('/\r\n|\r|\n/', trim($input)) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $columns = str_contains($line, "\t") ? explode("\t", $line) : str_getcsv($line);
            if (count($columns) === 2 && mb_strtolower(trim($columns[0])) === 'sku') {
                continue;
            }
            if (count($columns) !== 2) {
                throw ValidationException::withMessages(['sku_text' => 'Setiap baris harus berisi SKU dan Harga Max (TSV/CSV).']);
            }
            $sku = trim($columns[0]);
            $raw = preg_replace('/^rp\s*/i', '', trim($columns[1]));
            $raw = preg_replace('/\s+/', '', $raw);
            if (! preg_match('/^[A-Za-z0-9._-]{1,120}$/', $sku)
                || ! preg_match('/^(?:\d+|\d{1,3}(?:[.,]\d{3})+)$/', $raw)
                || isset($prices[$sku])) {
                throw ValidationException::withMessages(['sku_text' => 'SKU atau Harga Max tidak valid, atau SKU duplikat: '.$sku]);
            }
            $digits = str_replace(['.', ','], '', $raw);
            if (strlen($digits) > 10 || (int) $digits <= 0 || (int) $digits > 2000000000) {
                throw ValidationException::withMessages(['sku_text' => 'Harga Max harus antara Rp1 hingga Rp2.000.000.000.']);
            }
            $prices[$sku] = (int) $digits;
            if (count($prices) > 5000) {
                throw ValidationException::withMessages(['sku_text' => 'Maksimal 5.000 SKU sekali proses.']);
            }
        }
        if ($prices === []) {
            throw ValidationException::withMessages(['sku_text' => 'Masukkan setidaknya satu SKU dan Harga Max.']);
        }
        ksort($prices);

        return $prices;
    }

    private function storePreview(Request $request, string $type, array $input, array $plan): string
    {
        $token = Str::random(48);
        Cache::put($this->previewKey($request, $type, $token), [
            'input' => $input,
            'fingerprint' => $this->fingerprint($plan),
        ], now()->addMinutes(self::PREVIEW_TTL_MINUTES));

        return $token;
    }

    private function consumePreview(Request $request, string $type, string $token): array
    {
        $key = $this->previewKey($request, $type, $token);
        // Cache::pull alone is a non-atomic read followed by delete.
        // Claiming the cache lock prevents concurrent confirmations of one token.
        $lock = Cache::lock($key.'.consume-lock', 15);
        if (! $lock->get()) {
            throw ValidationException::withMessages([
                'preview_token' => 'Preview sedang dikonfirmasi. Jangan kirim ulang.',
            ]);
        }

        try {
            $preview = Cache::pull($key);
        } finally {
            $lock->release();
        }

        if (! is_array($preview)) {
            throw ValidationException::withMessages([
                'preview_token' => 'Preview sudah kedaluwarsa atau belum dibuat. Buat preview baru.',
            ]);
        }

        return $preview;
    }

    private function previewKey(Request $request, string $type, string $token): string
    {
        return 'admin.pricing.preview.'.$request->user('admin')->id.'.'.$type.'.'.hash('sha256', $token);
    }

    private function fingerprint(array $plan): string
    {
        return hash('sha256', json_encode($plan, JSON_THROW_ON_ERROR));
    }

    private function assertUnchanged(string $expected, array $current): void
    {
        if (! hash_equals($expected, $this->fingerprint($current))) {
            throw ValidationException::withMessages([
                'preview_token' => 'Data berubah sejak preview dibuat. Harap buat preview baru.',
            ]);
        }
    }
}
