<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class OperationalSettings
{
    public const FIELDS = [
        'checkout_reservation_minutes' => ['key' => 'operations.checkout_reservation_minutes', 'label' => 'Batas waktu checkout (menit)', 'default' => 30, 'min' => 5, 'max' => 120],
        'wallet_topup_minutes' => ['key' => 'operations.wallet_topup_minutes', 'label' => 'Batas waktu isi saldo (menit)', 'default' => 30, 'min' => 5, 'max' => 120],
        'guest_pii_days' => ['key' => 'operations.guest_pii_days', 'label' => 'Hapus email/telepon pesanan tamu setelah (hari)', 'default' => 30, 'min' => 30, 'max' => 365],
        'stale_fulfillment_minutes' => ['key' => 'operations.stale_fulfillment_minutes', 'label' => 'Peringatan pengiriman tertunda (menit)', 'default' => 15, 'min' => 5, 'max' => 120],
        'manual_overdue_minutes' => ['key' => 'operations.manual_overdue_minutes', 'label' => 'Batas terlambat produk manual dalam jam layanan (menit)', 'default' => 60, 'min' => 10, 'max' => 720],
    ];

    public function value(string $name): int
    {
        $field = self::FIELDS[$name] ?? null;
        if ($field === null) {
            throw new \InvalidArgumentException('Pengaturan operasional tidak dikenal.');
        }
        $stored = DB::table('system_settings')->where('key', $field['key'])->value('value');
        $number = $stored === null ? null : json_decode((string) $stored, true);
        if (! is_int($number) && ! (is_string($number) && ctype_digit($number))) {
            return $field['default'];
        }

        return max($field['min'], min($field['max'], (int) $number));
    }

    public function all(): array
    {
        $values = [];
        foreach (array_keys(self::FIELDS) as $name) {
            $values[$name] = $this->value($name);
        }

        return $values;
    }
}
