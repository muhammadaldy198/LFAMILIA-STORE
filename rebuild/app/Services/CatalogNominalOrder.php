<?php

namespace App\Services;

use Illuminate\Support\Collection;

class CatalogNominalOrder
{
    public function value(string $name, string $brand = ''): ?int
    {
        if ($brand !== '' && str_starts_with(mb_strtolower($name), mb_strtolower($brand))) {
            $name = trim(mb_substr($name, mb_strlen($brand)));
        }
        if (! preg_match('/(?<![\pL\d.,])(\d+(?:[.,]\d{3})*)(?![\pL\d.,])/u', $name, $match)) {
            return null;
        }
        $digits = str_replace(['.', ','], '', $match[1]);

        return strlen($digits) < 16 ? (int) $digits : null;
    }

    public function sort(Collection $items): Collection
    {
        return $items->sort(function ($left, $right): int {
            $leftName = (string) data_get($left, 'name', data_get($left, 'product_name', ''));
            $rightName = (string) data_get($right, 'name', data_get($right, 'product_name', ''));
            $leftValue = data_get($left, 'nominal_value') ?? $this->value($leftName, (string) data_get($left, 'brand', ''));
            $rightValue = data_get($right, 'nominal_value') ?? $this->value($rightName, (string) data_get($right, 'brand', ''));
            $numeric = ($leftValue ?? PHP_INT_MAX) <=> ($rightValue ?? PHP_INT_MAX);

            return $numeric ?: strnatcasecmp($leftName, $rightName) ?: ((int) data_get($left, 'id', 0) <=> (int) data_get($right, 'id', 0));
        })->values();
    }
}
