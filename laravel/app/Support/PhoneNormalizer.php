<?php

namespace App\Support;

use InvalidArgumentException;

final class PhoneNormalizer
{
    public static function whatsapp(string $value): string
    {
        $compact = preg_replace('/[\s().-]+/', '', trim($value)) ?? '';
        $digits = preg_replace('/\D+/', '', ltrim($compact, '+')) ?? '';

        if ($digits === '') {
            throw new InvalidArgumentException('Nomor kontak tidak valid.');
        }

        $normalized = str_starts_with($digits, '0')
            ? '62'.substr($digits, 1)
            : $digits;

        if (!str_starts_with($normalized, '62')) {
            $normalized = $digits;
        }

        if (!preg_match('/^62[1-9][0-9]{7,13}$/', $normalized)) {
            throw new InvalidArgumentException('Gunakan nomor kontak Indonesia yang valid, misalnya 081234567890.');
        }

        return '+'.$normalized;
    }

    /** @return list<string> */
    public static function searchVariants(string $value): array
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if (strlen($digits) < 8 || strlen($digits) > 16) {
            return [];
        }

        if (str_starts_with($digits, '62')) {
            $local = substr($digits, 2);
        } elseif (str_starts_with($digits, '0')) {
            $local = substr($digits, 1);
        } else {
            $local = $digits;
        }

        if (!str_starts_with($local, '8')) {
            return [];
        }

        return array_values(array_unique([
            $local,
            '0'.$local,
            '62'.$local,
            '+62'.$local,
        ]));
    }
}
