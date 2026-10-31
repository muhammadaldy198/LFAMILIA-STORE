<?php

namespace App\Services\Fulfillment;

use App\Models\Product;
use Illuminate\Support\Collection;

class DigiflazzDestinationTemplate
{
    /**
     * Infer only formats that are known for the specific game. An arbitrary
     * multi-field product must keep its administrator-confirmed template.
     *
     * @param Collection<int, mixed> $fields
     */
    public function resolve(Product $product, ?string $stored, Collection $fields): ?string
    {
        $template = trim((string) $stored);
        $keys = $fields->pluck('field_key')->all();

        if ($this->isMobileLegends($product)
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

    public function isMobileLegends(Product $product): bool
    {
        return in_array(strtolower(trim((string) $product->slug)), [
            'mobile-legends',
            'mobile-legends-bang-bang',
            'mobile-legend',
        ], true);
    }
}
