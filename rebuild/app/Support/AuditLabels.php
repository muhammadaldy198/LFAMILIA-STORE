<?php

namespace App\Support;

/**
 * Human labels for audit trail values. Presentation only; audit rows keep raw codes.
 *
 * Setiap kode aksi yang dipakai aplikasi punya kalimat Indonesia baku di
 * ACTION_LABELS. Kode baru yang belum terdaftar tidak pernah tampil mentah:
 * label dirakit dari SUBJECT + OPERATION, dan bila tetap tidak dikenali menjadi
 * "Aktivitas <modul>".
 */
final class AuditLabels
{
    private const MODULE = [
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
        'provider' => 'Penyedia',
        'payment' => 'Pembayaran',
        'customer' => 'Pelanggan',
        'wallet' => 'Pelanggan',
        'voucher' => 'Promo',
        'promo' => 'Promo',
        'promotion' => 'Promo',
        'popular' => 'Promo',
        'support' => 'Layanan Pelanggan',
        'report' => 'Laporan',
        'settings' => 'Pengaturan',
        'store' => 'Pengaturan',
        'membership' => 'Pengaturan',
        'configuration' => 'Pengaturan',
        'integration' => 'Integrasi',
    ];

    private const OPERATION = [
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
        'duplicated' => 'diduplikasi',
        'reordered' => 'diurutkan ulang',
        'uploaded' => 'diunggah',
        'tested' => 'diuji',
        'sent' => 'dikirim',
        'replied' => 'dibalas',
        'resolved' => 'diselesaikan',
        'closed' => 'ditutup',
        'cancelled' => 'dibatalkan',
        'checked' => 'diperiksa',
        'resent' => 'dikirim ulang',
        'activated' => 'diaktifkan',
        'deactivated' => 'dinonaktifkan',
        'toggled' => 'diubah statusnya',
    ];

    private const SUBJECT = [
        'secret' => 'kredensial',
        'channel' => 'metode pembayaran',
        'gateway' => 'gerbang pembayaran',
        'route' => 'jalur pembayaran',
        'wallet' => 'saldo',
        'tier' => 'tingkatan pelanggan',
        'membership' => 'tingkatan pelanggan',
        'monitor' => 'pemantauan',
        'settings' => 'pengaturan',
        'game_code' => 'kode game',
        'quick_reply' => 'balasan cepat',
        'presentation' => 'tampilan',
        'banner' => 'banner',
        'media' => 'gambar',
        'category' => 'kategori',
        'mapping' => 'sumber harga',
        'product' => 'produk',
        'package' => 'nominal',
        'packages' => 'nominal',
        'store' => 'toko',
        'theme' => 'tema tampilan',
        'asset' => 'aset toko',
        'fields' => 'kolom data pelanggan',
        'notice' => 'pemberitahuan',
        'pricing' => 'pengaturan harga',
        'margin' => 'margin',
        'faq' => 'FAQ',
        'news' => 'berita',
        'page' => 'halaman bantuan',
        'popup' => 'pop-up',
        'review' => 'ulasan',
        'baseline' => 'harga acuan',
        'catalog' => 'katalog',
        'ticket' => 'tiket',
        'payment' => 'pembayaran',
        'delivery' => 'pengiriman pesanan',
        'connection' => 'koneksi',
        'test_email' => 'email uji',
        'resend' => 'Resend',
        'popular' => 'produk populer',
        'cutoff' => 'batas waktu penyedia',
        'source' => 'sumber',
    ];

