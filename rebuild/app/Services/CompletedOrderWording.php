<?php

namespace App\Services;

/**
 * Memutuskan bunyi email penyelesaian pesanan untuk satu jalur
 * pengiriman: subjek, kalimat pembuka, kalimat penutup, mode
 * baris hasil, dan kode yang ditampilkan. Dipisah dari
 * TransactionalEmailService supaya keputusan bunyi tiap jalur
 * tinggal di satu tempat dan service itu tidak tumbuh melewati
 * batas ukuran file repo; service memakainya apa adanya untuk
 * merakit email teks dan konteks renderer HTML.
 */
class CompletedOrderWording
{
    /** Kode redeemable ditampilkan sebagai "Kode voucher". */
    public const MODE_REDEEM = 'redeem';

    /** Pengiriman manual: yang ditampilkan bukti pengiriman. */
    public const MODE_MANUAL = 'manual';

    /** Top up provider: yang ditampilkan nomor referensi. */
    public const MODE_REFERENCE = 'reference';

    /** Voucher tanpa kode dari provider: tidak ada baris hasil. */
    public const MODE_MISSING = 'missing';

    public function __construct(
        public readonly string $mode,
        public readonly ?string $code,
        public readonly string $subject,
        public readonly string $intro,
        public readonly string $closing,
    ) {}

    public static function resolve(string $deliveryKind, ?string $deliveryCode, ?string $deliveryReference, string $package): self
    {
        $isStock = $deliveryKind === 'stock';
        $isVoucher = $deliveryKind === 'voucher';
        // 'stock' dan 'voucher' sama-sama kode redeemable yang
        // ditukarkan sendiri oleh pembeli; payload 'voucher'
        // (Digiflazz) menyimpan kodenya di serial_number, yang
        // sampai ke sini sebagai delivery_reference.
        $code = $isVoucher ? ($deliveryCode ?? $deliveryReference) : $deliveryCode;
        // Provider bisa melaporkan sukses tanpa SN: order tetap
        // SUCCESS, tetapi email voucher tanpa kode tidak boleh
        // menjanjikan kode — arahkan pembeli ke CS.
        $voucherCodeMissing = $isVoucher && $code === null;

        if ($isStock || ($isVoucher && ! $voucherCodeMissing)) {
            return new self(
                self::MODE_REDEEM,
                $code,
                'Pesanan selesai — kode '.$package.' sudah siap ditukarkan',
                'Pesananmu sudah selesai diproses. Kode produkmu ada di bawah — tukarkan sendiri:',
                'Simpan kode ini baik-baik — jangan dibagikan ke orang lain. Kode ini yang ditukarkan untuk memakai produkmu; kode tidak terkirim otomatis ke akun game. Kalau kode tidak bisa ditukarkan, sampaikan nomor invoice di atas ke CS kami.'
            );
        }

        if ($voucherCodeMissing) {
            return new self(
                self::MODE_MISSING,
                null,
                'Pesanan selesai — '.$package.' sudah diproses',
                'Pesananmu sudah selesai diproses:',
                'Kode voucher untuk pesanan ini belum tersedia dari penyedia. Sampaikan nomor invoice di atas ke CS kami, kode kamu akan kami bantu teruskan.'
            );
        }

        if ($deliveryKind === 'manual') {
            return new self(
                self::MODE_MANUAL,
                $deliveryCode,
                'Pesanan selesai — '.$package.' sudah dikirim',
                'Pesananmu sudah selesai diproses dan sudah kami kirimkan secara manual:',
                'Pengiriman sudah kami lakukan secara manual. Kalau ada yang belum sesuai, sampaikan nomor invoice di atas ke CS kami.'
            );
        }

        return new self(
            self::MODE_REFERENCE,
            null,
            'Pesanan selesai — '.$package.' sudah masuk ke akunmu',
            'Pesananmu sudah selesai diproses dan item sudah terkirim ke akun tujuan:',
            'Silakan periksa saldo di dalam game. Kalau belum masuk setelah beberapa menit, sampaikan nomor invoice dan nomor referensi di atas ke CS kami.'
        );
    }
}
