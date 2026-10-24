<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Hitung lama antrean memakai JAM LAYANAN produk, bukan jam dinding.
 * Di luar jam layanan hitungan berhenti (PRD §8.2).
 */
class ServiceHours
{
    /**
     * @param  string|null  $open  "HH:MM" jam buka (zona produk)
     * @param  string|null  $close  "HH:MM" jam tutup (zona produk)
     * @param  string|null  $timezone  zona waktu produk
     * @return int menit jam-layanan yang sudah berjalan antara $from dan $now
     */
    public static function elapsedMinutes(
        mixed $from,
        mixed $now,
        ?string $open,
        ?string $close,
        ?string $timezone,
    ): int {
        $tz = $timezone ?: 'Asia/Jakarta';
        $start = Carbon::parse($from)->setTimezone($tz);
        $end = Carbon::parse($now)->setTimezone($tz);

        if ($end->lessThanOrEqualTo($start)) {
            return 0;
        }

        // Tanpa jadwal layanan → dianggap selalu buka.
        if (! self::isTime($open) || ! self::isTime($close)) {
            return (int) $start->diffInMinutes($end);
        }

        $minutes = 0;
        $cursor = $start->copy()->startOfDay();
        $guard = 0;
        while ($cursor->lessThanOrEqualTo($end) && $guard++ < 366) {
            [$windowStart, $windowEnd] = self::window($cursor, (string) $open, (string) $close);
            $overlapStart = $windowStart->greaterThan($start) ? $windowStart : $start;
            $overlapEnd = $windowEnd->lessThan($end) ? $windowEnd : $end;
            if ($overlapEnd->greaterThan($overlapStart)) {
                $minutes += (int) $overlapStart->diffInMinutes($overlapEnd);
            }
            $cursor->addDay();
        }

        return $minutes;
    }

    /**
     * @return array{0:Carbon,1:Carbon}
     */
    private static function window(Carbon $day, string $open, string $close): array
    {
        [$openHour, $openMinute] = array_map('intval', explode(':', $open));
        [$closeHour, $closeMinute] = array_map('intval', explode(':', $close));
        $start = $day->copy()->setTime($openHour, $openMinute);
        $end = $day->copy()->setTime($closeHour, $closeMinute);
        // Jam tutup lebih awal dari jam buka = layanan lewat tengah malam.
        if ($end->lessThanOrEqualTo($start)) {
            $end->addDay();
        }

        return [$start, $end];
    }

    private static function isTime(?string $value): bool
    {
        return is_string($value) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1;
    }
}
