<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\NicknameGameCode;
use App\Models\Product;
use App\Models\Provider;
use App\Models\ProviderMapping;
use App\Services\CatalogAudit;
use App\Services\LegacyCustomerNoTemplateCleanup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Membuat, mengubah, dan menghapus produk katalog dari panel admin.
 * Field input produk diurus AdminProductFieldsController; paket dan
 * nominalnya diurus AdminPackageController.
 */
class AdminProductController
{
    public function product(Request $request, CatalogAudit $audit): RedirectResponse
    {
        $data = $request->validate([
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'publisher' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'fulfillment_mode' => ['required', Rule::in(['AUTO_PROVIDER', 'MANUAL'])],
            'customer_no_template' => ['nullable', 'string', 'max:500'],
            'manual_instructions' => ['nullable', 'string', 'max:5000'],
            'manual_open_time' => ['nullable', 'regex:/^([01]\\d|2[0-3]):[0-5]\\d$/'],
            'manual_close_time' => ['nullable', 'regex:/^([01]\\d|2[0-3]):[0-5]\\d$/'],
            'manual_timezone' => ['nullable', Rule::in(['Asia/Jakarta', 'Asia/Makassar', 'Asia/Jayapura'])],
            // Manual legacy requests may still provide this field; Digiflazz
            // never uses it to calculate a selling price.
            'margin_percent' => ['sometimes', 'numeric', 'min:0', 'max:1000', 'decimal:0,4'],
            'sort_order' => ['required', 'integer', 'min:0'],
            // Satu formulir boleh membawa banyak baris nominal untuk produk manual.
            'packages' => ['sometimes', 'array', 'max:100'],
            'packages.*.name' => ['required', 'string', 'max:255'],
            'packages.*.code' => ['nullable', 'string', 'max:80', 'regex:/^[A-Za-z0-9_-]+$/'],
            'packages.*.note' => ['nullable', 'string', 'max:80'],
            'packages.*.group_name' => ['nullable', 'string', 'max:120'],
            'packages.*.nominal_value' => ['nullable', 'integer', 'min:0'],
            'packages.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'packages.*.cost_idr' => ['required', 'integer', 'min:0', 'max:1000000000'],
        ]);
        $rows = collect($data['packages'] ?? [])->values();
        unset($data['packages']);
        $data['margin_percent'] = $data['fulfillment_mode'] === 'AUTO_PROVIDER' ? 12 : ($data['margin_percent'] ?? 0);
        $data['slug'] = ($data['slug'] ?? null) ?: Str::slug($data['name']);
        if (! $data['slug']) {
            throw ValidationException::withMessages(['name' => 'Nama produk tidak valid.']);
        }
        if (Product::where('slug', $data['slug'])->exists()) {
            throw ValidationException::withMessages(['slug' => 'Alamat produk sudah dipakai.']);
        }
        if ($data['fulfillment_mode'] !== 'MANUAL') {
            $data['manual_instructions'] = null;
            $data['manual_open_time'] = null;
            $data['manual_close_time'] = null;
            $data['manual_timezone'] = 'Asia/Jakarta';
        } else {
            $data['manual_timezone'] = ($data['manual_timezone'] ?? null) ?: 'Asia/Jakarta';
        }

        if ($rows->isNotEmpty() && $data['fulfillment_mode'] !== 'MANUAL') {
            throw ValidationException::withMessages([
                'packages' => 'Baris nominal satu formulir hanya untuk produk manual. Produk otomatis mengambil nominal dari impor Digiflazz.',
            ]);
        }

        $duplicateNominals = $rows->whereNotNull('nominal_value')
            ->groupBy(fn (array $row): string => (string) $row['nominal_value'])
            ->filter(fn ($group): bool => $group->count() > 1)
            ->keys();
        if ($duplicateNominals->isNotEmpty()) {
            throw ValidationException::withMessages([
                'packages' => 'Nominal '.$duplicateNominals->implode(', ').' muncul lebih dari sekali di formulir ini. Gabungkan atau ubah nominalnya.',
            ]);
        }

        DB::transaction(function () use ($request, $data, $rows, $audit): void {
            $product = Product::create([...$data, 'is_active' => false]);
            $audit->record($request, 'catalog.product.created', 'product', $product->id, null, $product->toArray());

            if ($rows->isEmpty()) {
                return;
            }

            // Produk manual: modal toko tiap nominal disimpan pada mapping provider
            // MANUAL tanpa SKU provider asli, jadi Digiflazz tidak tersentuh.
            $manualProvider = Provider::where('code', 'MANUAL')->firstOrFail();
            $usedCodes = [];
            foreach ($rows as $index => $row) {
                $code = trim((string) ($row['code'] ?? '')) ?: Str::upper(Str::slug($row['name'], '_'));
                $code = Str::limit($code !== '' ? $code : 'NOMINAL', 80, '');
                $base = $code;
                $suffix = 2;
                while (in_array($code, $usedCodes, true)) {
                    $code = Str::limit($base, 76, '').'_'.$suffix;
                    $suffix++;
                }
                $usedCodes[] = $code;

                $package = $product->packages()->create([
                    'code' => $code,
                    'name' => $row['name'],
                    'note' => $row['note'] ?? null,
                    'group_name' => $row['group_name'] ?? null,
                    'nominal_value' => $row['nominal_value'] ?? null,
                    'sort_order' => $row['sort_order'] ?? $index,
                    // Nominal manual memakai margin persen produk; harga jual
                    // dihitung dari modal toko, bukan kolom markup legacy.
                    'pricing_mode' => 'PERCENT', 'margin_percent' => $data['margin_percent'],
                    'is_active' => false,
                ]);
                // Modal toko tidak di-uplift: uplift hanya berlaku saat impor provider.
                $mapping = ProviderMapping::create([
                    'product_package_id' => $package->id,
                    'provider_id' => $manualProvider->id,
                    'external_sku' => null,
                    'cost_idr' => $row['cost_idr'],
                    'store_cost_idr' => $row['cost_idr'],
                    'max_price_idr' => null,
                    'priority' => 0,
                    'is_active' => false,
                ]);
                $audit->record($request, 'catalog.mapping.created', 'provider_mapping', $mapping->id, null, $mapping->toArray());
                $audit->record($request, 'catalog.package.created', 'product_package', $package->id, null, $package->toArray());
            }
        });

        return back();
    }

