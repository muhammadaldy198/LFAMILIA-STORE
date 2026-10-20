<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Catalog redesign pricing controls.
 *
 * Two independent stages:
 *   1. cost uplift  — a one-time percentage on the Digiflazz seller price that
 *      produces the admin-editable store cost (applied at import/init only).
 *   2. sell markup  — a percentage on the store cost that produces the selling
 *      price (applied live at checkout, so changing it needs no data rewrite).
 *
 * Precedence for both: nominal override -> game override -> global setting.
 */
class CatalogPricingSettings
{
    public const COST_UPLIFT_KEY = 'pricing.cost_uplift_percent';

    public const SELL_MARKUP_KEY = 'pricing.sell_markup_percent';

    public const DEFAULT_COST_UPLIFT_PERCENT = '2';

    public const DEFAULT_SELL_MARKUP_PERCENT = '10';

    public const MAX_PERCENT = 1000;

    public function costUpliftPercent(): string
    {
        return $this->configured(self::COST_UPLIFT_KEY) ?? self::DEFAULT_COST_UPLIFT_PERCENT;
    }

    public function sellMarkupPercent(): string
    {
        return $this->configured(self::SELL_MARKUP_KEY) ?? self::DEFAULT_SELL_MARKUP_PERCENT;
    }

    public function costUpliftFor(?string $nominal, ?string $game): string
    {
        return $this->firstUsable($nominal, $game) ?? $this->costUpliftPercent();
    }

    public function sellMarkupFor(?string $nominal, ?string $game): string
    {
        return $this->firstUsable($nominal, $game) ?? $this->sellMarkupPercent();
    }

    /**
     * Round a percentage up to whole rupiah so a margin can never be lost to
     * truncation. Mirrors the integer maths used by checkout and the importer.
     */
    public function applyPercent(int $amountIdr, string $percent): int
    {
        if ($amountIdr < 0) {
            throw ValidationException::withMessages(['pricing' => 'Nilai harga tidak boleh negatif.']);
        }
        $units = $this->percentUnits($percent);
        if ($units === 0) {
            return $amountIdr;
        }
        if ($amountIdr > intdiv(PHP_INT_MAX - 999999, 1000000 + $units)) {
            throw ValidationException::withMessages(['pricing' => 'Nilai harga di luar batas aman.']);
        }

        return intdiv(($amountIdr * (1000000 + $units)) + 999999, 1000000);
    }

    /**
     * @param  array{cost_uplift_percent: mixed, sell_markup_percent: mixed}  $data
     */
    public function update(mixed $costUpliftPercent, mixed $sellMarkupPercent): void
    {
        $this->write(self::COST_UPLIFT_KEY, $this->normalize($costUpliftPercent));
        $this->write(self::SELL_MARKUP_KEY, $this->normalize($sellMarkupPercent));
    }

    public function normalize(mixed $value): string
    {
        $raw = is_string($value) ? trim($value) : $value;
        if (! is_numeric($raw)) {
            throw ValidationException::withMessages(['sell_markup_percent' => 'Persentase harus berupa angka.']);
        }
        $value = (float) $raw;
        if ($value < 0 || $value > self::MAX_PERCENT) {
            throw ValidationException::withMessages(['sell_markup_percent' => 'Persentase harus antara 0 dan 1000.']);
        }
        // decimal(8,4) storage: trim trailing zeroes for a stable string form.
        $formatted = rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');

        return $formatted === '' || $formatted === '-' ? '0' : $formatted;
    }

    private function firstUsable(?string $nominal, ?string $game): ?string
    {
        foreach ([$nominal, $game] as $candidate) {
            if ($candidate === null) {
                continue;
            }
            $normalized = $this->tryNormalize($candidate);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    private function tryNormalize(string $value): ?string
    {
        $value = trim($value);
        if (! is_numeric($value)) {
            return null;
        }
        $float = (float) $value;

        return $float >= 0 && $float <= self::MAX_PERCENT
            ? rtrim(rtrim(number_format($float, 4, '.', ''), '0'), '.') ?: '0'
            : null;
    }

    private function configured(string $key): ?string
    {
        $stored = DB::table('system_settings')->where('key', $key)->value('value');
        $decoded = $stored !== null ? json_decode((string) $stored, true) : null;
        if (! is_numeric($decoded)) {
            return null;
        }
        $value = (float) $decoded;

        return $value >= 0 && $value <= self::MAX_PERCENT
            ? rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.') ?: '0'
            : null;
    }

    private function write(string $key, string $value): void
    {
        DB::table('system_settings')->updateOrInsert(['key' => $key], [
            'value' => json_encode($value, JSON_THROW_ON_ERROR),
            'updated_at' => now(),
            'created_at' => now(),
        ]);
    }

    private function percentUnits(string $percent): int
    {
        if (! preg_match('/^(\d+)(?:\.(\d{1,4}))?$/', $percent, $parts)) {
            throw ValidationException::withMessages(['pricing' => 'Konfigurasi persentase tidak valid.']);
        }

        return ((int) $parts[1] * 10000) + (int) str_pad($parts[2] ?? '', 4, '0');
    }
}
