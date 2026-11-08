<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductPackage;
use App\Models\Provider;
use App\Models\ProviderMapping;
use App\Services\CatalogAudit;
use App\Services\CheckoutPricing;
use App\Services\DigiflazzPriceOwnership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Membuat, mengubah, menghapus, dan menduplikasi paket/nominal sebuah
 * produk dari panel admin, termasuk menempatkannya di grup paket yang
 * terkunci. Mengatur urutan paket diurus AdminPackageReorderController.
 */
class AdminPackageController
{
    public function destroyPackage(Request $request, string $package, CatalogAudit $audit): RedirectResponse
    {
        // Repeated requests (double-taps, stale dialogs) are idempotent.
        // Do not let implicit route-model binding turn an already-deleted
        // nominal into an HTML 404 in the Inertia admin interface.
        $nominal = ProductPackage::find($package);
        if ($nominal === null) {
            return redirect()->route('admin.catalog.index')->with('status', 'Nominal sudah terhapus.');
        }

        if (DB::table('orders')->where('product_package_id', $nominal->id)->exists()) {
            throw ValidationException::withMessages([
                'package' => 'Nominal memiliki riwayat pesanan dan tidak boleh dihapus. Nonaktifkan nominal jika tidak ingin dijual.',
            ]);
        }

        DB::transaction(function () use ($request, $nominal, $audit): void {
            // Serialize concurrent attempts on the same row. Only the first
            // request deletes files, mappings and writes an audit event.
            $locked = ProductPackage::query()->lockForUpdate()->find($nominal->id);
            if ($locked === null) {
                return;
            }
            $before = $locked->toArray();
            $id = $locked->id;
            $locked->clearMediaCollection('image');
            $locked->mappings()->delete();
            $locked->delete();
            $audit->record($request, 'catalog.package.deleted', 'product_package', $id, $before, []);
        });

        return back()->with('status', 'Nominal berhasil dihapus.');
    }

    public function duplicatePackage(Request $request, ProductPackage $package, CatalogAudit $audit): RedirectResponse
    {
        $product = $package->product()->firstOrFail();
        if ($product->fulfillment_mode !== 'MANUAL') {
            throw ValidationException::withMessages([
                'package' => 'Nominal otomatis tidak disalin agar SKU penyedia tidak terduplikasi. Gunakan Impor nominal Digiflazz untuk menambah nominal otomatis.',
            ]);
        }

        $manualProviderId = Provider::where('code', 'MANUAL')->value('id');
        $sourceMapping = $manualProviderId
            ? $package->mappings()->where('provider_id', $manualProviderId)->first()
            : null;
        if (! $sourceMapping) {
            throw ValidationException::withMessages(['package' => 'Modal nominal manual belum tersedia.']);
        }

        DB::transaction(function () use ($request, $product, $package, $manualProviderId, $audit): void {
            // A rename holds the same product lock. Refresh source after locking
            // so Salin never revives an obsolete group name.
            $product = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            $package = $product->packages()->whereKey($package->id)->lockForUpdate()->firstOrFail();
            $sourceMapping = $package->mappings()->where('provider_id', $manualProviderId)->first();
            if (! $sourceMapping) {
                throw ValidationException::withMessages(['package' => 'Modal nominal manual sudah berubah.']);
            }
            $baseCode = substr($package->code.'_COPY', 0, 70);
            $copyNumber = 1;
            do {
                $code = $baseCode.'_'.$copyNumber++;
            } while (ProductPackage::where('product_id', $product->id)->where('code', $code)->exists());

            $copy = $product->packages()->create([
                'code' => $code,
                'name' => $package->name.' (Salinan)',
                'note' => $package->note,
                'group_name' => $package->group_name,
                'nominal_value' => $package->nominal_value,
                'sort_order' => ((int) $product->packages()->max('sort_order')) + 1,
                'is_active' => false,
                'pricing_mode' => $package->pricing_mode,
                'margin_percent' => $package->margin_percent,
                'margin_fixed_idr' => $package->margin_fixed_idr,
                'sell_price_idr' => $package->sell_price_idr,
                'sell_markup_percent' => $package->sell_markup_percent,
                'cost_uplift_percent' => $package->cost_uplift_percent,
            ]);
            $mapping = $copy->mappings()->create([
                'provider_id' => $sourceMapping->provider_id,
                'external_sku' => null,
                'cost_idr' => $sourceMapping->cost_idr,
                'store_cost_idr' => $sourceMapping->store_cost_idr,
                'max_price_idr' => null,
                'priority' => $sourceMapping->priority,
                'is_active' => false,
            ]);
            if ($media = $package->getFirstMedia('image')) {
                $media->copy($copy, 'image');
            }
            $audit->record($request, 'catalog.package.duplicated', 'product_package', $copy->id,
                ['source_id' => $package->id], ['package' => $copy->toArray(), 'mapping' => $mapping->toArray()]);
        });

        return back()->with('status', 'Salinan nominal dibuat dalam keadaan nonaktif.');
    }

