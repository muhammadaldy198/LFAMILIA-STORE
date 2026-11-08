<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductInputField;
use App\Models\ProductPackage;
use App\Models\ProductPackageGroup;
use App\Models\Provider;
use App\Models\ProviderMapping;
use App\Services\AdminAuditService;
use App\Services\CatalogNominalOrder;
use App\Services\DigiflazzAutoSources;
use App\Services\DigiflazzCatalogImport;
use App\Services\DigiflazzCatalogService;
use App\Services\Fulfillment\DigiflazzDestinationTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Impor katalog Digiflazz ke produk toko dan pemasangan sumber
 * Digiflazz ke paket, termasuk template field pelanggan bawaan hasil
 * impor. Sinkronisasi harga dan stok bukan urusan class ini.
 */
class AdminDigiflazzImportController
{
    public function import(Request $request, Product $product, DigiflazzCatalogService $service, AdminAuditService $audit): RedirectResponse
    {
        // Alasan fungsi ini belum dipecah (standar keterbacaan di AGENTS.md):
        // satu urutan impor transaksional — pilihan nominal,
        // pengelompokan, dan penyimpanan berbagi konteks produk yang
        // sama di dalam satu transaksi; memecahnya sekarang berisiko
        // keadaan setengah jalan bocor antar fungsi.
        abort_unless($product->fulfillment_mode === 'AUTO_PROVIDER', 422);
        $data = $request->validate([
            'item_ids' => ['required', 'array', 'min:1', 'max:2000'],
            'group_id' => ['sometimes', 'integer'],
            'item_ids.*' => ['required', 'integer', 'distinct', 'exists:digiflazz_catalog_items,id'],
            'margin_percent' => ['prohibited'],
            'cost_uplift_percent' => ['prohibited'],
            'publish' => ['sometimes', 'boolean'],
            'customer_no_template' => ['sometimes', 'nullable', 'string', 'max:500'],
            'auto_sources' => ['prohibited'],
        ]);
        DB::transaction(function () use ($request, $product, $service, $audit, $data): void {
            $product = $product->lockForUpdate()->findOrFail($product->id);
            $publish = (bool) ($data['publish'] ?? false);
            $targetGroup = null;
            if (isset($data['group_id'])) {
                $targetGroup = ProductPackageGroup::where('product_id', $product->id)
                    ->whereKey($data['group_id'])->first();
                if ($targetGroup === null) {
                    throw ValidationException::withMessages(['group_id' => 'Grup tujuan tidak tersedia pada produk ini.']);
                }
            }
            // Keep imports one-click: establish a product-wide input once,
            // never a different destination for each SKU/nominal.
            if ($publish && ! $product->fields()->exists()) {
                $fieldDefs = app(DigiflazzDestinationTemplate::class)->isMobileLegends($product->slug)
                    ? [
                        ['field_key' => 'user_id', 'label' => 'User ID', 'placeholder' => 'Masukkan User ID'],
                        ['field_key' => 'zone_id', 'label' => 'Zone ID', 'placeholder' => 'Masukkan Zone ID'],
                    ]
                    : [['field_key' => 'destination', 'label' => 'ID Tujuan', 'placeholder' => 'Masukkan ID tujuan sesuai produk']];
                foreach ($fieldDefs as $index => $definition) {
                    ProductInputField::create([
                        ...$definition, 'product_id' => $product->id, 'type' => 'text',
                        'is_required' => true, 'sort_order' => $index,
                    ]);
                }
            }
            $template = app(DigiflazzDestinationTemplate::class)->resolve(
                (string) $product->slug,
                array_key_exists('customer_no_template', $data)
                    ? $data['customer_no_template'] : $product->customer_no_template,
                $product->fields()->orderBy('sort_order')->pluck('field_key')->all()
            );
            // Draft imports must not weaken a destination shared by
            // already-selling nominal mappings on this product.
            $changingActiveTemplate = $template !== $product->customer_no_template
                && $product->is_active
                && $product->packages()->where('is_active', true)
                    ->whereHas('mappings', fn ($query) => $query->where('is_active', true))->exists();
            $template = $this->importCustomerTemplate($product, $template, $publish || $changingActiveTemplate);
            if ($template !== $product->customer_no_template) {
                $product->update(['customer_no_template' => $template]);
            }
            if ($publish && (! $product->category->is_active || ! Provider::where('code', 'DIGIFLAZZ')->where('is_active', true)->exists())) {
                throw ValidationException::withMessages(['publish' => 'Aktifkan kategori produk dan integrasi Digiflazz sebelum menjual.']);
            }
            $selected = DB::table('digiflazz_catalog_items')->whereIn('id', $data['item_ids'])->lockForUpdate()->get();
            $nominals = $selected->groupBy(function (object $item) use ($targetGroup): string {
                $nominal = app(CatalogNominalOrder::class)->value((string) $item->product_name, (string) $item->brand);

                return $nominal === null ? 'sku:'.$item->buyer_sku_code
                    : 'value:'.mb_strtolower((string) ($targetGroup?->name ?? $item->type)).':'.$nominal;
            });
            if ($nominals->contains(fn ($items): bool => $items->count() > 1)) {
                throw ValidationException::withMessages([
                    'item_ids' => 'Beberapa SKU yang dipilih mengarah ke nominal sama. Pilih satu SKU untuk setiap nominal.',
                ]);
            }
            foreach ($selected as $item) {
                if (! $service->sellable($item)) {
                    throw ValidationException::withMessages(['item_ids' => 'SKU tidak tersedia atau data lebih dari 24 jam. Sinkronkan dahulu.']);
                }
                if ($product->package_tabs_enabled && in_array(trim((string) ($targetGroup?->name ?? $item->type)), $product->package_tabs ?? [], true) === false) {
                    throw ValidationException::withMessages(['item_ids' => 'Grup nominal belum tersedia pada tab produk. Tambahkan tab "'.$item->type.'" dahulu.']);
                }
            }
            $sources = app(DigiflazzAutoSources::class);
            foreach ($selected as $item) {
                $sources->importGroup(
                    $product,
                    collect([$item]),
                    $template,
                    $publish,
                    '12',
                    false,
                    $request,
                    null,
                    $targetGroup?->name,
                );
            }
            $sources->orderProduct($product);
            if ($publish && ! $product->is_active) {
                $before = $product->toArray();
                $product->update(['is_active' => true]);
                $audit->record($request, 'catalog.product.updated', 'product', $product->id, $before, $product->fresh()->toArray());
            }
        }, 3);

        return back()->with('status', ($data['publish'] ?? false)
            ? 'Nominal berhasil diimpor dan sudah bisa dipilih di toko.'
            : 'Nominal disimpan sebagai draf.');
    }

