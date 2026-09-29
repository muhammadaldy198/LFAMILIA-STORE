<?php

namespace App\Services\Fulfillment;

use Illuminate\Validation\ValidationException;

class FulfillmentTargetBuilder
{
    /**
     * @param  array<string, mixed>  $customerInput
     * @param  array<string, mixed>  $config
     */
    public function customerNo(array $customerInput, array $config): string
    {
        $values = collect($customerInput)
            ->filter(fn (mixed $value): bool => is_scalar($value) && trim((string) $value) !== '')
            ->map(fn (mixed $value): string => trim((string) $value))
            ->all();

        $template = trim((string) ($config['customer_no_template'] ?? ''));
        if ($template === '') {
            if (count($values) !== 1) {
                throw ValidationException::withMessages([
                    'fulfillment' => 'Mapping provider membutuhkan template customer_no untuk produk dengan lebih dari satu field.',
                ]);
            }

            $target = (string) array_values($values)[0];
        } else {
            $target = preg_replace_callback(
                '/\{\{([a-z][a-z0-9_]*)\}\}/',
                function (array $match) use ($values): string {
                    $key = $match[1];
                    if (! array_key_exists($key, $values)) {
                        throw ValidationException::withMessages([
                            'fulfillment' => "Field {$key} untuk customer_no tidak tersedia.",
                        ]);
                    }

                    return $values[$key];
                },
                $template
            );

            if (! is_string($target) || preg_match('/\{\{[^}]+\}\}/', $target)) {
                throw ValidationException::withMessages([
                    'fulfillment' => 'Template customer_no tidak valid.',
                ]);
            }
        }

        $target = trim($target);
        if ($target === '' || mb_strlen($target) > 100) {
            throw ValidationException::withMessages([
                'fulfillment' => 'customer_no hasil mapping tidak valid.',
            ]);
        }

        return $target;
    }
}
