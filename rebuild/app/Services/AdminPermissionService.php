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
        'fulfillment.manage' => 'Manual & fulfillment',
        'providers.manage' => 'Digiflazz & provider',
        'payments.manage' => 'Pembayaran operasional',
        'customers.view' => 'Pelanggan',
        'vouchers.manage' => 'Promo / voucher',
        'support.manage' => 'Layanan pelanggan',
        'reports.view' => 'Laporan',
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
     * @return array<int, array{label:string,href:string,permission:?string,super_only:bool}>
     */
    public function menu(?AdminUser $admin): array
    {
        $items = [
            ['label' => 'Dashboard', 'href' => '/admin/panel', 'permission' => 'dashboard.view'],
            ['label' => 'Pesanan', 'href' => '/admin/orders', 'permission' => 'orders.view'],
            ['label' => 'Produk', 'href' => '/admin/catalog', 'permission' => 'catalog.manage'],
            ['label' => 'Manual', 'href' => '/admin/fulfillment', 'permission' => 'fulfillment.manage'],
            ['label' => 'Banner & Konten', 'href' => '/admin/content', 'permission' => 'content.manage'],
            ['label' => 'Digiflazz', 'href' => '/admin/providers?provider=DIGIFLAZZ', 'permission' => 'providers.manage'],
            ['label' => 'Validasi Akun', 'href' => '/admin/nickname-tools', 'permission' => null, 'super_only' => true],
            ['label' => 'Provider', 'href' => '/admin/providers', 'permission' => 'providers.manage'],
            ['label' => 'Pembayaran', 'href' => '/admin/payments', 'permission' => 'payments.manage'],
            ['label' => 'Pelanggan', 'href' => '/admin/customers', 'permission' => 'customers.view'],
            ['label' => 'Promo', 'href' => '/admin/vouchers', 'permission' => 'vouchers.manage'],
            ['label' => 'Layanan Pelanggan', 'href' => '/admin/support', 'permission' => 'support.manage'],
            ['label' => 'Laporan', 'href' => '/admin/reports', 'permission' => 'reports.view'],
            ['label' => 'Admin & Akses', 'href' => '/admin/access', 'permission' => null, 'super_only' => true],
            ['label' => 'Pengaturan', 'href' => '/admin/settings', 'permission' => 'settings.manage'],
            ['label' => 'Integrasi', 'href' => '/admin/integrations', 'permission' => null, 'super_only' => true],
            ['label' => 'System Health', 'href' => '/admin/health', 'permission' => null, 'super_only' => true],
            ['label' => 'Audit Log', 'href' => '/admin/audit', 'permission' => null, 'super_only' => true],
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
