<?php

namespace App\Support;

final class NominalLabel
{
    public static function clean(string $productName, string $label): string
    {
        $label = trim(preg_replace('/\s+/u', ' ', $label) ?? $label);
        $productName = trim(preg_replace('/\s+/u', ' ', $productName) ?? $productName);
        if ($label === '' || $productName === '') {
            return $label;
        }

        $words = preg_split('/[^\pL\pN]+/u', $productName, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($words === []) {
            return $label;
        }

        $parts = array_map(static fn (string $word): string => preg_quote($word, '/'), $words);
        $last = array_pop($parts);
        if ($last === null) {
            return $label;
        }

        // Accept common provider variations such as "Mobile Legends", "MOBILELEGEND", or "Free-Fire".
        $last = '(?:'.$last.'s?)';
        $pattern = '/^\s*'.implode('[\s._\-–—|]*', [...$parts, $last]).'\s*(?:[-–—|:]+\s*)?/iu';
        $clean = trim((string) preg_replace($pattern, '', $label, 1));

        return $clean !== '' ? $clean : $label;
    }

    public static function numericKey(string $productName, string $label): int
    {
        $clean = self::clean($productName, $label);
        if (preg_match('/\d[\d.,]*/', $clean, $match)) {
            $digits = preg_replace('/\D+/', '', $match[0]) ?? '';
            return $digits !== '' ? (int) $digits : PHP_INT_MAX;
        }

        return PHP_INT_MAX;
    }
}
