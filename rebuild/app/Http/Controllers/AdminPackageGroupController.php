<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductPackageGroup;
use App\Services\CatalogAudit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminPackageGroupController
{
    private function uniqueName(Product $product, string $name, ?int $except = null): void
    {
        if ($product->packageGroups()->get(['id', 'name'])->contains(
            fn (ProductPackageGroup $group): bool => (int) $group->id !== $except
                && mb_strtolower($group->name) === mb_strtolower($name)
        )) {
            throw ValidationException::withMessages(['name' => 'Nama grup sudah digunakan pada produk ini.']);
        }
    }

    public function store(Request $request, Product $product, CatalogAudit $audit): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'image' => ['sometimes', 'image', 'mimes:jpeg,png,webp', 'max:5120'],
        ]);
        $name = trim($data['name']);
        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Nama grup wajib diisi.']);
        }
        DB::transaction(function () use ($request, $product, $name, $audit): void {
            $product = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            $this->uniqueName($product, $name);
            $group = $product->packageGroups()->create([
                'name' => $name, 'sort_order' => ((int) $product->packageGroups()->max('sort_order')) + 1,
            ]);
            $tabs = $product->package_tabs ?? [];
            if (! in_array($name, $tabs, true)) {
                $tabs[] = $name;
                $product->update(['package_tabs' => $tabs]);
            }
            if ($request->hasFile('image')) {
                $group->addMediaFromRequest('image')->toMediaCollection('image', config('media-library.disk_name', 'public'));
            }
            $audit->record($request, 'catalog.group.created', 'product_package_group', $group->id, null, $group->toArray());
        }, 3);

        return back();
    }

    public function update(Request $request, ProductPackageGroup $group, CatalogAudit $audit): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'image' => ['sometimes', 'image', 'mimes:jpeg,png,webp', 'max:5120'],
        ]);
        $name = trim($data['name']);
        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Nama grup wajib diisi.']);
        }
        DB::transaction(function () use ($request, $group, $name, $audit): void {
            $product = Product::whereKey($group->product_id)->lockForUpdate()->firstOrFail();
            $group = ProductPackageGroup::whereKey($group->id)->lockForUpdate()->firstOrFail();
            $this->uniqueName($product, $name, (int) $group->id);
            $before = $group->toArray();
            $old = $group->name;
            if ($name !== $old) {
                // Exact match protects legacy case-sensitive groups.
                $ids = $product->packages()->get(['id', 'group_name'])
                    ->filter(fn ($row): bool => trim((string) $row->group_name) === $old
                        || ($old === 'Umum' && trim((string) $row->group_name) === ''))
                    ->pluck('id')->all();
                if ($ids !== []) {
                    $product->packages()->whereIn('id', $ids)->update(['group_name' => $name]);
                }
                $product->update(['package_tabs' => array_map(
                    fn ($tab) => trim((string) $tab) === $old ? $name : $tab, $product->package_tabs ?? []
                )]);
                $group->update(['name' => $name]);
            }
            if ($request->hasFile('image')) {
                $group->addMediaFromRequest('image')->toMediaCollection('image', config('media-library.disk_name', 'public'));
            }
            $audit->record($request, 'catalog.group.updated', 'product_package_group', $group->id, $before, $group->fresh()->toArray());
        }, 3);

        return back();
    }

    public function destroy(Request $request, ProductPackageGroup $group, CatalogAudit $audit): RedirectResponse
    {
        DB::transaction(function () use ($request, $group, $audit): void {
            $product = Product::whereKey($group->product_id)->lockForUpdate()->firstOrFail();
            $group = ProductPackageGroup::whereKey($group->id)->lockForUpdate()->firstOrFail();
            // Old nominal group labels may still carry whitespace. Never
            // delete a group used by any such nominal.
            $inUse = $product->packages()->get(['group_name'])->contains(
                fn ($row): bool => (trim((string) $row->group_name) === '' ? 'Umum' : trim((string) $row->group_name)) === $group->name
            );
            if ($inUse || ($product->package_tabs_enabled && $product->packageGroups()->count() <= 1)) {
                throw ValidationException::withMessages([
                    'group' => 'Grup masih berisi nominal atau tab terakhir. Pindahkan nominal dahulu.',
                ]);
            }
            $before = $group->toArray();
            $product->update(['package_tabs' => array_values(array_filter($product->package_tabs ?? [],
                fn ($name): bool => trim((string) $name) !== $group->name))]);
            $group->delete();
            $audit->record($request, 'catalog.group.deleted', 'product_package_group', $group->id, $before, []);
        }, 3);

        return back();
    }

    public function reorder(Request $request, Product $product, CatalogAudit $audit): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:1000'],
            'ids.*' => ['required', 'integer', 'distinct'],
        ]);
        DB::transaction(function () use ($request, $product, $data, $audit): void {
            $product = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            $groups = $product->packageGroups()->lockForUpdate()->get();
            $ids = array_map('intval', $data['ids']);
            $expected = $groups->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $actual = $ids;
            sort($expected);
            sort($actual);
            if ($expected !== $actual) {
                throw ValidationException::withMessages(['ids' => 'Daftar grup berubah. Muat ulang panel.']);
            }
            $names = [];
            foreach ($ids as $index => $id) {
                $group = $groups->firstWhere('id', $id);
                $group->update(['sort_order' => $index]);
                $names[] = $group->name;
            }
            $before = $product->package_tabs ?? [];
            $product->update(['package_tabs' => $names]);
            $audit->record($request, 'catalog.groups.reordered', 'product', $product->id, $before, $names);
        }, 3);

        return back();
    }
}
