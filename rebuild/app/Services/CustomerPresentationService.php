<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class CustomerPresentationService
{
    public function registry(): array
    {
        return json_decode(file_get_contents(resource_path('customer-text.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    public function fields(): array
    {
        return config('customer_presentation.fields', []);
    }

    public function saved(): array
    {
        $value = DB::table('system_settings')->where('key', 'store.customer_presentation')->value('value');

        return $this->sanitize(json_decode((string) $value, true) ?: []);
    }

    public function sanitize(array $input): array
    {
        $output = ['text' => [], 'typography' => ['mobile' => [], 'desktop' => []], 'sections' => []];
        foreach ($this->registry() as $key => $definition) {
            $value = $input['text'][$key] ?? null;
            if (is_string($value) && trim($value) !== '' && mb_strlen($value) <= 5000) {
                $output['text'][$key] = $value;
            }
        }
        foreach (['mobile', 'desktop'] as $viewport) {
            foreach ($this->fields() as $field => $definition) {
                $value = $input['typography'][$viewport][$field] ?? $definition[$viewport];
                $output['typography'][$viewport][$field] = is_numeric($value)
                    ? max($definition['min'], min($definition['max'], (int) $value))
                    : $definition[$viewport];
            }
        }
        foreach (config('customer_presentation.sections', []) as $key => $label) {
            $output['sections'][$key] = ($input['sections'][$key] ?? true) !== false;
        }

        return $output;
    }
}
