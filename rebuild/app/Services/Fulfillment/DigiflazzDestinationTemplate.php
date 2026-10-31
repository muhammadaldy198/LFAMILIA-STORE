<?php

namespace App\Services\Fulfillment;

class DigiflazzDestinationTemplate
{
    /**
     * Infer only formats that are known for the specific game. An arbitrary
     * multi-field product must keep its administrator-confirmed template.
     *
     * @param array<int, string> $keys
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

        if ($template !== '') {
            return $template;
        }

        return count($keys) === 1 ? '{{'.$keys[0].'}}' : null;
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
