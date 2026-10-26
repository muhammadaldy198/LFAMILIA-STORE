<?php

namespace App\Services;

use App\Models\AdminUser;

class AdminPermissionService
{
    public const DEFINITIONS = [
        'dashboard.view' => 'Dashboard',
        'orders.view' => 'Pesanan',
        'catalog.manage' => 'Produk & katalog',
        'content.manage' => 'Banner & konten',
        'fulfillment.manage' => 'Pengiriman pesanan',
        'providers.manage' => 'Digiflazz & provider',
        'payments.manage' => 'Pembayaran operasional',
        'customers.view' => 'Pelanggan',
        'vouchers.manage' => 'Promo / voucher',
        'support.manage' => 'Layanan pelanggan',
        'reports.view' => 'Laporan',
        'reports.finance' => 'Laporan finansial',
        'settings.manage' => 'Pengaturan non-secret',
        'notifications.view' => 'Notifikasi',
    ];

    public function allows(?AdminUser $admin, string $permission): bool
    {
        if (! $admin || ! $admin->is_active) {
            return false;
        }
        if ($admin->role === 'SUPER_ADMIN') {
            return true;
        }
        if ($admin->role !== 'ADMIN' || ! array_key_exists($permission, self::DEFINITIONS)) {
            return false;
        }

        return in_array($permission, $admin->permissions ?? [], true);
    }

    /**
     * @return array<string, string>
     */
    public function definitions(): array
    {
        return self::DEFINITIONS;
    }

    /**
     * @return array<int, array{label:string,href:string,group:?string,permission:?string,super_only:bool}>
     */
    public function menu(?AdminUser $admin): array
    {
        // Satu sumber menu untuk label, urutan, kelompok, dan hak akses sidebar.
        $items = [
            ['label' => 'Dashboard', 'href' => '/admin/panel', 'group' => null, 'permission' => 'dashboard.view'],
            ['label' => 'Pesanan', 'href' => '/admin/orders', 'group' => 'Transaksi', 'permission' => 'orders.view'],
            ['label' => 'Pengiriman', 'href' => '/admin/fulfillment', 'group' => 'Transaksi', 'permission' => 'fulfillment.manage'],
            ['label' => 'Promo', 'href' => '/admin/vouchers', 'group' => 'Transaksi', 'permission' => 'vouchers.manage'],
            ['label' => 'Produk', 'href' => '/admin/catalog', 'group' => 'Katalog', 'permission' => 'catalog.manage'],
            ['label' => 'Kategori', 'href' => '/admin/catalog/categories', 'group' => 'Katalog', 'permission' => 'catalog.manage'],
            ['label' => 'Banner & Konten', 'href' => '/admin/content', 'group' => 'Konten', 'permission' => 'content.manage'],
            ['label' => 'Tampilan Toko', 'href' => '/admin/content/presentation', 'group' => 'Konten', 'permission' => 'content.manage'],
            ['label' => 'Digiflazz', 'href' => '/admin/digiflazz', 'group' => 'Integrasi', 'permission' => 'providers.manage'],
            ['label' => 'Validasi Akun', 'href' => '/admin/nickname-tools', 'group' => 'Integrasi', 'permission' => null, 'super_only' => true],
            ['label' => 'Provider', 'href' => '/admin/providers', 'group' => 'Integrasi', 'permission' => 'providers.manage'],
            ['label' => 'Pembayaran', 'href' => '/admin/payments', 'group' => 'Keuangan', 'permission' => 'payments.manage'],
            ['label' => 'Laporan', 'href' => '/admin/reports', 'group' => 'Keuangan', 'permission' => 'reports.view'],
            ['label' => 'Pelanggan', 'href' => '/admin/customers', 'group' => 'Pelanggan', 'permission' => 'customers.view'],
            ['label' => 'Layanan Pelanggan', 'href' => '/admin/support', 'group' => 'Pelanggan', 'permission' => 'support.manage'],
            ['label' => 'Notifikasi', 'href' => '/admin/notifications', 'group' => 'Sistem', 'permission' => 'notifications.view'],
            ['label' => 'Integrasi', 'href' => '/admin/integrations', 'group' => 'Sistem', 'permission' => null, 'super_only' => true],
            ['label' => 'Kesehatan Sistem', 'href' => '/admin/health', 'group' => 'Sistem', 'permission' => null, 'super_only' => true],
            ['label' => 'Catatan Audit', 'href' => '/admin/audit', 'group' => 'Sistem', 'permission' => null, 'super_only' => true],
            ['label' => 'Admin & Akses', 'href' => '/admin/access', 'group' => 'Pengaturan', 'permission' => null, 'super_only' => true],
            ['label' => 'Pengaturan', 'href' => '/admin/settings', 'group' => 'Pengaturan', 'permission' => 'settings.manage'],
        ];

        return collect($items)
            ->map(fn (array $item): array => [
                ...$item,
                'super_only' => $item['super_only'] ?? false,
                'permissions' => $item['permissions'] ?? (isset($item['permission']) ? [$item['permission']] : []),
            ])
            ->filter(function (array $item) use ($admin): bool {
                if ($item['super_only']) {
                    return $admin?->role === 'SUPER_ADMIN';
                }

                return collect($item['permissions'])
                    ->contains(fn (string $permission): bool => $this->allows($admin, $permission));
            })
            ->values()->all();
    }
}
