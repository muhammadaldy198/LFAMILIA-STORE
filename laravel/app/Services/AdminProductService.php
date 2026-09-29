<?php

namespace App\Services;

use App\Support\NominalLabel;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AdminProductService
{
    /** @return array<int,array<string,mixed>> */
    public function all(): array
    {
        $products = DB::table('products')->orderBy('sort_order')->orderBy('name')->get();
        $packages = DB::table('product_packages')->orderBy('sort_order')->orderBy('id')->get()->groupBy('product_id');
        $notices = DB::table('product_notices')->orderBy('sort_order')->orderBy('id')->get()->groupBy('product_id');

        return $products->map(function ($product) use ($packages, $notices): array {
            return [
                'dbId' => (int) $product->id,
                'slug' => (string) $product->slug,
                'name' => (string) $product->name,
                'publisher' => (string) $product->publisher,
                'category' => (string) $product->category,
                'imageUrl' => $product->image_url,
                'bannerUrl' => $product->banner_url ?: $product->image_url,
                'description' => $product->description,
                'initials' => (string) $product->initials,
                'accent' => (string) $product->accent,
                'inputLabel' => (string) $product->input_label,
                'inputPlaceholder' => (string) $product->input_placeholder,
                'inputFields' => $this->jsonArray($product->input_fields_json),
                'nicknameRequired' => trim((string) $product->nickname_game_code) !== '',
                'needsServer' => (bool) $product->needs_server,
                'popular' => (bool) $product->popular,
                'instant' => (bool) $product->instant,
                'fulfillmentType' => (string) $product->fulfillment_type,
                'targetTemplate' => (string) $product->target_template,
                'manualInstructions' => $product->manual_instructions,
                'manualOpenTime' => $product->manual_open_time,
                'manualCloseTime' => $product->manual_close_time,
                'manualTimezone' => $product->manual_timezone ?: 'Asia/Jakarta',
                'packageTabsEnabled' => (bool) $product->package_tabs_enabled,
                'packageTabs' => $this->stringArray($product->package_tabs_json),
                'isActive' => (bool) $product->is_active,
                'sortOrder' => (int) $product->sort_order,
                'packages' => $packages->get($product->id, collect())->map(fn ($row) => [
                    'dbId' => (int) $row->id,
                    'id' => (string) $row->sku,
                    'label' => NominalLabel::clean((string) $product->name, (string) $row->label),
                    'price' => (int) $row->price,
                    'note' => $row->note,
                    'group' => $row->package_group,
                    'imageUrl' => $row->image_url,
                    'providerCode' => $row->provider_code,
                    'providerSku' => $row->provider_sku,
                    'supplierPrice' => $row->supplier_price === null ? null : (int) $row->supplier_price,
                    'providerMaxPrice' => $row->provider_max_price === null ? null : (int) $row->provider_max_price,
                    'pricingMode' => $row->pricing_mode ?: 'auto',
                    'marginType' => $row->margin_type ?: 'fixed',
                    'marginValue' => (int) ($row->margin_value ?? 0),
                    'isActive' => (bool) $row->is_active,
                    'sortOrder' => (int) $row->sort_order,
                ])->values()->all(),
                'notices' => $notices->get($product->id, collect())->map(fn ($row) => [
                    'id' => (int) $row->id,
                    'title' => (string) $row->title,
                    'body' => (string) $row->body,
                    'isActive' => (bool) $row->is_active,
                    'sortOrder' => (int) $row->sort_order,
                ])->values()->all(),
            ];
        })->all();
    }

    /** @param array<string,mixed> $input */
    public function save(array $input, ?int $productId = null): int
    {
        $this->validateContract($input);

        return DB::transaction(function () use ($input, $productId): int {
            $existingProduct = $productId
                ? DB::table('products')->where('id', $productId)->lockForUpdate()->first()
                : null;

            if ($productId && !$existingProduct) {
                throw new RuntimeException('Produk tidak ditemukan.');
            }

            $duplicateSlug = DB::table('products')->where('slug', $input['slug']);
            if ($productId) {
                $duplicateSlug->where('id', '<>', $productId);
            }
            if ($duplicateSlug->exists()) {
                throw new RuntimeException('Slug produk sudah digunakan.');
            }

            $values = [
                'slug' => $input['slug'],
                'name' => trim($input['name']),
                'publisher' => trim((string) ($input['publisher'] ?? '')),
                'category' => $input['category'],
                'image_url' => trim((string) ($input['imageUrl'] ?? '')) ?: null,
                'banner_url' => trim((string) ($input['bannerUrl'] ?? '')) ?: null,
                'description' => trim((string) ($input['description'] ?? '')) ?: null,
                'initials' => trim($input['initials']),
                'accent' => trim($input['accent']),
                'input_label' => trim($input['inputLabel']),
                'input_placeholder' => trim($input['inputPlaceholder']),
                'input_fields_json' => json_encode($input['inputFields'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'needs_server' => $input['needsServer'] ? 1 : 0,
                'popular' => $input['popular'] ? 1 : 0,
                'instant' => $input['instant'] ? 1 : 0,
                'fulfillment_type' => $input['fulfillmentType'],
                'target_template' => $input['targetTemplate'],
                'manual_instructions' => trim((string) ($input['manualInstructions'] ?? '')) ?: null,
                'manual_open_time' => $input['manualOpenTime'] ?: null,
                'manual_close_time' => $input['manualCloseTime'] ?: null,
                'manual_timezone' => $input['manualTimezone'] ?: 'Asia/Jakarta',
                'package_tabs_enabled' => $input['packageTabsEnabled'] ? 1 : 0,
                'package_tabs_json' => json_encode(array_values($input['packageTabs']), JSON_UNESCAPED_SLASHES),
                'is_active' => $input['isActive'] ? 1 : 0,
                'sort_order' => (int) $input['sortOrder'],
                'updated_at' => now(),
            ];

            if ($existingProduct) {
                DB::table('products')->where('id', $productId)->update($values);
                $id = (int) $productId;
            } else {
                $id = (int) DB::table('products')->insertGetId([
                    ...$values,
                    'nickname_game_code' => null,
                    'created_at' => now(),
                ]);
            }

            $existingPackages = DB::table('product_packages')
                ->where('product_id', $id)
                ->lockForUpdate()
                ->get()
                ->keyBy('sku');

            $submittedSkus = [];
            foreach ($input['packages'] as $index => $package) {
                $sku = trim((string) $package['id']);
                $submittedSkus[] = $sku;

                $foreignOwner = DB::table('product_packages')
                    ->where('sku', $sku)
                    ->where('product_id', '<>', $id)
                    ->exists();
                if ($foreignOwner) {
                    throw new RuntimeException('SKU '.$sku.' sudah digunakan produk lain.');
                }

                $stored = $existingPackages->get($sku);
                $providerCode = trim((string) ($package['providerCode'] ?? '')) ?: null;
                $providerSku = trim((string) ($package['providerSku'] ?? '')) ?: null;

                $packageValues = [
                    'label' => NominalLabel::clean(trim((string) $input['name']), trim((string) $package['label'])),
                    'note' => trim((string) ($package['note'] ?? '')) ?: null,
                    'package_group' => trim((string) ($package['group'] ?? '')) ?: null,
                    'image_url' => trim((string) ($package['imageUrl'] ?? '')) ?: null,
                    'provider_code' => $providerCode,
                    'provider_sku' => $providerSku,
                    'is_active' => $package['isActive'] ? 1 : 0,
                    'sort_order' => (int) ($package['sortOrder'] ?? $index),
                    'updated_at' => now(),
                ];

                if ($providerCode === 'digiflazz') {
                    if ($stored && strtolower(trim((string) $stored->provider_code)) === 'digiflazz') {
                        $packageValues += [
                            'price' => (int) $stored->price,
                            'supplier_price' => $stored->supplier_price,
                            'provider_max_price' => $stored->provider_max_price,
                            'pricing_mode' => 'auto',
                            'margin_type' => $stored->margin_type ?: 'fixed',
                            'margin_value' => (int) ($stored->margin_value ?? 0),
                        ];
                    } else {
                        $packageValues += [
                            'price' => (int) $package['price'],
                            'supplier_price' => null,
                            'provider_max_price' => null,
                            'pricing_mode' => 'auto',
                            'margin_type' => 'fixed',
                            'margin_value' => 0,
                        ];
                    }
                } else {
                    $packageValues += [
                        'price' => (int) $package['price'],
                        'supplier_price' => null,
                        'provider_max_price' => null,
                        'pricing_mode' => $package['pricingMode'] ?? 'manual',
                        'margin_type' => $package['marginType'] ?? 'fixed',
                        'margin_value' => (int) ($package['marginValue'] ?? 0),
                    ];
                }

                if ($stored) {
                    DB::table('product_packages')->where('id', $stored->id)->update($packageValues);
                } else {
                    DB::table('product_packages')->insert([
                        'product_id' => $id,
                        'sku' => $sku,
                        ...$packageValues,
                        'created_at' => now(),
                    ]);
                }
            }

            $removed = DB::table('product_packages')
                ->where('product_id', $id)
                ->when($submittedSkus !== [], fn ($query) => $query->whereNotIn('sku', $submittedSkus))
                ->pluck('id')
                ->all();
            if ($submittedSkus === []) {
                $removed = DB::table('product_packages')->where('product_id', $id)->pluck('id')->all();
            }

            if ($removed !== []) {
                DB::table('digiflazz_seller_monitor')->whereIn('package_id', $removed)->delete();
                DB::table('product_packages')->whereIn('id', $removed)->delete();
            }

            DB::table('product_notices')->where('product_id', $id)->delete();
            foreach ($input['notices'] as $index => $notice) {
                DB::table('product_notices')->insert([
                    'product_id' => $id,
                    'title' => trim((string) $notice['title']),
                    'body' => trim((string) $notice['body']),
                    'is_active' => $notice['isActive'] ? 1 : 0,
                    'sort_order' => (int) ($notice['sortOrder'] ?? $index),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return $id;
        }, 3);
    }

    public function delete(int $id): void
    {
        DB::transaction(function () use ($id): void {
            $packageIds = DB::table('product_packages')->where('product_id', $id)->pluck('id')->all();
            if ($packageIds !== []) {
                DB::table('digiflazz_seller_monitor')->whereIn('package_id', $packageIds)->delete();
            }
            DB::table('products')->where('id', $id)->delete();
        }, 3);
    }

    /** @param array<string,mixed> $input */
    private function validateContract(array $input): void
    {
        if ($input['isActive'] && count($input['packages']) === 0) {
            throw new RuntimeException('Tambahkan minimal satu nominal dari katalog sebelum mengaktifkan produk.');
        }

        $fieldIds = array_map(fn ($field) => strtolower((string) $field['id']), $input['inputFields']);
        if (count($fieldIds) !== count(array_unique($fieldIds))) {
            throw new RuntimeException('Nama kolom data pelanggan tidak boleh duplikat.');
        }

        if ($input['fulfillmentType'] === 'automatic') {
            preg_match_all('/\{\{([a-z0-9-]+)\}\}/i', (string) $input['targetTemplate'], $matches);
            $allowed = array_merge(['destination', 'server'], $fieldIds);
            foreach ($matches[1] ?? [] as $token) {
                if (!in_array(strtolower((string) $token), $allowed, true)) {
                    throw new RuntimeException('Token tujuan provider {{'.$token.'}} tidak cocok dengan kolom pelanggan.');
                }
            }
        }

        $tabs = array_map(fn ($value) => trim((string) $value), $input['packageTabs']);
        $lowerTabs = array_map('strtolower', $tabs);
        if (count($lowerTabs) !== count(array_unique($lowerTabs))) {
            throw new RuntimeException('Nama tab nominal tidak boleh duplikat.');
        }

        foreach ($input['packages'] as $package) {
            $provider = trim((string) ($package['providerCode'] ?? ''));
            $providerSku = trim((string) ($package['providerSku'] ?? ''));

            if ($provider === 'digiflazz' && $package['isActive'] && $providerSku === '') {
                throw new RuntimeException('Nominal DigiFlazz aktif wajib memiliki SKU provider.');
            }
            if ($provider === 'voucher-stock'
                && ($providerSku === '' || !preg_match('/^[a-z0-9][a-z0-9._:-]{1,99}$/', $providerSku))) {
                throw new RuntimeException('Kunci stok internal hanya boleh berisi huruf kecil, angka, titik, garis, titik dua, atau underscore.');
            }
            if ($input['packageTabsEnabled'] && !empty($package['group'])
                && !in_array(trim((string) $package['group']), $tabs, true)) {
                throw new RuntimeException('Tab nominal "'.$package['group'].'" belum dibuat pada produk ini.');
            }
        }
    }

    private function jsonArray(?string $json): array
    {
        $decoded = json_decode($json ?: '[]', true);
        return is_array($decoded) ? array_values($decoded) : [];
    }

    private function stringArray(?string $json): array
    {
        $decoded = $this->jsonArray($json);
        return array_values(array_unique(array_filter(array_map(
            fn ($value) => is_string($value) ? trim($value) : '',
            $decoded,
        ))));
    }
}
