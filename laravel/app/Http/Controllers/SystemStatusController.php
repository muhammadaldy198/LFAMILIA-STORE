<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class SystemStatusController
{
    public function __invoke(): JsonResponse
    {
        try {
            DB::select('select 1');

            $catalogReady = DB::table('products')
                ->where('is_active', 1)
                ->exists();

            $paymentsReady = DB::table('payment_gateway_settings as gateways')
                ->join('payment_channels as channels', 'channels.gateway', '=', 'gateways.gateway')
                ->where('gateways.is_active', 1)
                ->where('channels.is_active', 1)
                ->exists();

            $fulfillmentReady = DB::table('integration_profiles')
                ->where('provider', 'digiflazz')
                ->where('encrypted_config', '<>', '')
                ->exists();

            $settings = DB::table('store_settings')->where('id', 1)->first([
                'support_hours',
                'merchant_legal_name',
                'merchant_registration_id',
                'merchant_address',
            ]);

            return response()->json([
                'ok' => true,
                'service' => 'LFAMILIA STORE',
                'runtime' => 'laravel',
                'database' => 'ok',
                'environment' => app()->environment(),
                'services' => [
                    [
                        'id' => 'catalog',
                        'name' => 'Katalog produk',
                        'state' => $catalogReady ? 'operational' : 'degraded',
                        'detail' => $catalogReady
                            ? 'Produk yang tersedia dapat dipilih dan dipesan.'
                            : 'Katalog sedang diperbarui.',
                    ],
                    [
                        'id' => 'payments',
                        'name' => 'Pembayaran',
                        'state' => $paymentsReady ? 'operational' : 'degraded',
                        'detail' => $paymentsReady
                            ? 'Kanal pembayaran yang aktif siap digunakan.'
                            : 'Kanal pembayaran sedang ditinjau.',
                    ],
                    [
                        'id' => 'fulfillment',
                        'name' => 'Pengiriman otomatis',
                        'state' => $fulfillmentReady ? 'operational' : 'degraded',
                        'detail' => $fulfillmentReady
                            ? 'Pesanan otomatis diteruskan setelah pembayaran terverifikasi.'
                            : 'Sebagian pengiriman otomatis sedang ditinjau.',
                    ],
                    [
                        'id' => 'support',
                        'name' => 'Layanan pelanggan',
                        'state' => 'operational',
                        'detail' => (string) ($settings?->support_hours ?? 'Setiap hari'),
                    ],
                ],
                'merchant' => [
                    'legalName' => (string) ($settings?->merchant_legal_name ?? ''),
                    'registrationId' => (string) ($settings?->merchant_registration_id ?? ''),
                    'address' => (string) ($settings?->merchant_address ?? ''),
                ],
                'updatedAt' => now()->toIso8601String(),
            ], 200, [
                'Cache-Control' => 'public, max-age=15, s-maxage=15, stale-while-revalidate=30',
            ]);
        } catch (Throwable) {
            return response()->json([
                'ok' => false,
                'service' => 'LFAMILIA STORE',
                'runtime' => 'laravel',
                'database' => 'unavailable',
                'environment' => app()->environment(),
            ], 503, [
                'Cache-Control' => 'no-store',
            ]);
        }
    }
}
