<?php

namespace App\Services;

use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cashback LFAMILIA Coin untuk pesanan member yang sukses: 1 coin per
 * Rp1.000 belanja (dibulatkan ke bawah), hanya bila belanja minimal
 * Rp150.000. 1 coin = Rp1 saldo dompet, jadi coin ditulis sebagai kredit
 * ledger dompet biasa dengan sumber CASHBACK. Keputusan pemilik: berlaku
 * untuk semua metode bayar, tamu tidak dapat, tanpa masa berlaku dan
 * tanpa batas pakai.
 *
 * Class ini TIDAK mengubah status pesanan, TIDAK memotong saldo, dan
 * TIDAK mengirim notifikasi. Earn adalah efek samping: kegagalannya
 * hanya dicatat sebagai warning, tidak pernah menggagalkan pesanan.
 */
class CashbackCoinService
{
    public const MINIMUM_BASIS_IDR = 150000;

    public const RUPIAH_PER_COIN = 1000;

    public const LEDGER_SOURCE = 'CASHBACK';

    public const LEDGER_REFERENCE_TYPE = 'ORDER';

    /**
     * Jumlah coin untuk satu nominal belanja. Dipakai juga oleh lencana
     * kartu paket di katalog supaya angka di etalase dan angka yang
     * masuk dompet berasal dari satu aturan yang sama.
     */
    public static function coinsForAmount(int $basisIdr): int
    {
        if ($basisIdr < self::MINIMUM_BASIS_IDR) {
            return 0;
        }

        return intdiv($basisIdr, self::RUPIAH_PER_COIN);
    }

    public static function idempotencyKey(int $orderId): string
    {
        return 'cashback:order:'.$orderId;
    }

    /**
     * Dipanggil dari dalam transaksi yang mengubah pesanan menjadi
     * SUCCESS. Earn baru jalan setelah transaksi itu commit, jadi status
     * SUCCESS sudah tersimpan dan rollback transaksi pemanggil otomatis
     * membatalkan earn. Tanpa transaksi aktif, earn langsung jalan.
     */
    public function earnAfterCommit(int $orderId): void
    {
        DB::afterCommit(fn () => $this->earnForOrderSafely($orderId));
    }

    /**
     * Versi earn yang tidak pernah melempar exception, untuk jalur
     * transisi pesanan. Kegagalan ditulis ke log sebagai warning.
     */
    public function earnForOrderSafely(int $orderId): int
    {
        try {
            return $this->earnForOrder($orderId);
        } catch (Throwable $exception) {
            Log::warning('Cashback coin tidak dapat ditulis untuk pesanan.', [
                'order_id' => $orderId,
                'reason' => $exception->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * Mengkredit cashback coin untuk satu pesanan, tepat sekali.
     *
     * Idempotensi berlapis, meniru pola penyesuaian saldo dan top up:
     * pesanan dan baris dompet dikunci, ledger CASHBACK pesanan ini dicek
     * dulu, lalu ditulis dengan insertOrIgnore di atas dua unique
     * constraint (idempotency_key dan source+reference). Saldo hanya
     * bertambah bila baris ledger benar-benar tersisip, jadi pemanggilan
     * serentak dari dua jalur tetap mengkredit satu kali.
     *
     * @return int jumlah coin yang dikredit oleh panggilan ini (0 bila tidak ada penulisan)
     */
    public function earnForOrder(int $orderId): int
    {
        return DB::transaction(function () use ($orderId): int {
            $order = DB::table('orders')->where('id', $orderId)->lockForUpdate()
                ->first(['id', 'user_id', 'status', 'snapshot']);
            if (! $order || $order->user_id === null || $order->status !== 'SUCCESS') {
                return 0;
            }

            $basisIdr = $this->basisFromSnapshot($order->snapshot);
            if ($basisIdr === null) {
                Log::warning('Cashback coin dilewati: rincian harga snapshot pesanan tidak terbaca.', [
                    'order_id' => (int) $order->id,
                ]);

                return 0;
            }

            $coins = self::coinsForAmount($basisIdr);
            if ($coins === 0) {
                return 0;
            }

            return $this->creditWallet((int) $order->id, (int) $order->user_id, $coins);
        }, 3);
    }

    /**
     * Basis cashback = nominal barang yang benar-benar dibayar: subtotal
     * setelah diskon voucher, sebelum biaya kanal. Snapshot pesanan tidak
     * menyimpan subtotal sebagai kunci tersendiri; CheckoutCreationService
     * menyusun total = (subtotal - diskon voucher) + biaya kanal, jadi
     * basis dibaca sebagai pricing.total_idr - pricing.fee_idr dari
     * snapshot yang sama, tanpa menghitung ulang dari tabel lain.
     */
    private function basisFromSnapshot(mixed $snapshot): ?int
    {
        $decoded = is_string($snapshot) ? json_decode($snapshot, true) : null;
        if (! is_array($decoded)) {
            return null;
        }

        $totalIdr = $this->wholeRupiah(data_get($decoded, 'pricing.total_idr'));
        $feeIdr = $this->wholeRupiah(data_get($decoded, 'pricing.fee_idr'));
        if ($totalIdr === null || $feeIdr === null || $feeIdr > $totalIdr) {
            return null;
        }

        return $totalIdr - $feeIdr;
    }

    private function wholeRupiah(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }

    private function creditWallet(int $orderId, int $userId, int $coins): int
    {
        Wallet::firstOrCreate(['user_id' => $userId]);
        $wallet = DB::table('wallets')->where('user_id', $userId)->lockForUpdate()->first();
        $idempotencyKey = self::idempotencyKey($orderId);

        $alreadyCredited = DB::table('wallet_ledger')
            ->where('idempotency_key', $idempotencyKey)
            ->orWhere(fn ($query) => $query->where('source', self::LEDGER_SOURCE)
                ->where('reference_type', self::LEDGER_REFERENCE_TYPE)
                ->where('reference_id', (string) $orderId))
            ->exists();
        if ($alreadyCredited) {
            return 0;
        }

        $beforeBalance = (int) $wallet->balance_idr;
        $afterBalance = $beforeBalance + $coins;
        $inserted = DB::table('wallet_ledger')->insertOrIgnore([
            'wallet_id' => $wallet->id,
            'amount_idr' => $coins,
            'balance_before_idr' => $beforeBalance,
            'balance_after_idr' => $afterBalance,
            'source' => self::LEDGER_SOURCE,
            'reference_type' => self::LEDGER_REFERENCE_TYPE,
            'reference_id' => (string) $orderId,
            'actor_type' => 'system',
            'actor_id' => null,
            'idempotency_key' => $idempotencyKey,
            'created_at' => now(),
        ]);
        if ($inserted !== 1) {
            return 0;
        }

        DB::table('wallets')->where('id', $wallet->id)->update([
            'balance_idr' => $afterBalance,
            'version' => DB::raw('version + 1'),
            'updated_at' => now(),
        ]);

        return $coins;
    }
}
