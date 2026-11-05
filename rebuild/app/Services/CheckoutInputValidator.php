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
    public function validate(Product $product, array $input, bool $ignorePassword = false): array
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
            if ($field->type === 'password' && $ignorePassword) {
                continue;
            }
            $raw = $input[$field->field_key] ?? null;
            if ($raw !== null && ! is_string($raw)) {
                throw ValidationException::withMessages([
                    'customer_input.'.$field->field_key => 'Format input tidak valid.',
                ]);
            }

            $value = $field->type === 'password' ? (string) $raw : trim((string) $raw);
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
            if ($field->type === 'select' && ! in_array($value, collect($field->options ?? [])->pluck('value')->all(), true)) {
                throw ValidationException::withMessages([
                    'customer_input.'.$field->field_key => $field->label.' harus dipilih dari opsi yang tersedia.',
                ]);
            }
            if ($field->type === 'email' && ! filter_var($value, FILTER_VALIDATE_EMAIL)) {
                throw ValidationException::withMessages([
                    'customer_input.'.$field->field_key => $field->label.' harus berupa email valid.',
                ]);
            }
            if ($field->type === 'tel' && ! preg_match('/^(?=(?:[^0-9]*[0-9]){10})[0-9+().\-\s]{6,32}$/', $value)) {
                throw ValidationException::withMessages([
                    'customer_input.'.$field->field_key => $field->label.' tidak valid.',
                ]);
            }

            $normalized[$field->field_key] = $value;
        }

        return $normalized;
    }

    /**
     * Strip credentials before H2H requests and public order persistence.
     *
     * @param  array<string, string>  $input
     * @return array<string, string>
     */
    public function publicInput(Product $product, array $input): array
    {
        $passwordKeys = $product->fields()->where('type', 'password')->pluck('field_key')->all();

        return array_diff_key($input, array_flip($passwordKeys));
    }
}