    public function updateProduct(Request $request, Product $product, CatalogAudit $audit, LegacyCustomerNoTemplateCleanup $legacyTemplates): RedirectResponse
    {
        $data = $request->validate([
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['sometimes', 'required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('products', 'slug')->ignore($product->id)],
            'publisher' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'fulfillment_mode' => ['sometimes', Rule::in(['AUTO_PROVIDER', 'MANUAL'])],
            'customer_no_template' => ['sometimes', 'nullable', 'string', 'max:500'],
            'initials' => ['nullable', 'string', 'max:4'],
            'accent_color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'instant' => ['sometimes', 'boolean'],
            'package_tabs_enabled' => ['sometimes', 'boolean'],
            'package_tabs' => ['sometimes', 'array', 'max:20'],
            'package_tabs.*' => ['required', 'string', 'min:1', 'max:60'],
            'manual_instructions' => ['nullable', 'string', 'max:5000'],
            'manual_open_time' => ['nullable', 'regex:/^([01]\\d|2[0-3]):[0-5]\\d$/'],
            'manual_close_time' => ['nullable', 'regex:/^([01]\\d|2[0-3]):[0-5]\\d$/'],
            'manual_timezone' => ['nullable', Rule::in(['Asia/Jakarta', 'Asia/Makassar', 'Asia/Jayapura'])],
            // Kept for manual legacy API compatibility only.
            'margin_percent' => ['sometimes', 'numeric', 'min:0', 'max:1000', 'decimal:0,4'],
            'sort_order' => ['required', 'integer', 'min:0'],
            'is_active' => ['required', 'boolean'],
            'cost_uplift_percent' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1000', 'decimal:0,4'],
            'nickname_check_enabled' => ['sometimes', 'boolean'],
            'nickname_game_code' => ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9][a-z0-9-]*$/'],
            'nickname_user_field_key' => ['nullable', 'string', 'max:80', 'regex:/^[a-z][a-z0-9_]*$/'],
            'nickname_server_field_key' => ['nullable', 'string', 'max:80', 'regex:/^[a-z][a-z0-9_]*$/'],
            'nickname_required_region' => ['nullable', 'string', 'size:2', 'regex:/^[A-Z]{2}$/'],
        ]);
        $templateSubmitted = array_key_exists('customer_no_template', $data);
        $template = trim((string) ($templateSubmitted
            ? $data['customer_no_template']
            : ($product->customer_no_template ?? '')));
        $fieldKeys = $product->fields()->pluck('field_key')->all();
        preg_match_all('/\{\{([^}]+)\}\}/', $template, $templateMatches);
        foreach ($templateMatches[1] ?? [] as $fieldKey) {
            if (! preg_match('/^[a-z][a-z0-9_]*$/', $fieldKey) || ! in_array($fieldKey, $fieldKeys, true)) {
                throw ValidationException::withMessages(['customer_no_template' => 'Placeholder {{'.$fieldKey.'}} tidak cocok dengan field produk.']);
            }
        }
        $requiredFieldKeys = $product->fields()->where('is_required', true)->pluck('field_key')->all();
        $referencedKeys = $templateMatches[1] ?? [];
        if (preg_match('/\{(?!\{)|(?<!\})\}/', preg_replace('/\{\{[a-z][a-z0-9_]*\}\}/', '', $template) ?? $template)) {
            throw ValidationException::withMessages(['customer_no_template' => 'Format tujuan mengandung kurung kurawal yang tidak berpasangan.']);
        }
        $hasDigiflazzMapping = DB::table('provider_mappings as mappings')
            ->join('providers', 'providers.id', '=', 'mappings.provider_id')
            ->join('product_packages', 'product_packages.id', '=', 'mappings.product_package_id')
            ->where('providers.code', 'DIGIFLAZZ')
            ->where('product_packages.product_id', $product->id)
            ->exists();
        if ($templateSubmitted && $data['is_active']
            && ($data['fulfillment_mode'] ?? $product->fulfillment_mode) === 'AUTO_PROVIDER'
            && $hasDigiflazzMapping
            && (($template !== '' && $referencedKeys === [])
                || ($product->fields()->count() > 1 && $template === '')
                || ($template !== '' && array_diff($requiredFieldKeys, $referencedKeys)))) {
            throw ValidationException::withMessages(['customer_no_template' => 'Format tujuan harus mencantumkan semua kolom wajib produk dengan placeholder {{field_key}}.']);
        }
        if ($data['is_active'] && ! $product->is_active
            && ($data['fulfillment_mode'] ?? $product->fulfillment_mode) === 'AUTO_PROVIDER'
            && $hasDigiflazzMapping) {
            $mappingConfigs = DB::table('provider_mappings as mappings')
                ->join('providers', 'providers.id', '=', 'mappings.provider_id')
                ->join('product_packages', 'product_packages.id', '=', 'mappings.product_package_id')
                ->where('product_packages.product_id', $product->id)
                ->where('providers.code', 'DIGIFLAZZ')
                ->where('mappings.is_active', true)
                ->pluck('mappings.fulfillment_config');
            foreach ($mappingConfigs as $config) {
                $config = is_string($config) ? (json_decode($config, true) ?: []) : (array) $config;
                $effectiveTemplate = $templateSubmitted
                    ? $template
                    : ($template !== '' ? $template : trim((string) data_get($config, 'customer_no_template')));
                preg_match_all('/\{\{([^}]+)\}\}/', $effectiveTemplate, $effectiveMatches);
                $effectiveReferences = $effectiveMatches[1] ?? [];
                if (($effectiveTemplate !== '' && array_diff($effectiveReferences, $fieldKeys))
                    || ($effectiveTemplate !== '' && $effectiveReferences === [])
                    || (count($fieldKeys) > 1 && $effectiveTemplate === '')
                    || ($effectiveTemplate !== '' && array_diff($requiredFieldKeys, $effectiveReferences))) {
                    throw ValidationException::withMessages(['customer_no_template' => 'Produk otomatis belum memiliki format tujuan yang valid untuk semua kolom wajib.']);
                }
            }
        }
        if ($templateSubmitted) {
            $data['customer_no_template'] = $template !== '' ? $template : null;
        }
        $data['slug'] ??= $product->slug;
        $data['fulfillment_mode'] ??= $product->fulfillment_mode;
        $data['package_tabs_enabled'] ??= $product->package_tabs_enabled;
        $data['package_tabs'] ??= $product->package_tabs ?? [];

        $tabs = collect($data['package_tabs'])->map(fn ($tab) => trim((string) $tab))
            ->filter(fn (string $tab): bool => strlen($tab) > 0)->values();
        // Inactive tab navigation stores group priority here too. Group labels
        // that differ only by case are valid; reject them only for actual tabs.
        if ($data['package_tabs_enabled'] && $tabs->map(fn (string $tab): string => mb_strtolower($tab))->uniqueStrict()->count() !== $tabs->count()) {
            throw ValidationException::withMessages(['package_tabs' => 'Nama tab nominal tidak boleh duplikat.']);
        }
        $data['package_tabs'] = $tabs->all();

        if ($data['package_tabs_enabled']) {
            if ($tabs->isEmpty()) {
                throw ValidationException::withMessages(['package_tabs' => 'Tambahkan minimal satu nama tab nominal.']);
            }
            $groups = $product->packages()->pluck('group_name')->map(fn ($group) => trim((string) $group));
            if ($groups->contains('')) {
                throw ValidationException::withMessages(['package_tabs' => 'Semua nominal harus memiliki grup sebelum tab nominal diaktifkan.']);
            }
            $missing = $groups->filter(fn (string $group): bool => ! $tabs->containsStrict($group))->uniqueStrict()->values();
            if ($missing->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'package_tabs' => 'Grup nominal belum tercantum sebagai tab: '.$missing->implode(', ').'.',
                ]);
            }
        }

        if ($data['is_active'] && ! $product->packages()->exists()) {
            throw ValidationException::withMessages([
                'is_active' => 'Tambahkan minimal satu nominal sebelum produk diaktifkan.',
            ]);
        }

        if ($data['fulfillment_mode'] !== $product->fulfillment_mode
            && ($product->packages()->exists() || DB::table('orders')->where('product_id', $product->id)->exists())) {
            throw ValidationException::withMessages([
                'fulfillment_mode' => 'Jenis penanganan hanya dapat diubah sebelum produk memiliki nominal atau riwayat pesanan.',
            ]);
        }

        $categorySlug = (string) Category::whereKey($data['category_id'])->value('slug');
        if ($categorySlug === 'voucher' && ($data['nickname_check_enabled'] ?? false) === true) {
            throw ValidationException::withMessages([
                'nickname_check_enabled' => 'Produk voucher tidak memakai Kode Game Nickname.',
            ]);
        }

        $requiredRegion = trim((string) (array_key_exists('nickname_required_region', $data)
            ? $data['nickname_required_region'] : $product->nickname_required_region));
        if ($requiredRegion !== '') {
            $regionGame = NicknameGameCode::active()->nicknameSupported()
                ->where('code', $data['nickname_game_code'] ?? $product->nickname_game_code)->first();
            if (! ($data['nickname_check_enabled'] ?? $product->nickname_check_enabled) || ! $regionGame?->requires_region_check) {
                throw ValidationException::withMessages([
                    'nickname_required_region' => 'Pembatasan region hanya untuk game yang mendukung pemeriksaan region dan cek nickname aktif.',
                ]);
            }
        }
        if (($data['nickname_check_enabled'] ?? false) === true) {
            $fieldKeys = $product->fields()->pluck('field_key')->all();
            if (empty($data['nickname_game_code']) || empty($data['nickname_user_field_key'])) {
                throw ValidationException::withMessages([
                    'nickname_game_code' => 'Kode game dan kolom User ID wajib dipilih ketika cek nickname aktif.',
                ]);
            }

            $nicknameGame = NicknameGameCode::active()
                ->nicknameSupported()
                ->where('code', $data['nickname_game_code'])
                ->first();
            if (! $nicknameGame) {
                throw ValidationException::withMessages([
                    'nickname_game_code' => 'Pilih game aktif yang mendukung cek nickname di menu Validasi Akun.',
                ]);
            }

            if (! in_array($data['nickname_user_field_key'], $fieldKeys, true)) {
                throw ValidationException::withMessages([
                    'nickname_user_field_key' => 'Kolom User ID harus memakai kolom data pelanggan yang tersedia.',
                ]);
            }
            if ($nicknameGame->requires_server && empty($data['nickname_server_field_key'])) {
                throw ValidationException::withMessages([
                    'nickname_server_field_key' => 'Game ini memerlukan kolom Server / Zone.',
                ]);
            }
            if (! empty($data['nickname_server_field_key'])
                && ! in_array($data['nickname_server_field_key'], $fieldKeys, true)) {
                throw ValidationException::withMessages([
                    'nickname_server_field_key' => 'Kolom Server / Zone harus memakai kolom data pelanggan yang tersedia.',
                ]);
            }
        }
        if ($data['fulfillment_mode'] !== 'MANUAL') {
            $data['manual_instructions'] = null;
            $data['manual_open_time'] = null;
            $data['manual_close_time'] = null;
            $data['manual_timezone'] = 'Asia/Jakarta';
        } else {
            $data['manual_timezone'] = ($data['manual_timezone'] ?? null) ?: 'Asia/Jakarta';
        }

        DB::transaction(function () use ($request, $product, $data, $audit, $legacyTemplates): void {
            $before = $product->toArray();
            $product->update($data);
            if (array_key_exists('customer_no_template', $data) && $data['customer_no_template'] === null) {
                $legacyTemplates->clearForProduct($product->id);
            }
            $audit->record($request, 'catalog.product.updated', 'product', $product->id, $before, $product->toArray());
        });

        return back();
    }

    public function destroyProduct(Request $request, Product $product, CatalogAudit $audit): RedirectResponse
    {
        if (DB::table('orders')->where('product_id', $product->id)->exists()) {
            throw ValidationException::withMessages([
                'product' => 'Produk memiliki riwayat pesanan dan tidak boleh dihapus. Nonaktifkan produk jika tidak ingin ditampilkan.',
            ]);
        }

        DB::transaction(function () use ($request, $product, $audit): void {
            $before = $product->toArray();
            $packages = $product->packages()->with('mappings')->get();
            foreach ($packages as $package) {
                $package->clearMediaCollection('image');
                $package->mappings()->delete();
                $package->delete();
            }
            $product->clearMediaCollection('image');
            $product->clearMediaCollection('banner');
            $id = $product->id;
            $product->delete();
            $audit->record($request, 'catalog.product.deleted', 'product', $id, $before, []);
        });

        return redirect()->route('admin.catalog.index')->with('status', 'Produk berhasil dihapus.');
    }
}