    public function attachSource(Request $request, ProductPackage $package, DigiflazzCatalogService $service, DigiflazzCatalogImport $importer, AdminAuditService $audit): RedirectResponse
    {
        // Alasan belum dipecah: satu urutan transaksional — validasi,
        // pemasangan sumber di dalam transaksi, audit, lalu tanggapan;
        // semua langkah berbagi konteks paket dan hasil yang sama dan
        // harus berhenti utuh bila satu langkah gagal.
        abort_unless($package->product->fulfillment_mode === 'AUTO_PROVIDER', 422);

        $data = $request->validate([
            'item_id' => ['required', 'integer', 'exists:digiflazz_catalog_items,id'],
        ]);

        DB::transaction(function () use ($request, $package, $service, $importer, $audit, $data): void {
            $item = DB::table('digiflazz_catalog_items')->where('id', $data['item_id'])->lockForUpdate()->firstOrFail();
            if (! $service->sellable($item)) {
                throw ValidationException::withMessages([
                    'item_id' => 'SKU tidak tersedia atau data lebih dari 24 jam. Sinkronkan Digiflazz dahulu.',
                ]);
            }

            $provider = Provider::where('code', 'DIGIFLAZZ')->firstOrFail();
            $existing = ProviderMapping::where('provider_id', $provider->id)
                ->where('external_sku', $item->buyer_sku_code)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                throw ValidationException::withMessages([
                    'item_id' => $existing->product_package_id === $package->id
                        ? 'SKU ini sudah menjadi mapping nominal tersebut.'
                        : 'SKU ini sudah digunakan oleh nominal lain.',
                ]);
            }
            if (ProviderMapping::where('product_package_id', $package->id)
                ->where('provider_id', $provider->id)->exists()) {
                throw ValidationException::withMessages([
                    'item_id' => 'Nominal ini sudah memiliki satu SKU Digiflazz. Lepas mapping lama sebelum menggantinya.',
                ]);
            }

            try {
                $mapping = $importer->upsert(
                    $package,
                    (string) $item->buyer_sku_code,
                    (int) $item->price_idr,
                    (int) $item->price_idr
                );
            } catch (\InvalidArgumentException $exception) {
                throw ValidationException::withMessages(['item_id' => $exception->getMessage()]);
            }

            $priority = (int) ProviderMapping::where('product_package_id', $package->id)
                ->whereKeyNot($mapping->id)
                ->max('priority') + 1;
            $primaryConfig = ProviderMapping::where('product_package_id', $package->id)
                ->where('provider_id', $provider->id)
                ->whereKeyNot($mapping->id)
                ->orderBy('priority')
                ->value('fulfillment_config');

            $config = is_string($primaryConfig)
                ? json_decode($primaryConfig, true)
                : $primaryConfig;
            $config = is_array($config) ? $config : [];
            // The destination template can be shared, but a manually chosen
            // backup must not inherit the primary SKU's verified identity.
            $config['auto_source_group'] = app(DigiflazzAutoSources::class)->key($item);
            $before = $mapping->toArray();
            $mapping->update([
                'priority' => $priority,
                'is_active' => false,
                'fulfillment_config' => $config,
            ]);
            $audit->record(
                $request,
                'catalog.fulfillment_source.attached',
                'provider_mapping',
                $mapping->id,
                $before,
                $mapping->toArray()
            );
        }, 3);

