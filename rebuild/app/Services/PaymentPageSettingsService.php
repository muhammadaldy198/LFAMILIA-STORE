<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class PaymentPageSettingsService
{
    public const KEY = 'payment.page_settings';

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return [
            'accentColor' => '#b9ff35',
            'headerImageUrl' => '',
            'eyebrow' => 'LFAMILIA PAYMENT',
            'pendingTitle' => 'Selesaikan pembayaran',
            'paidTitle' => 'Pembayaran berhasil',
            'failedTitle' => 'Pembayaran tidak aktif',
            'subtitle' => 'Pembayaran diproses aman oleh LFAMILIA STORE.',
            'invoiceNoticeTitle' => 'Simpan invoice sebelum membayar',
            'invoiceNoticeText' => 'Invoice diperlukan untuk mengecek transaksi jika halaman pembayaran tertutup atau terjadi kendala.',
            'pendingStatusText' => 'Status diperiksa otomatis setiap 3 detik.',
            'paidStatusText' => 'Pembayaran sudah diterima. Status pesanan akan diperbarui otomatis.',
            'failedStatusText' => 'Transaksi ini tidak dapat dilanjutkan. Buat checkout baru bila diperlukan.',
            'payButtonText' => 'Bayar Sekarang',
            'checkStatusButtonText' => 'Cek status',
            'checkInvoiceButtonText' => 'Cek invoice',
            'supportText' => 'Butuh bantuan pembayaran?',
            'supportUrl' => '/contact',
            'showStoreBrand' => true,
            'showInvoiceNotice' => true,
            'showOrderSummary' => true,
            'showStatusBox' => true,
            'showSupport' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function read(): array
    {
        $raw = DB::table('system_settings')->where('key', self::KEY)->value('value');
        if (! is_string($raw) || trim($raw) === '') {
            return $this->defaults();
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return $this->defaults();
        }

        return [...$this->defaults(), ...$decoded];
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function write(array $settings, ?int $adminId): void
    {
        DB::table('system_settings')->updateOrInsert(
            ['key' => self::KEY],
            [
                'value' => json_encode([...$this->defaults(), ...$settings], JSON_THROW_ON_ERROR),
                'updated_by_admin_id' => $adminId,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }
}