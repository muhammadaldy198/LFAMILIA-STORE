<?php

namespace App\Services\Fulfillment;

class DigiflazzDestinationTemplate
{
    /**
     * Infer only formats that are known for the specific game. An arbitrary
     * multi-field product must keep its administrator-confirmed template.
     *
     * @param  array<int, string>  $keys
     */
    public function resolve(string $productSlug, ?string $stored, array $keys): ?string
    {
        $template = trim((string) $stored);

        if ($this->isMobileLegends($productSlug)
            && in_array('user_id', $keys, true)
            && in_array('zone_id', $keys, true)
            && ($template === '' || $template === '{{user_id}}({{zone_id}})')) {
            return '{{user_id}}{{zone_id}}';
        }

        if (count($keys) === 1) {
            // A field code can be renamed without needing to visit another
            // settings page. Preserve valid custom literals, but replace
            // references to keys that no longer exist.
            preg_match_all('/\{\{([^}]+)\}\}/', $template, $matches);
            $referencedKeys = $matches[1] ?? [];
            if ($template === '' || array_diff($referencedKeys, $keys)) {
                return '{{'.$keys[0].'}}';
            }
        }

        return $template !== '' ? $template : null;
    }

    public function isMobileLegends(string $productSlug): bool
    {
        return in_array(strtolower(trim($productSlug)), [
            'mobile-legends',
            'mobile-legends-bang-bang',
            'mobile-legend',
        ], true);
    }
}
