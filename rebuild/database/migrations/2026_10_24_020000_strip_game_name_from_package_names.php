<?php

use App\Support\GameNamePrefix;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /** Before/after counts for the migration report. */
    public array $report = ['scanned' => 0, 'renamed' => 0];

    /**
     * Drop the leading game name from existing nominal labels, using the same
     * rule as new imports. Only the name column changes: price, margin, stock,
     * mappings and activation state are untouched. Safe to run repeatedly.
     */
    public function up(): void
    {
        $this->report = ['scanned' => 0, 'renamed' => 0];

        DB::table('product_packages')->orderBy('id')->chunkById(500, function ($packages): void {
            $ids = $packages->pluck('id')->all();
            $productNames = DB::table('products')
                ->whereIn('id', $packages->pluck('product_id')->unique()->all())
                ->pluck('name', 'id');

            $brands = [];
            $brandRows = DB::table('provider_mappings as m')
                ->join('providers as pr', 'pr.id', '=', 'm.provider_id')
                ->join('digiflazz_catalog_items as d', 'd.buyer_sku_code', '=', 'm.external_sku')
                ->where('pr.code', 'DIGIFLAZZ')
                ->whereIn('m.product_package_id', $ids)
                ->get(['m.product_package_id', 'd.brand']);
            foreach ($brandRows as $row) {
                $brand = trim((string) $row->brand);
                if ($brand !== '') {
                    $brands[(int) $row->product_package_id][$brand] = true;
                }
            }

            foreach ($packages as $package) {
                $this->report['scanned']++;
                $name = (string) $package->name;
                $clean = GameNamePrefix::strip(
                    $name,
                    $productNames[$package->product_id] ?? null,
                    ...array_keys($brands[(int) $package->id] ?? []),
                );
                if ($clean === $name) {
                    continue;
                }
                // Only replace the label that was read, never a concurrent edit.
                $this->report['renamed'] += DB::table('product_packages')
                    ->where('id', $package->id)->where('name', $name)
                    ->update(['name' => $clean]);
            }
        });

        Log::info('Nominal labels cleaned of leading game names.', $this->report);
    }

    public function down(): void
    {
        // The old labels are not recoverable and carried no information worth
        // restoring; the product name still describes the game.
    }
};
