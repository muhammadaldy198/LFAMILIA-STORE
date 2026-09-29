<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Validation\ValidationException;

class CheckoutInputValidator
{
    /**
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    public function validate(Product $product, array $input): array
    {
        $fields = $product->fields()->orderBy('sort_order')->get();
        $allowed = $fields->pluck('field_key')->all();

        foreach (array_keys($input) as $key) {
            if (! in_array($key, $allowed, true)) {
                throw ValidationException::withMessages([
                    'customer_input.'.$key => 'Field input tidak dikenal.',
                ]);
            }
        }

        $normalized = [];
        foreach ($fields as $field) {
            $raw = $input[$field->field_key] ?? null;
            if ($raw !== null && ! is_string($raw)) {
                throw ValidationException::withMessages([
                    'customer_input.'.$field->field_key => 'Format input tidak valid.',
                ]);
            }

            $value = trim((string) $raw);
            if ($field->is_required && $value === '') {
                throw ValidationException::withMessages([
                    'customer_input.'.$field->field_key => $field->label.' wajib diisi.',
                ]);
            }
            if ($value === '') {
                continue;
            }
            if (mb_strlen($value) > 255) {
                throw ValidationException::withMessages([
                    'customer_input.'.$field->field_key => $field->label.' terlalu panjang.',
                ]);
            }
            if ($field->type === 'email' && ! filter_var($value, FILTER_VALIDATE_EMAIL)) {
                throw ValidationException::withMessages([
                    'customer_input.'.$field->field_key => $field->label.' harus berupa email valid.',
                ]);
            }
            if ($field->type === 'tel' && ! preg_match('/^[0-9+().\-\s]{6,32}$/', $value)) {
                throw ValidationException::withMessages([
                    'customer_input.'.$field->field_key => $field->label.' tidak valid.',
                ]);
            }

            $normalized[$field->field_key] = $value;
        }

        return $normalized;
    }
}
