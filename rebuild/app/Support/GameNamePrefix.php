<?php

namespace App\Support;

/**
 * Nominal labels must not repeat the game name: "5 Diamond", not
 * "Free Fire 5 Diamond". Only a leading game name is removed, and only when a
 * meaningful label remains, so a clean label is never emptied or truncated.
 */
final class GameNamePrefix
{
    public static function strip(string $label, ?string ...$gameNames): string
    {
        $trimmed = trim($label);
        $candidates = array_unique(array_filter(
            array_map(fn (?string $game): string => trim((string) $game), $gameNames),
            fn (string $game): bool => $game !== '',
        ));
        // Longest first, so "Free Fire MAX" wins over "Free Fire".
        usort($candidates, fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        foreach ($candidates as $game) {
            // A game name glued to another word ("Free FireMAX") is not a prefix.
            if (preg_match('/^'.preg_quote($game, '/').'(?![\pL\pN])/ui', $trimmed, $match) !== 1) {
                continue;
            }

            $rest = trim(ltrim(mb_substr($trimmed, mb_strlen($match[0])), "-–—:|/, \t"));
            if (preg_match('/[\pL\pN]/u', $rest) === 1) {
                return $rest;
            }
        }

        return $label;
    }
}
