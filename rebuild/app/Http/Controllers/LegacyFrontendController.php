<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LegacyFrontendController
{
    public function catalog(): RedirectResponse
    {
        return redirect('/#produk', 302);
    }

    public function checkout(Request $request): RedirectResponse
    {
        $slug = trim((string) $request->query('product', ''));
        if ($slug === '') {
            return redirect('/#produk', 302);
        }

        $product = Product::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->first();

        if (! $product) {
            return redirect('/#produk', 302);
        }

        $target = '/catalog/'.rawurlencode($product->slug);
        $package = trim((string) $request->query('package', ''));
        if ($package !== '') {
            $target .= '?package='.rawurlencode($package);
        }

        return redirect($target, 302);
    }

    public function track(): RedirectResponse
    {
        return redirect('/orders/check', 302);
    }
}
