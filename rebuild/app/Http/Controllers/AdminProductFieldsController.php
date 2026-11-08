<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductInputField;
use App\Services\CatalogAudit;
use App\Services\Fulfillment\DigiflazzDestinationTemplate;
use App\Services\LegacyCustomerNoTemplateCleanup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Menyimpan definisi field input sebuah produk dari panel admin — apa saja
 * yang harus diisi pembeli — lalu membersihkan template customer_no warisan
 * dari mapping provider produk itu.
 */
class AdminProductFieldsController
{
    /**
     * Validate catalog field definitions; password input lengths are checked at checkout.
     */
    public function fields(Request $request, Product $product, CatalogAudit $audit, LegacyCustomerNoTemplateCleanup $legacyTemplates): RedirectResponse
    {
        $data = $request->validate([
            'fields' => ['present', 'array', 'max:20'],
            'customer_no_template' => ['sometimes', 'nullable', 'string', 'max:500'],
            'fields.*.field_key' => ['required', 'string', 'max:80', 'regex:/^[a-z][a-z0-9_]*$/', 'distinct'],
            'fields.*.label' => ['required', 'string', 'max:255'],
            'fields.*.placeholder' => ['nullable', 'string', 'max:255'],
            'fields.*.type' => ['required', Rule::in(['text', 'tel', 'email', 'select', 'password'])],
            'fields.*.options' => ['nullable', 'array', 'max:50'],
            'fields.*.options.*.value' => ['required', 'string', 'max:100'],
            'fields.*.options.*.label' => ['required', 'string', 'max:100'],
            'fields.*.is_required' => ['required', 'boolean'],
        ]);
        foreach ($data['fields'] as $index => &$field) {
            if ($field['type'] !== 'select') {
                $field['options'] = null;

                continue;
            }
            $options = $field['options'] ?? [];
            if ($options === []) {
                throw ValidationException::withMessages(['fields.'.$index.'.options' => 'Tambahkan minimal satu pilihan.']);
            }
            $normalized = [];
            $seen = [];
            foreach ($options as $option) {
                $value = trim((string) ($option['value'] ?? ''));
                $label = trim((string) ($option['label'] ?? ''));
                if ($value === '' || $label === '' || in_array($value, $seen, true)
                    || str_contains($value, '{{') || str_contains($value, '}}')) {
                    throw ValidationException::withMessages(['fields.'.$index.'.options' => 'Nilai dan label pilihan harus terisi, unik, dan tanpa placeholder template.']);
                }
                $seen[] = $value;
                $normalized[] = ['value' => $value, 'label' => $label];
            }
            $field['options'] = $normalized;
        }
        unset($field);

        $keys = array_column($data['fields'], 'field_key');
        $template = (string) (app(DigiflazzDestinationTemplate::class)->resolve(
            (string) $product->slug,
            array_key_exists('customer_no_template', $data)
                ? $data['customer_no_template'] : $product->customer_no_template,
            $keys
        ) ?? '');
        preg_match_all('/\{\{([^}]+)\}\}/', $template, $matches);
        $templateReferences = $matches[1] ?? [];
        $references = $templateReferences;
        $nicknameUpdates = [];
        if ($product->nickname_check_enabled) {
            // Nickname lookup is advisory: stale checker keys must never block
            // the real customer fields or the Digiflazz delivery template.
            foreach (['nickname_user_field_key' => 'user_id', 'nickname_server_field_key' => 'zone_id'] as $setting => $canonical) {
                $old = trim((string) $product->{$setting});
                if ($old !== '' && ! in_array($old, $keys, true)) {
                    if (in_array($canonical, $keys, true)) {
                        $nicknameUpdates[$setting] = $canonical;
                    } else {
                        $nicknameUpdates['nickname_check_enabled'] = false;
                    }
                }
            }
        }
        foreach ($matches[1] ?? [] as $fieldKey) {
            if (! preg_match('/^[a-z][a-z0-9_]*$/', $fieldKey) || ! in_array($fieldKey, $keys, true)) {
                throw ValidationException::withMessages(['customer_no_template' => 'Placeholder {{'.$fieldKey.'}} tidak cocok dengan field produk.']);
            }
        }
        if (preg_match('/\{(?!\{)|(?<!\})\}/', preg_replace('/\{\{[a-z][a-z0-9_]*\}\}/', '', $template) ?? $template)) {
            throw ValidationException::withMessages(['customer_no_template' => 'Format tujuan mengandung kurung kurawal yang tidak berpasangan.']);
        }
        if (! array_key_exists('customer_no_template', $data)) {
            $packageIds = $product->packages()->pluck('id');
            foreach (DB::table('provider_mappings as mappings')
                ->join('providers', 'providers.id', '=', 'mappings.provider_id')
                ->where('providers.code', 'DIGIFLAZZ')
                ->whereIn('mappings.product_package_id', $packageIds)
                ->pluck('mappings.fulfillment_config') as $config) {
                $config = is_string($config) ? (json_decode($config, true) ?: []) : (array) $config;
                preg_match_all('/\{\{([^}]+)\}\}/', (string) data_get($config, 'customer_no_template'), $mappingMatches);
                $references = array_merge($references, $mappingMatches[1] ?? []);
            }
        }
        if (array_diff($references, $keys)) {
            throw ValidationException::withMessages(['fields' => 'Format pengiriman lama masih merujuk kolom yang tidak ada. Periksa format tujuan produk.']);
        }
        $requiredKeys = collect($data['fields'])->filter(fn (array $field): bool => (bool) $field['is_required'])
            ->pluck('field_key')->all();
        $hasDigiflazzMapping = DB::table('provider_mappings as mappings')
            ->join('providers', 'providers.id', '=', 'mappings.provider_id')
            ->join('product_packages', 'product_packages.id', '=', 'mappings.product_package_id')
            ->where('providers.code', 'DIGIFLAZZ')
            ->where('product_packages.product_id', $product->id)
            ->exists();
        if ($product->is_active && $product->fulfillment_mode === 'AUTO_PROVIDER' && $hasDigiflazzMapping
            && (($template !== '' && $templateReferences === [])
                || (count($data['fields']) > 1 && $template === '')
                || ($requiredKeys !== [] && array_diff($requiredKeys, $templateReferences)))) {
            throw ValidationException::withMessages(['customer_no_template' => 'Format tujuan harus memakai placeholder kolom produk dan mencantumkan semua kolom wajib.']);
        }
        DB::transaction(function () use ($request, $product, $data, $audit, $template, $nicknameUpdates, $legacyTemplates): void {
            $before = [
                'fields' => $product->fields()->get()->toArray(),
                'customer_no_template' => $product->customer_no_template,
                'nickname_check_enabled' => $product->nickname_check_enabled,
                'nickname_user_field_key' => $product->nickname_user_field_key,
                'nickname_server_field_key' => $product->nickname_server_field_key,
            ];
            $product->forceFill([
                'customer_no_template' => $template !== '' ? $template : null,
                ...$nicknameUpdates,
            ])->save();
            if (array_key_exists('customer_no_template', $data)) {
                $legacyTemplates->clearForProduct($product->id);
            }
            $product->refresh();
            $product->fields()->delete();
            foreach ($data['fields'] as $index => $field) {
                ProductInputField::create([
                    ...$field, 'product_id' => $product->id, 'sort_order' => $index,
                ]);
            }
            $audit->record($request, 'catalog.fields.updated', 'product', $product->id,
                $before, [
                    'fields' => $product->fields()->get()->toArray(),
                    'customer_no_template' => $product->customer_no_template,
                    'nickname_check_enabled' => $product->nickname_check_enabled,
                    'nickname_user_field_key' => $product->nickname_user_field_key,
                    'nickname_server_field_key' => $product->nickname_server_field_key,
                ]);
        });

        return back();
    }
}
