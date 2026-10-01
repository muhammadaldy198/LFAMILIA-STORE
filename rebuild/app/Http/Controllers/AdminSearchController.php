<?php

namespace App\Http\Controllers;

use App\Services\AdminPermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminSearchController
{
    public function __invoke(Request $request, AdminPermissionService $permissions): JsonResponse
    {
        $data = $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']]);
        $q = trim($data['q']);
        $admin = $request->user('admin');
        $results = [];
        foreach ($permissions->menu($admin) as $item) {
            if (mb_stripos($item['label'], $q) !== false) {
                $results[] = ['title' => $item['label'], 'detail' => 'Menu Admin', 'href' => $item['href']];
            }
        }
        if ($permissions->allows($admin, 'orders.view')) {
            foreach (DB::table('orders')->where('order_number', 'like', '%'.$q.'%')->orderByDesc('id')->limit(5)->get(['order_number']) as $order) {
                $results[] = ['title' => $order->order_number, 'detail' => 'Pesanan',
                    'href' => '/admin/orders?q='.rawurlencode($order->order_number)];
            }
        }
        if ($permissions->allows($admin, 'catalog.manage')) {
            foreach (DB::table('products')->where(fn ($query) => $query->where('name', 'like', '%'.$q.'%')->orWhere('slug', 'like', '%'.$q.'%'))
                ->orderBy('name')->limit(5)->get(['name', 'slug']) as $product) {
                $results[] = ['title' => $product->name, 'detail' => 'Produk',
                    'href' => '/admin/catalog?q='.rawurlencode($product->name)];
            }
        }
        if ($permissions->allows($admin, 'customers.view')) {
            foreach (DB::table('users')->whereNull('deleted_at')->where(fn ($query) => $query->where('name', 'like', '%'.$q.'%')->orWhere('email', 'like', '%'.$q.'%'))
                ->orderBy('name')->limit(5)->get(['name', 'email']) as $customer) {
                $results[] = ['title' => $customer->name, 'detail' => 'Pelanggan',
                    'href' => '/admin/customers?q='.rawurlencode($customer->email ?: $customer->name)];
            }
        }

        return response()->json(['results' => array_slice($results, 0, 20)])->header('Cache-Control', 'no-store, private');
    }
}
