<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class AdminChoiceSettings
{
    public const DEFAULTS = [
        'cancellations' => [
            ['code' => 'stok_habis', 'label' => 'Stok habis', 'active' => true],
            ['code' => 'salah_harga', 'label' => 'Salah harga', 'active' => true],
            ['code' => 'permintaan_pelanggan', 'label' => 'Permintaan pelanggan', 'active' => true],
            ['code' => 'lainnya', 'label' => 'Lainnya', 'active' => true],
        ],
        'support' => [
            ['code' => 'GENERAL', 'label' => 'Umum', 'active' => true],
            ['code' => 'ORDER', 'label' => 'Pesanan', 'active' => true],
            ['code' => 'PAYMENT', 'label' => 'Pembayaran', 'active' => true],
            ['code' => 'REFUND', 'label' => 'Pengembalian Dana', 'active' => true],
            ['code' => 'COMPLAINT', 'label' => 'Komplain', 'active' => true],
            ['code' => 'ACCOUNT', 'label' => 'Akun pelanggan', 'active' => true],
            ['code' => 'OTHER', 'label' => 'Lainnya', 'active' => true],
        ],
    ];

    public function all(): array
    {
        $raw = DB::table('system_settings')->where('key', 'store.admin_choices')->value('value');
        $stored = is_string($raw) ? json_decode($raw, true) : null;
        $output = [];

        foreach (self::DEFAULTS as $section => $fallback) {
            $entries = is_array($stored) && isset($stored[$section]) && is_array($stored[$section])
                ? $stored[$section] : $fallback;
            $output[$section] = [];
            foreach ($entries as $entry) {
                if (! is_array($entry) || ! is_string($entry['code'] ?? null)
                    || ! is_string($entry['label'] ?? null)
                    || ! preg_match('/^[a-zA-Z][a-zA-Z0-9_]{1,29}$/', $entry['code'])) {
                    continue;
                }
                $output[$section][] = [
                    'code' => $entry['code'],
                    'label' => mb_substr(trim($entry['label']), 0, 80),
                    'active' => (bool) ($entry['active'] ?? true),
                ];
            }
        }

        return $output;
    }

    public function active(string $section): array
    {
        return array_values(array_filter($this->all()[$section] ?? [],
            fn (array $option): bool => $option['active']));
    }

    public function labels(string $section): array
    {
        return collect($this->all()[$section] ?? [])->pluck('label', 'code')->all();
    }
}
