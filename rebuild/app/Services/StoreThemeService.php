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

    public function tokens(): array
    {
        $colors = $this->saved();
        // Keep the approved pale-blue checkout gradient at the default palette.
        // A primary edit shifts both stops by the same RGB difference.
        $primary = array_map('hexdec', str_split(substr($colors['primary'], 1), 2));
        foreach (['action-start' => [49, 43, 0], 'action-end' => [18, 3, -21]] as $key => $offsets) {
            $colors[$key] = sprintf('#%02X%02X%02X', ...array_map(
                fn (int $value, int $offset): int => max(0, min(255, $value + $offset)),
                $primary, $offsets,
            ));
        }

        $variants = [
            'card-start' => ['card', '#253147'], 'card-mid' => ['card', '#1A2436'], 'card-end' => ['card', '#171F30'],
            'card-selected-start' => ['card', '#253E59'], 'card-selected-mid' => ['card', '#21334C'], 'card-selected-end' => ['card', '#253645'],
            'header-start' => ['panel', '#2B3645'], 'header-end' => ['panel', '#293443'],
            'modal-start' => ['surface', '#202D46'], 'modal-end' => ['surface', '#101929'],
            'modal-rows' => ['surface', '#0B1627'], 'border-light' => ['border', '#A8BEDC'],
        ];
        $defaults = $this->defaults();
        foreach ($variants as $key => [$role, $reference]) {
            $base = array_map('hexdec', str_split(substr($colors[$role], 1), 2));
            $default = array_map('hexdec', str_split(substr($defaults[$role], 1), 2));
            $target = array_map('hexdec', str_split(substr($reference, 1), 2));
            $colors[$key] = sprintf('#%02X%02X%02X', ...array_map(
                fn (int $value, int $original, int $desired): int => max(0, min(255, $value + $desired - $original)),
                $base, $default, $target,
            ));
        }

        return $colors;
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
