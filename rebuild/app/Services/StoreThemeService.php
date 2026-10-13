<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class StoreThemeService
{
    public function fields(): array
    {
        return config('store_theme');
    }

    public function defaults(): array
    {
        return array_map(fn (array $field): string => $field['default'], $this->fields());
    }

    public function sanitize(array $input): array
    {
        $colors = $this->defaults();
        foreach ($colors as $key => $default) {
            $value = $input[$key] ?? null;
            if (is_string($value) && preg_match('/^#[0-9A-Fa-f]{6}$/D', $value)) {
                $colors[$key] = strtoupper($value);
            }
        }

        return $colors;
    }

    public function saved(): array
    {
        $value = json_decode((string) DB::table('system_settings')->where('key', 'store.theme')->value('value'), true);

        return $this->sanitize(is_array($value) ? $value : []);
    }

    public function contrast(string $foreground, string $background): float
    {
        $luminance = function (string $hex): float {
            $rgb = array_map(function (string $part): float {
                $value = hexdec($part) / 255;

                return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
            }, str_split(substr($hex, 1), 2));

            return 0.2126 * $rgb[0] + 0.7152 * $rgb[1] + 0.0722 * $rgb[2];
        };
        $a = $luminance($foreground);
        $b = $luminance($background);

        return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    }
}