    /** Kalimat baku per kode aksi; kuncinya nilai kolom audit_logs.action. */
    private const ACTION_LABELS = [
        'admin.created' => 'Admin & Akses — akun Admin ditambahkan',
        'admin.updated' => 'Admin & Akses — akun Admin diperbarui',
        'admin.deleted' => 'Admin & Akses — akun Admin dihapus',

        'catalog.product.created' => 'Produk — produk ditambahkan',
        'catalog.product.updated' => 'Produk — produk diperbarui',
        'catalog.product.deleted' => 'Produk — produk dihapus',
        'catalog.product.manual_nominals_activated' => 'Produk — produk diaktifkan setelah nominal manual diimpor',
        'catalog.package.created' => 'Produk — nominal ditambahkan',
        'catalog.package.updated' => 'Produk — nominal diperbarui',
        'catalog.package.deleted' => 'Produk — nominal dihapus',
        'catalog.package.duplicated' => 'Produk — nominal diduplikasi',
        'catalog.package.imported' => 'Produk — nominal diimpor dari penyedia',
        'catalog.packages.reordered' => 'Produk — urutan nominal diubah',
        'catalog.category.created' => 'Produk — kategori ditambahkan',
        'catalog.category.updated' => 'Produk — kategori diperbarui',
        'catalog.category.deleted' => 'Produk — kategori dihapus',
        'catalog.fields.updated' => 'Produk — kolom data pelanggan diperbarui',
        'catalog.notice.created' => 'Produk — pemberitahuan ditambahkan',
        'catalog.notice.updated' => 'Produk — pemberitahuan diperbarui',
        'catalog.notice.deleted' => 'Produk — pemberitahuan dihapus',
        'catalog.media.uploaded' => 'Produk — gambar diunggah',
        'catalog.media.deleted' => 'Produk — gambar dihapus',
        'catalog.asset.updated' => 'Produk — aset toko diperbarui',
        'catalog.pricing.updated' => 'Produk — pengaturan harga diperbarui',
        'catalog.margin.global_updated' => 'Produk — margin global diperbarui',
        'catalog.mapping.created' => 'Produk — sumber harga ditambahkan',
        'catalog.mapping.updated' => 'Produk — sumber harga diperbarui',
        'catalog.manual_source_cutoff_attached' => 'Produk — sumber harga cadangan ditambahkan di batas waktu penyedia',
        'catalog.manual_source_ranked' => 'Produk — urutan sumber harga disusun ulang',

        'content.banner.created' => 'Banner & Konten — banner ditambahkan',
        'content.banner.updated' => 'Banner & Konten — banner diperbarui',
        'content.banner.deleted' => 'Banner & Konten — banner dihapus',
        'content.popup.created' => 'Banner & Konten — pop-up ditambahkan',
        'content.popup.updated' => 'Banner & Konten — pop-up diperbarui',
        'content.popup.deleted' => 'Banner & Konten — pop-up dihapus',
        'content.faq.created' => 'Banner & Konten — FAQ ditambahkan',
        'content.faq.updated' => 'Banner & Konten — FAQ diperbarui',
        'content.faq.deleted' => 'Banner & Konten — FAQ dihapus',
        'content.news.created' => 'Banner & Konten — berita ditambahkan',
        'content.news.updated' => 'Banner & Konten — berita diperbarui',
        'content.news.deleted' => 'Banner & Konten — berita dihapus',
        'content.page.updated' => 'Banner & Konten — halaman bantuan diperbarui',
        'content.review.updated' => 'Banner & Konten — ulasan diperbarui',
        'content.review.deleted' => 'Banner & Konten — ulasan dihapus',
        'content.presentation.updated' => 'Banner & Konten — tampilan toko diperbarui',
        'content.settings.updated' => 'Banner & Konten — pengaturan konten diperbarui',
        'content.theme.updated' => 'Banner & Konten — tema tampilan diperbarui',

        'digiflazz.baseline.updated' => 'Digiflazz — harga acuan diperbarui',
        'digiflazz.catalog.synced' => 'Digiflazz — katalog disinkronkan',
        'digiflazz.product.synced' => 'Digiflazz — produk disinkronkan',
        'digiflazz.mapping.synced' => 'Digiflazz — sumber harga disinkronkan',
        'digiflazz.max_price.buyer_area_updated' => 'Digiflazz — batas harga dari Buyer Area diperbarui',
        'digiflazz.monitor.settings_updated' => 'Digiflazz — pengaturan pemantauan diperbarui',

        'integration.updated' => 'Integrasi — diperbarui',
        'integration.connection.tested' => 'Integrasi — koneksi diuji',
        'integration.resend.test_email.sent' => 'Integrasi — email uji Resend berhasil dikirim',
        'integration.resend.test_email.failed' => 'Integrasi — email uji Resend gagal dikirim',

        'order.cancelled' => 'Pesanan — pesanan dibatalkan',
        'order.manual.created' => 'Pesanan — pesanan manual dicatat',
        'order.delivery.resent' => 'Pesanan — pengiriman pesanan dikirim ulang',
        'order.fulfillment.checked' => 'Pesanan — status penanganan diperiksa',
        'order.payment.checked' => 'Pesanan — status pembayaran diperiksa',

        'promotion.popular.updated' => 'Promo — produk populer diperbarui',
        'voucher.created' => 'Promo — voucher ditambahkan',
        'voucher.updated' => 'Promo — voucher diperbarui',
        'voucher.deleted' => 'Promo — voucher dihapus',

        'report.exported' => 'Laporan — data diekspor',
        'settings.store.updated' => 'Pengaturan — identitas toko diperbarui',
        'store.theme' => 'Pengaturan — tema tampilan disimpan',
        'support.ticket.created' => 'Layanan Pelanggan — tiket pelanggan ditambahkan',
        'support.updated' => 'Layanan Pelanggan — diperbarui',
    ];

    public function actionLabel(string $action): string
    {
        if (isset(self::ACTION_LABELS[$action])) {
            return self::ACTION_LABELS[$action];
        }

        $parts = array_values(array_filter(explode('.', $action), fn (string $part): bool => $part !== ''));
        if ($parts === []) {
            return 'Aktivitas Admin';
        }

        $module = self::MODULE[$parts[0]] ?? 'Administrasi';
        $operation = (string) array_pop($parts);
        array_shift($parts);

        $operationLabel = self::OPERATION[$operation] ?? null;
        $subject = $this->subject($parts);

        if ($operationLabel === null) {
            return $subject !== '' ? $module.' — '.$subject : 'Aktivitas '.$module;
        }

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
            'provider' => 'Penyedia',
            'provider_mapping' => 'Sumber harga',
            'payment' => 'Pembayaran',
            'payment_channel' => 'Metode Pembayaran',
            'payment_gateway' => 'Gerbang Pembayaran',
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
            'membership_tier' => 'Tingkatan Pelanggan',
            'report' => 'Laporan',
            'digiflazz_catalog_item' => 'SKU Digiflazz',
        ][$target] ?? ($this->subject(explode('_', $target)) ?: 'Data sistem');
    }

    /** @param  array<int, string>  $parts */
    private function subject(array $parts): string
    {
        return collect($parts)
            ->map(fn (string $part): string => self::SUBJECT[$part] ?? '')
            ->filter()
            ->implode(' ');
    }
}
