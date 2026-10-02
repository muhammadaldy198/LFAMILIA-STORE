<?php

namespace App\Services;

use App\Models\IntegrationCredential;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class AdminDigiflazzMonitorService
{
    public function connection(bool $includeBalance): array
    {
        $record = IntegrationCredential::where('code', 'digiflazz')->first();
        $config = $record?->config_ciphertext ?? [];
        $configured = (bool) $record?->is_active
            && is_array($config)
            && trim((string) ($config['username'] ?? '')) !== ''
            && trim((string) ($config['api_key'] ?? '')) !== '';

        $savedHealth = json_decode(
            (string) DB::table('system_settings')->where('key', 'integration.health.digiflazz')->value('value'),
            true
        ) ?: [];

        $result = [
            'configured' => $configured,
            'status' => $configured ? (string) ($savedHealth['status'] ?? 'UNTESTED') : 'NOT_CONFIGURED',
            'message' => $configured
                ? (string) ($savedHealth['message'] ?? 'Integrasi aktif. Jalankan Tes Koneksi di menu Integrasi untuk verifikasi.')
                : 'Lengkapi dan aktifkan Digiflazz di menu Integrasi.',
            'tested_at' => $savedHealth['tested_at'] ?? null,
            'balance_idr' => null,
            'balance_checked_at' => null,
        ];

        if (! $includeBalance || ! $configured) {
            return $result;
        }

        $key = 'admin.digiflazz.balance.'.hash('sha256', json_encode($config));

        return [...$result, ...Cache::remember($key, 60, function () use ($config): array {
            try {
                $base = rtrim((string) ($config['base_url'] ?? 'https://api.digiflazz.com'), '/');
                if (! str_starts_with(strtolower($base), 'https://')) {
                    return ['status' => 'DOWN', 'message' => 'Endpoint Digiflazz harus HTTPS.', 'balance_idr' => null, 'balance_checked_at' => now()->toIso8601String()];
                }
                $response = Http::acceptJson()->timeout(2)->connectTimeout(1)->post($base.'/v1/cek-saldo', [
                    'cmd' => 'deposit',
                    'username' => $config['username'],
                    'sign' => md5($config['username'].$config['api_key'].'depo'),
                ]);
                $amount = $response->json('data.deposit');
                if ($response->successful() && is_numeric($amount) && (float) $amount >= 0) {
                    return [
                        'status' => 'HEALTHY',
                        'message' => 'Koneksi dan saldo berhasil diverifikasi.',
                        'balance_idr' => (int) $amount,
                        'balance_checked_at' => now()->toIso8601String(),
                    ];
                }
            } catch (\Throwable) {
                // Monitoring page remains available when the read-only balance probe fails.
            }

            return [
                'status' => 'DOWN',
                'message' => 'Saldo tidak dapat diverifikasi. Data katalog tersimpan tetap digunakan.',
                'balance_idr' => null,
                'balance_checked_at' => now()->toIso8601String(),
            ];
        })];
    }

    public function health(object $item): array
    {
        $critical = [];
        $warning = [];

        if (! (bool) $item->buyer_active) {
            $critical[] = 'Produk buyer sedang nonaktif.';
        }
        if (! (bool) $item->seller_active) {
            $critical[] = 'Seller sedang nonaktif.';
        }
        if (! (bool) $item->unlimited_stock && (int) $item->stock <= 0) {
            $critical[] = 'Stok seller habis.';
        }
        if ($critical === [] && ! (bool) $item->unlimited_stock && (int) $item->stock <= 5) {
            $warning[] = 'Stok menipis: '.(int) $item->stock.' tersisa.';
        }
        if ($critical === [] && $this->insideCutoff((string) $item->start_cut_off, (string) $item->end_cut_off)) {
            $warning[] = 'Sedang cut-off '.(string) $item->start_cut_off.'–'.(string) $item->end_cut_off.' WIB.';
        }

        $baseline = (int) $item->baseline_price_idr;
        $price = (int) $item->price_idr;
        if ($critical === [] && $baseline > 0 && $price > $baseline) {
            $increase = (($price - $baseline) / $baseline) * 100;
            if ($increase >= 3) {
                $warning[] = 'Harga naik '.number_format($increase, 1, ',', '.').'% dari baseline.';
            }
        }

        return [
            'health' => $critical !== [] ? 'critical' : ($warning !== [] ? 'warning' : 'healthy'),
            'alert_reason' => implode(' ', [...$critical, ...$warning]) ?: null,
        ];
    }

    public function summary(iterable $items): array
    {
        $summary = ['total' => 0, 'healthy' => 0, 'warning' => 0, 'critical' => 0];
        foreach ($items as $item) {
            $summary['total']++;
            $summary[$this->health($item)['health']]++;
        }

        return $summary;
    }

    public function recentTransactions(int $limit = 8): array
    {
        $providerId = DB::table('providers')->where('code', 'DIGIFLAZZ')->value('id');
        if (! $providerId) {
            return [];
        }

        return DB::table('fulfillment_attempts as attempts')
            ->join('orders', 'orders.id', '=', 'attempts.order_id')
            ->join('products', 'products.id', '=', 'orders.product_id')
            ->join('product_packages', 'product_packages.id', '=', 'orders.product_package_id')
            ->where('attempts.provider_id', $providerId)
            ->orderByDesc('attempts.id')
            ->limit($limit)
            ->get([
                'attempts.id', 'attempts.status', 'attempts.provider_status', 'attempts.last_error',
                'attempts.created_at', 'orders.id as order_id', 'orders.order_number',
                'products.name as product_name', 'product_packages.name as package_name',
            ])
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'order_id' => (int) $row->order_id,
                'order_number' => $row->order_number,
                'product_name' => $row->product_name,
                'package_name' => $row->package_name,
                'status' => $row->status,
                'status_label' => app(AdminOrderPresentation::class)->status($row->status),
                'provider_status' => $row->provider_status,
                'message' => app(AdminOrderPresentation::class)->note($row->last_error),
                'created_at' => $row->created_at,
            ])->all();
    }

    private function insideCutoff(string $start, string $end): bool
    {
        if (! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $start)
            || ! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $end)
            || $start === $end) {
            return false;
        }

        $time = now('Asia/Jakarta')->format('H:i');

        return $start < $end
            ? $time >= $start && $time < $end
            : $time >= $start || $time < $end;
    }
}
