<?php

namespace App\Support;

/**
 * Human labels for audit trail values. Presentation only; audit rows keep raw codes.
 */
final class AuditLabels
{
    public function actionLabel(string $action): string
    {
        $parts = array_values(array_filter(explode('.', $action), fn (string $part): bool => $part !== ''));
        if ($parts === []) {
            return 'Aktivitas Admin';
        }

        $module = [
            'admin' => 'Admin & Akses',
            'order' => 'Pesanan',
            'catalog' => 'Produk',
            'product' => 'Produk',
            'category' => 'Produk',
            'package' => 'Produk',
            'fulfillment' => 'Manual',
            'content' => 'Banner & Konten',
            'banner' => 'Banner & Konten',
            'news' => 'Banner & Konten',
            'review' => 'Banner & Konten',
            'faq' => 'Banner & Konten',
            'digiflazz' => 'Digiflazz',
            'nickname' => 'Validasi Akun',
            'nickname_game_code' => 'Validasi Akun',
            'provider' => 'Provider',
            'payment' => 'Pembayaran',
            'customer' => 'Pelanggan',
            'wallet' => 'Pelanggan',
            'voucher' => 'Promo',
            'promo' => 'Promo',
            'popular' => 'Promo',
            'support' => 'Layanan Pelanggan',
            'report' => 'Laporan',
            'settings' => 'Pengaturan',
            'membership' => 'Pengaturan',
            'configuration' => 'Pengaturan',
            'integration' => 'Integrasi',
        ][$parts[0]] ?? 'Administrasi';

        $operation = array_pop($parts);
        array_shift($parts);

        $operationLabel = [
            'created' => 'ditambahkan',
            'updated' => 'diperbarui',
            'deleted' => 'dihapus',
            'enabled' => 'diaktifkan',
            'disabled' => 'dinonaktifkan',
            'revealed' => 'ditampilkan',
            'exported' => 'diekspor',
            'adjusted' => 'disesuaikan',
            'confirmed' => 'dikonfirmasi',
            'completed' => 'diselesaikan',
            'failed' => 'ditandai gagal',
            'retried' => 'dicoba ulang',
            'reconciled' => 'direkonsiliasi',
            'synced' => 'disinkronkan',
            'imported' => 'diimpor',
            'reordered' => 'diurutkan ulang',
            'uploaded' => 'diunggah',
            'tested' => 'dites',
            'settings_updated' => 'pengaturan diperbarui',
            'status_updated' => 'status diperbarui',
            'margin_updated' => 'margin diperbarui',
            'price_updated' => 'harga diperbarui',
            'toggled' => 'diubah statusnya',
            'activated' => 'diaktifkan',
            'deactivated' => 'dinonaktifkan',
            'replied' => 'dibalas',
            'sent' => 'dikirim',
            'resolved' => 'diselesaikan',
            'closed' => 'ditutup',
        ][$operation] ?? str_replace('_', ' ', $operation);

        $subjectMap = [
            'secret' => 'kredensial',
            'channel' => 'metode pembayaran',
            'gateway' => 'gateway',
            'route' => 'routing',
            'wallet' => 'saldo',
            'tier' => 'membership',
            'membership' => 'membership',
            'monitor' => 'monitor',
            'settings' => 'pengaturan',
            'game_code' => 'kode game',
            'quick_reply' => 'balasan cepat',
            'presentation' => 'tampilan',
            'banner' => 'banner',
            'media' => 'media',
            'category' => 'kategori',
            'mapping' => 'mapping provider',
            'product' => 'produk',
            'package' => 'nominal',
            'store' => 'toko',
        ];

        $subject = collect($parts)
            ->map(fn (string $part): string => $subjectMap[$part] ?? str_replace('_', ' ', $part))
            ->filter()
            ->implode(' ');

        return trim($module.' — '.($subject !== '' ? ucfirst($subject).' ' : '').$operationLabel);
    }

    public function targetLabel(string $target): string
    {
        return [
            'admin_user' => 'Akun Admin',
            'order' => 'Pesanan',
            'product' => 'Produk',
            'product_package' => 'Nominal',
            'product_category' => 'Kategori',
            'provider' => 'Provider',
            'provider_mapping' => 'Mapping Provider',
            'payment' => 'Pembayaran',
            'payment_channel' => 'Metode Pembayaran',
            'payment_gateway' => 'Gateway Pembayaran',
            'customer' => 'Pelanggan',
            'customer_user' => 'Pelanggan',
            'wallet' => 'Saldo Pelanggan',
            'voucher' => 'Voucher',
            'support_ticket' => 'Tiket Pelanggan',
            'integration' => 'Integrasi',
            'integration_credential' => 'Integrasi',
            'settings' => 'Pengaturan',
            'system_setting' => 'Pengaturan Sistem',
            'home_banner' => 'Banner',
            'nickname_game_code' => 'Kode Game',
            'membership_tier' => 'Membership',
            'report' => 'Laporan',
        ][$target] ?? ucwords(str_replace('_', ' ', $target));
    }
}
