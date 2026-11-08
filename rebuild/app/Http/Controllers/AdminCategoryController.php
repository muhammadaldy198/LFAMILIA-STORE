<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Services\CatalogAudit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Mengelola kategori katalog dari panel admin: daftar, membuat, mengubah,
 * dan menghapus kategori. Produk di dalam kategori diurus
 * AdminProductController, bukan class ini.
 */
class AdminCategoryController
{
    public function categories(): Response
    {
        return Inertia::render('Admin/Categories', [
            'categories' => Category::with('media')->withCount('products')->orderBy('sort_order')->get()
                ->map(fn (Category $category): array => [
                    ...$category->only('id', 'name', 'slug', 'icon', 'sort_order', 'is_active'),
                    'products_count' => (int) $category->products_count,
                    'image_url' => $category->getFirstMediaUrl('image'),
                ]),
        ]);
    }

    public function category(Request $request, CatalogAudit $audit): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'icon' => ['nullable', Rule::in(['gamepad', 'ticket', 'play', 'smartphone', 'zap', 'grid'])],
            'sort_order' => ['required', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $data['slug'] = ($data['slug'] ?? null) ?: Str::slug($data['name']);
        $data['icon'] = $data['icon'] ?? 'grid';
        if (! $data['slug']) {
            throw ValidationException::withMessages(['name' => 'Nama kategori tidak valid.']);
        }
        if (Category::where('slug', $data['slug'])->exists()) {
            throw ValidationException::withMessages(['slug' => 'Alamat kategori sudah dipakai.']);
        }

        DB::transaction(function () use ($request, $data, $audit): void {
            $category = Category::create([...$data, 'is_active' => (bool) ($data['is_active'] ?? false)]);
            $audit->record($request, 'catalog.category.created', 'category', $category->id, null, $category->toArray());
        });

        return back();
    }

    public function updateCategory(Request $request, Category $category, CatalogAudit $audit): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['sometimes', 'required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('categories', 'slug')->ignore($category->id)],
            'icon' => ['sometimes', 'required', Rule::in(['gamepad', 'ticket', 'play', 'smartphone', 'zap', 'grid'])],
            'sort_order' => ['required', 'integer', 'min:0'],
            'is_active' => ['required', 'boolean'],
        ]);
        $data['slug'] ??= $category->slug;
        $data['icon'] ??= $category->icon;

        DB::transaction(function () use ($request, $category, $data, $audit): void {
            $before = $category->toArray();
            $category->update($data);
            $audit->record($request, 'catalog.category.updated', 'category', $category->id, $before, $category->toArray());
        });

        return back();
    }

    public function destroyCategory(Request $request, Category $category, CatalogAudit $audit): RedirectResponse
    {
        if ($category->products()->exists()) {
            return back()->withErrors([
                'category' => 'Kategori masih memiliki produk. Pindahkan atau nonaktifkan produknya terlebih dahulu.',
            ]);
        }

        $before = $category->toArray();
        $id = $category->id;
        $category->clearMediaCollection('image');
        $category->delete();
        $audit->record($request, 'catalog.category.deleted', 'category', $id, $before, []);

        return back();
    }
}