        return back()->with('status', 'Sumber fulfillment ditambahkan dalam keadaan nonaktif. Periksa prioritas, format tujuan, dan batas harga sebelum mengaktifkannya.');
    }

    public function autoSources(Request $request, Product $product, DigiflazzAutoSources $sources): RedirectResponse
    {
        abort_unless($product->fulfillment_mode === 'AUTO_PROVIDER', 422);
        $added = $sources->enableProduct($product, $request);

        return back()->with('status', 'Pemeriksaan sumber otomatis selesai. '.$added.' sumber awal ditambahkan pada nominal yang belum dipetakan.');
    }

    private function importCustomerTemplate(Product $product, ?string $value, bool $publish): ?string
    {
        $fields = $product->fields()->orderBy('sort_order')->get();
        $template = trim((string) $value);
        if ($template === '' && $fields->count() === 1) {
            $template = '{{'.$fields->first()->field_key.'}}';
        }
        if ($publish && ($fields->isEmpty() || $template === '')) {
            throw ValidationException::withMessages(['customer_no_template' => 'Tentukan format ID tujuan agar nominal langsung bisa dijual.']);
        }
        if ($template === '') {
            return null;
        }
        preg_match_all('/\{\{([a-z][a-z0-9_]*)\}\}/', $template, $matches);
        $references = $matches[1] ?? [];
        $remaining = preg_replace('/\{\{([a-z][a-z0-9_]*)\}\}/', '', $template);
        if ($references === [] || array_diff($references, $fields->pluck('field_key')->all())
            || str_contains($remaining, '{') || str_contains($remaining, '}')
            || ($publish && array_diff($fields->where('is_required', true)->pluck('field_key')->all(), $references))) {
            throw ValidationException::withMessages(['customer_no_template' => 'Format ID tujuan harus memakai kolom pelanggan yang benar dan menyertakan semua kolom wajib.']);
        }

        return $template;
    }
}
