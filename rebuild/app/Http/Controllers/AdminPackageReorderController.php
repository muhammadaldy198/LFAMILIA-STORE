<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductPackage;
use App\Services\CatalogAudit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Mengatur ulang urutan paket dan urutan grup paket di dalam satu produk
 * dari panel admin. Class ini hanya mengubah urutan, tidak mengubah isi
 * paket atau grupnya.
 */
class AdminPackageReorderController
{
    public function reorderPackageGroups(Request $request, Product $product, CatalogAudit $audit): RedirectResponse
    {
        $data = $request->validate([
            'groups' => ['present', 'array', 'max:1000'],
            'groups.*' => ['required', 'string', 'max:120'],
        ]);
        // No new database table: the existing package_tabs JSON keeps the
        // group priority, while package_tabs_enabled remains unchanged.
        $normalizeGroup = static function ($name): string {
            $name = trim((string) $name);

            return $name === '' ? 'Umum' : $name;
        };
        $current = $product->packages()->pluck('group_name')
            ->map($normalizeGroup)->uniqueStrict()->values()->all();
        $submitted = array_map($normalizeGroup, $data['groups']);
        $expected = $current;
        $actual = $submitted;
        sort($expected, SORT_STRING);
        sort($actual, SORT_STRING);
        if ($expected !== $actual || count($submitted) !== count(array_unique($submitted))) {
            throw ValidationException::withMessages([
                'groups' => 'Daftar grup berubah atau tidak lengkap. Muat ulang halaman produk.',
            ]);
        }
        $before = $product->package_tabs ?? [];
        $stored = $submitted;
        if ($product->package_tabs_enabled) {
            // Legacy tabs without nominals are still valid future destinations.
            // Keep their slots while reordering only the occupied group headings.
            $remaining = $submitted;
            $stored = [];
            foreach ($before as $name) {
                if (in_array(trim((string) $name), $current, true)) {
                    if ($remaining !== []) {
                        $stored[] = array_shift($remaining);
                    }
                } else {
                    $stored[] = $name;
                }
            }
            $stored = array_merge($stored, $remaining);
        }
        $product->update(['package_tabs' => $stored]);
        $audit->record($request, 'catalog.package-groups.reordered', 'product', $product->id,
            $before, $stored);

        return back();
    }

    public function reorderPackages(Request $request, Product $product, CatalogAudit $audit): RedirectResponse
    {
        $data = $request->validate(['ids' => ['required', 'array', 'max:1000'], 'ids.*' => ['integer', 'distinct']]);
        DB::transaction(function () use ($product, $data, $request, $audit): void {
            $before = $product->packages()->lockForUpdate()->orderBy('sort_order')->pluck('id')->all();
            $ids = array_map('intval', $data['ids']);
            $expected = $before;
            $actual = $ids;
            sort($expected);
            sort($actual);
            if ($expected !== $actual) {
                throw ValidationException::withMessages(['ids' => 'Daftar nominal berubah. Muat ulang halaman.']);
            }
            foreach ($ids as $order => $id) {
                ProductPackage::where('id', $id)->update(['sort_order' => $order]);
            }
            $audit->record($request, 'catalog.packages.reordered', 'product', $product->id, $before, $ids);
        });

        return back();
    }
}
