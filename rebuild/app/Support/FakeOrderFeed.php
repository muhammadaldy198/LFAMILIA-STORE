<?php

namespace App\Support;

use App\Services\PublicOrderStatusService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Umpan pesanan sintetis untuk halaman Cek Pesanan: entri pelengkap yang
 * dihitung deterministik dari katalog live, tanpa menyimpan baris apa pun
 * di database. Pola yang sama dengan dinding ulasan (ReviewWall): keluaran
 * murni fungsi dari (tick 5 menit, indeks) sehingga polling halaman yang
 * berulang dalam satu tick selalu menerima himpunan yang sama persis.
 *
 * Bukan urusannya: pesanan asli. Controller menggabungkan keduanya;
 * pesanan asli selalu tampil semua, entri sintetis hanya mengisi sisa
 * tempat sampai 20. Nomor sintetis tidak pernah bisa dilacak: pencarian
 * dan status publik selalu membaca tabel orders, yang tidak berisi
 * nomor-nomor ini.
 */
class FakeOrderFeed
{
    private const TIMEZONE = 'Asia/Jakarta';

    private const TICK_SECONDS = 300;

    /** Peluang status SUCCESS; sisanya PROCESSING. */
    private const SUCCESS_WEIGHT = 0.85;

    /** Umur entri sintetis: 2 menit sampai 12 jam sebelum awal tick. */
    private const MIN_AGE_MINUTES = 2;

    private const MAX_AGE_MINUTES = 720;

    private const REFERENCE_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

    /**
     * @param  list<string>  $excludedMaskedIds  masked id pesanan asli pada respons yang sama
     * @return list<array{referenceId: null, maskedReferenceId: string, productName: string, packageLabel: string, total: int, status: string, statusNote: null, createdAt: string}>
     */
    public static function entries(int $count, CarbonImmutable $now, array $excludedMaskedIds, PublicOrderStatusService $statuses): array
    {
        if ($count <= 0) {
            return [];
        }

        $catalog = self::catalog();
        if ($catalog === []) {
            return [];
        }

        $tick = intdiv($now->timestamp, self::TICK_SECONDS);
        $tickStart = CarbonImmutable::createFromTimestamp($tick * self::TICK_SECONDS, 'UTC');
        $usedMaskedIds = array_flip($excludedMaskedIds);
        $entries = [];

        for ($i = 0; count($entries) < $count; $i++) {
            $entry = self::entry($catalog, $tick, $tickStart, $i, $usedMaskedIds, $statuses, $now);
            if ($entry === null) {
                break;
            }
            $usedMaskedIds[$entry['maskedReferenceId']] = true;
            $entries[] = $entry;
        }

        return $entries;
    }

    /**
     * Satu entri sintetis; semua pilihan (paket, status, waktu, nomor) dari
     * hash (tick, indeks). Tabrakan masked id digeser deterministik (salt
     * retry); entri dibatalkan (null) bila tetap tabrakan.
     *
     * @param  list<array{product_name: string, package_name: string, sell_price_idr: int}>  $catalog
     * @param  array<string, bool>  $usedMaskedIds
     */
    private static function entry(array $catalog, int $tick, CarbonImmutable $tickStart, int $index, array $usedMaskedIds, PublicOrderStatusService $statuses, CarbonImmutable $now): ?array
    {
        $package = $catalog[(int) (self::unit("feed|pkg|{$tick}|{$index}") * count($catalog))];
        $rawStatus = self::unit("feed|status|{$tick}|{$index}") < self::SUCCESS_WEIGHT ? 'SUCCESS' : 'PROCESSING';

        $ageMinutes = self::MIN_AGE_MINUTES + (int) (self::unit("feed|age|{$tick}|{$index}") * (self::MAX_AGE_MINUTES - self::MIN_AGE_MINUTES));
        $jitterSeconds = (int) (self::unit("feed|jitter|{$tick}|{$index}") * 240);
        $created = $tickStart->subMinutes($ageMinutes)->addSeconds($jitterSeconds);
        if ($created->greaterThan($now)) {
            // Jendela berjalan belum selesai: jangan tampilkan waktu masa depan.
            $created = $now->subSeconds(20 + $index * 37);
        }

        $masked = null;
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $candidate = self::maskReference(self::reference($tick, $index, $attempt, $created));
            if (! isset($usedMaskedIds[$candidate])) {
                $masked = $candidate;
                break;
            }
        }
        if ($masked === null) {
            return null;
        }

        return [
            'referenceId' => null,
            'maskedReferenceId' => $masked,
            'productName' => $package['product_name'],
            'packageLabel' => $package['package_name'],
            'total' => $package['sell_price_idr'],
            'status' => $statuses->slug($rawStatus),
            'statusNote' => $statuses->note($rawStatus, true),
            'createdAt' => $created->toIso8601String(),
        ];
    }

    /** Nomor berbentuk sama dengan CheckoutCreationService: 'LF'.ymd.'-'.10 char acak kapital. */
    private static function reference(int $tick, int $index, int $attempt, CarbonImmutable $created): string
    {
        $chars = '';
        for ($position = 0; $position < 10; $position++) {
            $chars .= self::REFERENCE_CHARS[(int) (self::unit("feed|ref|{$tick}|{$index}|{$attempt}|{$position}") * strlen(self::REFERENCE_CHARS))];
        }

        return 'LF'.$created->setTimezone(self::TIMEZONE)->format('ymd').'-'.$chars;
    }

    /** Sama persis dengan PublicOrderTrackingController::maskReference untuk nomor > 9 char. */
    private static function maskReference(string $reference): string
    {
        if (strlen($reference) <= 9) {
            return substr($reference, 0, 3).'***';
        }

        return substr($reference, 0, 5).'••••'.substr($reference, -4);
    }

    /**
     * Katalog live (read-only): paket aktif dari produk aktif berharga jual > 0.
     *
     * @return list<array{product_name: string, package_name: string, sell_price_idr: int}>
     */
    private static function catalog(): array
    {
        return DB::table('product_packages')
            ->join('products', 'products.id', '=', 'product_packages.product_id')
            ->where('product_packages.is_active', true)
            ->where('products.is_active', true)
            ->where('product_packages.sell_price_idr', '>', 0)
            ->orderBy('product_packages.id')
            ->get([
                'products.name as product_name',
                'product_packages.name as package_name',
                'product_packages.sell_price_idr',
            ])
            ->map(fn (object $row): array => [
                'product_name' => (string) $row->product_name,
                'package_name' => (string) $row->package_name,
                'sell_price_idr' => (int) $row->sell_price_idr,
            ])
            ->all();
    }

    /**
     * Hash deterministik ke rentang [0, 1); sama dengan ReviewWall::unit.
     * Wajib sha256: crc32 difusinya lemah untuk kunci yang hanya beda angka tick.
     */
    private static function unit(string $key): float
    {
        return hexdec(substr(hash('sha256', $key), 0, 8)) / 4294967295.0;
    }
}