    public function package(Request $request, Product $product, CatalogAudit $audit): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9_-]+$/',
                Rule::unique('product_packages', 'code')->where('product_id', $product->id)],
            'name' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:80'],
            'group_name' => ['nullable', 'string', 'max:120'],
            'group_id' => ['sometimes', 'nullable', 'integer'],
            'nominal_value' => ['nullable', 'integer', 'min:0'],
            'sort_order' => ['required', 'integer', 'min:0'],
            'cost_idr' => [$product->fulfillment_mode === 'MANUAL' ? 'required' : 'nullable',
                'integer', 'min:0', 'max:1000000000'],
            'is_active' => ['sometimes', 'boolean'],
            'pricing_mode' => ['sometimes', Rule::in(['PRODUCT_MARGIN', 'PERCENT', 'FIXED', 'SELL_PRICE'])],
            'margin_percent' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1000', 'decimal:0,4', 'required_if:pricing_mode,PERCENT'],
            'margin_fixed_idr' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000000', 'required_if:pricing_mode,FIXED'],
            'sell_price_idr' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000000000', 'required_if:pricing_mode,SELL_PRICE'],
            'sell_markup_percent' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1000', 'decimal:0,4'],
        ]);

        // Nominal otomatis hanya bisa dijual setelah punya sumber Digiflazz aktif.
        $wantsActive = (bool) ($data['is_active'] ?? false);
        $canActivate = $product->fulfillment_mode === 'MANUAL';
        $isActive = $wantsActive && $canActivate;
        $pricing = collect($data)->only([
            'pricing_mode', 'margin_percent', 'margin_fixed_idr', 'sell_price_idr', 'sell_markup_percent',
        ])->filter(fn ($value): bool => $value !== null)->all();
        if (($pricing['pricing_mode'] ?? null) === 'SELL_PRICE'
            && (int) $pricing['sell_price_idr'] < (int) ($data['cost_idr'] ?? 0)) {
            throw ValidationException::withMessages(['sell_price_idr' => 'Harga jual tidak boleh di bawah modal.']);
        }
        if ($isActive && $product->fulfillment_mode === 'MANUAL' && (int) ($data['cost_idr'] ?? 0) < 1) {
            throw ValidationException::withMessages(['cost_idr' => 'Nominal aktif harus punya modal lebih dari Rp 0.']);
        }

        if (isset($data['group_id'])) {
            $group = $product->packageGroups()->find($data['group_id']);
            if (! $group) {
                throw ValidationException::withMessages(['group_id' => 'Pilih grup pada produk ini.']);
            }
            $data['group_name'] = $group->name;
        }
        $selectedGroupId = $data['group_id'] ?? null;
        unset($data['group_id']);
        if ($product->package_tabs_enabled) {
            $group = trim((string) ($data['group_name'] ?? ''));
            $tabs = collect($product->package_tabs ?? []);
            if ($group === '' || ! $tabs->containsStrict($group)) {
                throw ValidationException::withMessages([
                    'group_name' => 'Pilih grup yang tersedia pada tab nominal produk.',
                ]);
            }
        }

        DB::transaction(function () use ($request, $product, $data, $audit, $isActive, $pricing, $selectedGroupId): void {
            $lockedProduct = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            if ($selectedGroupId !== null) {
                $data['group_name'] = $this->lockedPackageGroupName($lockedProduct, (int) $selectedGroupId);
            }
            $package = $product->packages()->create([
                'code' => $data['code'],
                'name' => $data['name'],
                'note' => $data['note'] ?? null,
                'group_name' => $data['group_name'] ?? null,
                'nominal_value' => $data['nominal_value'] ?? null,
                'sort_order' => $data['sort_order'],
                ...$pricing,
                'is_active' => $isActive,
            ]);
            if ($product->fulfillment_mode === 'MANUAL') {
                $provider = Provider::where('code', 'MANUAL')->firstOrFail();
                $mapping = ProviderMapping::create([
                    'product_package_id' => $package->id,
                    'provider_id' => $provider->id,
                    'external_sku' => null,
                    'cost_idr' => $data['cost_idr'],
                    'store_cost_idr' => $data['cost_idr'],
                    'max_price_idr' => null,
                    'priority' => 0,
                    'is_active' => $isActive,
                ]);
                $audit->record($request, 'catalog.mapping.created', 'provider_mapping',
                    $mapping->id, null, $mapping->toArray());
            }
            $audit->record($request, 'catalog.package.created', 'product_package', $package->id, null, $package->toArray());
        });

        return $wantsActive && ! $isActive
            ? back()->with('status', 'Nominal disimpan nonaktif. Aktifkan setelah sumber Digiflazz dipasang.')
            : back();
    }

    public function updatePackage(Request $request, ProductPackage $package, CatalogAudit $audit): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9_-]+$/',
                Rule::unique('product_packages', 'code')->where('product_id', $package->product_id)->ignore($package->id)],
            'name' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:80'],
            'group_name' => ['nullable', 'string', 'max:120'],
            'group_id' => ['sometimes', 'nullable', 'integer'],
            'nominal_value' => ['nullable', 'integer', 'min:0'],
            'sort_order' => ['required', 'integer', 'min:0'],
            'is_active' => ['required', 'boolean'],
            'pricing_mode' => ['sometimes', Rule::in(['PRODUCT_MARGIN', 'PERCENT', 'FIXED', 'SELL_PRICE'])],
            'price_source' => ['sometimes', Rule::in(['DIGIFLAZZ', 'VOUCHER_STOCK'])],
            'margin_percent' => ['nullable', 'numeric', 'min:0', 'max:1000', 'decimal:0,4', 'required_if:pricing_mode,PERCENT'],
            'margin_fixed_idr' => ['nullable', 'integer', 'min:0', 'max:1000000000', 'required_if:pricing_mode,FIXED'],
            'sell_price_idr' => ['nullable', 'integer', 'min:1', 'max:1000000000', 'required_if:pricing_mode,SELL_PRICE'],
            'sell_markup_percent' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1000', 'decimal:0,4'],
            'cost_uplift_percent' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1000', 'decimal:0,4'],
            'cost_idr' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000000'],
            'store_cost_idr' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000000'],
        ]);

        $product = $package->product()->firstOrFail();
        // Nominal tersimpan tidak boleh hilang karena klien mengirim null tanpa
        // sengaja mengubahnya (form edit tidak punya input nominal_value).
        if (array_key_exists('nominal_value', $data)
            && $data['nominal_value'] === null && $package->nominal_value !== null) {
            unset($data['nominal_value']);
        }
        // Modal toko hidup di store_cost_idr. cost_idr hanya dibaca sebagai
        // fallback supaya form lama tetap jalan, tapi tidak pernah menimpanya.
        $newCost = array_key_exists('store_cost_idr', $data) && $data['store_cost_idr'] !== null
            ? (int) $data['store_cost_idr']
            : (array_key_exists('cost_idr', $data) && $data['cost_idr'] !== null ? (int) $data['cost_idr'] : null);
        if ($newCost !== null && $product->fulfillment_mode !== 'MANUAL') {
            throw ValidationException::withMessages([
                'cost_idr' => 'Modal hanya bisa diisi pada produk dengan penanganan manual.',
            ]);
        }
        $isActive = (bool) ($data['is_active'] ?? $package->is_active);
        // Nominal aktif tanpa modal > 0 tidak akan pernah lolos CheckoutPricing.
        $manualMapping = $product->fulfillment_mode === 'MANUAL'
            ? $package->mappings()->where('provider_id', Provider::where('code', 'MANUAL')->value('id'))->first()
            : null;
        $effectiveCost = $newCost ?? (int) ($manualMapping?->store_cost_idr ?? $manualMapping?->cost_idr ?? 0);
        if ($isActive && $product->fulfillment_mode === 'MANUAL' && $effectiveCost < 1) {
            // Modal nol tidak bisa dijual: nominalnya dimatikan, tapi modal lama
            // tidak ikut dihapus supaya bisa dinyalakan lagi tanpa isi ulang.
            $isActive = false;
            $newCost = null;
        }
        // Nominal otomatis hanya bisa dijual lewat sumber penyedia yang aktif.
        if ($isActive && $product->fulfillment_mode !== 'MANUAL'
            && ! $package->mappings()->where('is_active', true)->exists()) {
            throw ValidationException::withMessages([
                'is_active' => 'Aktifkan sumber Digiflazz nominal ini sebelum menjualnya.',
            ]);
        }
        if (isset($data['group_id'])) {
            $group = $product->packageGroups()->find($data['group_id']);
            if (! $group) {
                throw ValidationException::withMessages(['group_id' => 'Pilih grup pada produk ini.']);
            }
            $data['group_name'] = $group->name;
        }
        $selectedGroupId = $data['group_id'] ?? null;
        unset($data['group_id']);
        if ($product->package_tabs_enabled) {
            $group = trim((string) ($data['group_name'] ?? $package->group_name ?? ''));
            $tabs = collect($product->package_tabs ?? []);
            if ($group === '' || ! $tabs->containsStrict($group)) {
                throw ValidationException::withMessages([
                    'group_name' => 'Pilih grup yang tersedia pada tab nominal produk.',
                ]);
            }
        }

        $highestActiveCost = $package->mappings()->where('is_active', true)
            ->selectRaw('MAX(COALESCE(store_cost_idr, cost_idr)) as aggregate')->value('aggregate');
        $effectiveQuote = app(CheckoutPricing::class)->forPackages([(int) $package->id], true);
        $hasDigiflazzSource = app(DigiflazzPriceOwnership::class)->usesDigiflazz(
            $package, $effectiveQuote[(int) $package->id] ?? null
        );
        $expectedPriceSource = $data['price_source'] ?? null;
        unset($data['price_source']);
        $changesDigiflazzPrice = array_key_exists('pricing_mode', $data)
            || array_key_exists('margin_percent', $data)
            || array_key_exists('margin_fixed_idr', $data)
            || isset($data['sell_price_idr']) || isset($data['sell_markup_percent']);
        if ($expectedPriceSource !== null && $changesDigiflazzPrice
            && $expectedPriceSource !== ($hasDigiflazzSource ? 'DIGIFLAZZ' : 'VOUCHER_STOCK')) {
            throw ValidationException::withMessages([
                'pricing_mode' => 'Sumber harga nominal berubah sejak editor dibuka. Muat ulang nominal sebelum menyimpan harga.',
            ]);
        }
        if ($hasDigiflazzSource && $changesDigiflazzPrice) {
            $effectiveMode = $data['pricing_mode'] ?? $package->digiflazz_markup_mode ?? $package->pricing_mode;
            if (! in_array($effectiveMode, ['PERCENT', 'FIXED'], true)) {
                throw ValidationException::withMessages([
                    'pricing_mode' => 'Harga Digiflazz harus berupa persentase atau tambahan rupiah per nominal.',
                ]);
            }
            // Validate the effective mode and price even when the client sends
            // only one price field, without the pricing_mode field.
            $effectiveValue = $effectiveMode === 'FIXED'
                ? (array_key_exists('margin_fixed_idr', $data) ? $data['margin_fixed_idr']
                    : ($package->digiflazz_markup_mode !== null ? $package->digiflazz_markup_fixed_idr : $package->margin_fixed_idr))
                : (array_key_exists('margin_percent', $data) ? $data['margin_percent']
                    : ($package->digiflazz_markup_mode !== null ? $package->digiflazz_markup_percent : $package->margin_percent));
            if ($effectiveValue === null || ! is_numeric($effectiveValue)) {
                throw ValidationException::withMessages([
                    $effectiveMode === 'FIXED' ? 'margin_fixed_idr' : 'margin_percent' => 'Nilai markup nominal Digiflazz wajib diisi.',
                ]);
            }
            if (isset($data['sell_price_idr']) || isset($data['sell_markup_percent'])) {
                throw ValidationException::withMessages([
                    'pricing_mode' => 'Nominal Digiflazz tidak memakai harga jual tetap atau markup global.',
                ]);
            }
        }
        $overridesDigiflazzPrice = $hasDigiflazzSource && $changesDigiflazzPrice
            && $package->digiflazz_markup_mode !== null;
        $newDigiflazzPricing = [];
        if ($overridesDigiflazzPrice) {
            $mode = $data['pricing_mode'] ?? $package->digiflazz_markup_mode;
            $newDigiflazzPricing = [
                'digiflazz_markup_mode' => $mode,
                'digiflazz_markup_percent' => $mode === 'PERCENT'
                    ? ($data['margin_percent'] ?? $package->digiflazz_markup_percent) : null,
                'digiflazz_markup_fixed_idr' => $mode === 'FIXED'
                    ? ($data['margin_fixed_idr'] ?? $package->digiflazz_markup_fixed_idr) : null,
            ];
            // Never overwrite voucher pricing when its Digiflazz source is
            // selected for checkout. These fields belong to the other source.
            unset($data['pricing_mode'], $data['margin_percent'], $data['margin_fixed_idr'],
                $data['sell_price_idr'], $data['sell_markup_percent']);
        }
        // A mixed-source nominal can retain a separate fixed voucher price.
        // Editing that voucher must not accidentally replace its Digiflazz
        // percentage/additional-IDR rule on the same package.
        $hasRetainedVoucherPrice = ! $hasDigiflazzSource
            && $package->legacy_voucher_sell_price_idr !== null;
        $editsVoucherPrice = $hasRetainedVoucherPrice
            && (array_key_exists('pricing_mode', $data) || array_key_exists('sell_price_idr', $data));
        $voucherMode = $editsVoucherPrice
            ? ($data['pricing_mode'] ?? 'SELL_PRICE') : null;
        $replacementVoucherPrice = $voucherMode === 'SELL_PRICE'
            ? (int) ($data['sell_price_idr'] ?? $package->legacy_voucher_sell_price_idr)
            : null;
        if (! $hasDigiflazzSource
            && (($hasRetainedVoucherPrice && $voucherMode === 'SELL_PRICE')
                || (! $hasRetainedVoucherPrice && ($data['pricing_mode'] ?? $package->pricing_mode) === 'SELL_PRICE'))
            && (int) ($hasRetainedVoucherPrice ? $replacementVoucherPrice : ($data['sell_price_idr'] ?? $package->sell_price_idr))
                < (int) ($newCost ?? ($effectiveQuote[(int) $package->id]['cost_idr'] ?? $highestActiveCost))) {
            throw ValidationException::withMessages(['sell_price_idr' => 'Harga jual tidak boleh di bawah modal.']);
        }
        if ($editsVoucherPrice && $voucherMode === 'SELL_PRICE') {
            // Retain the independent Digiflazz rule; only the voucher cap
            // is changed by this editor action.
            unset($data['pricing_mode'], $data['sell_price_idr']);
        }
        $wasActive = (bool) $package->is_active;
        DB::transaction(function () use ($request, $package, $product, $isActive, $data, $audit, $newCost, $wasActive, $editsVoucherPrice, $replacementVoucherPrice, $newDigiflazzPricing, $selectedGroupId): void {
            $lockedProduct = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            if ($selectedGroupId !== null) {
                $data['group_name'] = $this->lockedPackageGroupName($lockedProduct, (int) $selectedGroupId);
            }
            $before = $package->toArray();
            // is_active memakai hasil hitungan server: nominal tanpa modal
            // dimatikan walau klien mengirim true.
            $package->update([
                ...collect($data)->except(['cost_idr', 'store_cost_idr', 'is_active'])->all(),
                'is_active' => $isActive,
            ]);
            if ($newDigiflazzPricing !== []) {
                $package->forceFill($newDigiflazzPricing)->save();
            }
            if ($editsVoucherPrice) {
                // A successful fixed-price edit must affect checkout too.
                // Switching to percentage pricing removes the legacy cap.
                $package->forceFill(['legacy_voucher_sell_price_idr' => $replacementVoucherPrice])->save();
            }
            // Admin menyalakan sendiri: penanda "dimatikan sinkron" dilepas supaya
            // sinkron berikutnya tidak menganggapnya miliknya.
            if ($isActive && $package->disabled_by_sync) {
                $package->update(['disabled_by_sync' => false]);
            }
            if ($product->fulfillment_mode === 'MANUAL') {
                // Modal nominal manual hidup di pemetaan provider, bukan di paket,
                // dan status aktif nominal harus ikut supaya CheckoutPricing melihatnya.
                $provider = Provider::where('code', 'MANUAL')->firstOrFail();
                $mapping = $package->mappings()->where('provider_id', $provider->id)->first()
                    ?? ProviderMapping::create([
                        'product_package_id' => $package->id,
                        'provider_id' => $provider->id,
                        'external_sku' => null,
                        'cost_idr' => $newCost ?? 0,
                        'store_cost_idr' => $newCost ?? 0,
                        'max_price_idr' => null,
                        'priority' => 0,
                        'is_active' => $isActive,
                    ]);
                $mappingBefore = $mapping->toArray();
                $mapping->update([
                    'cost_idr' => $newCost ?? $mapping->cost_idr,
                    'store_cost_idr' => $newCost ?? $mapping->store_cost_idr,
                    // Status sumber hanya ikut berubah kalau status nominalnya
                    // sendiri berubah; ganti nama nominal tidak boleh
                    // menghidupkan sumber yang sudah dimatikan admin.
                    ...($wasActive === $isActive ? [] : ['is_active' => $isActive]),
                ]);
                $audit->record($request, 'catalog.mapping.updated', 'provider_mapping', $mapping->id, $mappingBefore, $mapping->fresh()->toArray());
            }
            $audit->record($request, 'catalog.package.updated', 'product_package', $package->id, $before, $package->toArray());
        });

        return back();
    }

    /**
     * Called only while the transaction holds the same product lock as rename,
     * so a concurrent rename or deletion cannot strand a nominal.
     */
    private function lockedPackageGroupName(Product $product, int $groupId): string
    {
        $group = $product->packageGroups()->whereKey($groupId)->first();
        if (! $group) {
            throw ValidationException::withMessages(['group_id' => 'Grup telah berubah atau dihapus. Muat ulang panel.']);
        }
        if ($product->package_tabs_enabled && ! in_array($group->name, $product->package_tabs ?? [], true)) {
            throw ValidationException::withMessages(['group_id' => 'Grup tidak ada pada tab produk ini.']);
        }

        return $group->name;
    }
}
