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
        $lastWord = array_pop($words);
        $last = array_pop($parts);
        if ($last === null || $lastWord === null) {
            return $label;
        }

        // Accept common provider variations such as "Mobile Legends", "MOBILELEGEND", or "Free-Fire".
        // Provider labels often omit a plural suffix: "Mobile Legends" → "MOBILELEGEND".
        // Avoid singularizing short names ("Us") and words ending in "ss" ("Chess").
        if (mb_strlen($lastWord) >= 5 && preg_match('/(?<!s)s$/iu', $lastWord)) {
            $last = preg_quote(mb_substr($lastWord, 0, -1), '/');
        }
        $last = '(?:'.$last.'s?)';
        $pattern = '/^\\s*'.implode('[\\s._\\-–—|]*', [...$parts, $last]).'(?:\\s*[-–—|:]+\\s*|\\s+)(?=\\S)/iu';
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
