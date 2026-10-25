<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Baris katalog lama belum punya modal toko (store_cost_idr NULL), sehingga
 * harga jualnya ikut bergerak setiap kali sinkronisasi memperbarui harga seller.
 * Backfill sekali jalan: modal toko = harga referensi seller × 1,02.
 *
 * Idempoten: hanya menyentuh baris yang store_cost_idr-nya masih NULL.
 */
return new class extends Migration
{
    private const UPLIFT_PERCENT = 2;

    public function up(): void
    {
        $filled = 0;
        $skipped = 0;

        // Only Digiflazz mappings have a seller reference price to uplift.
        // MANUAL and VOUCHER_STOCK costs must never be changed by this migration.
        DB::table('provider_mappings')
            ->whereIn('provider_id', DB::table('providers')->where('code', 'DIGIFLAZZ')->select('id'))
            ->whereNull('store_cost_idr')
            ->orderBy('id')
            ->chunkById(500, function ($mappings) use (&$filled, &$skipped): void {
                foreach ($mappings as $mapping) {
                    $reference = $mapping->seller_reference_price_idr ?? $mapping->cost_idr;
                    if ($reference === null || (int) $reference <= 0) {
                        $skipped++;

                        continue;
                    }

                    $storeCost = (int) ceil(((int) $reference) * (100 + self::UPLIFT_PERCENT) / 100);
                    $updated = DB::table('provider_mappings')
                        ->where('id', $mapping->id)
                        ->whereNull('store_cost_idr')
                        ->update([
                            'store_cost_idr' => $storeCost,
                            'seller_reference_price_idr' => $mapping->seller_reference_price_idr ?? (int) $reference,
                            'max_price_idr' => $storeCost,
                            'updated_at' => now(),
                        ]);
                    $filled += $updated;
                }
            });

        $report = 'Backfill store_cost_idr: '.$filled.' baris diisi, '.$skipped.' baris dilewati (tanpa harga referensi).';
        Log::info($report);
        if (app()->runningInConsole()) {
            echo $report.PHP_EOL;
        }
    }

    public function down(): void
    {
        // Tidak dikembalikan: modal toko yang sudah terkunci adalah data bisnis.
    }
};
