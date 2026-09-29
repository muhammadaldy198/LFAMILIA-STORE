<?php

use App\Http\Controllers\AdminAuthController;
use App\Http\Controllers\AdminCatalogController;
use App\Http\Controllers\AdminCatalogMediaController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\CustomerAccountController;
use App\Http\Controllers\CustomerOrderController;
use App\Http\Controllers\CustomerPhoneController;
use App\Http\Controllers\GoogleAuthController;
use App\Http\Controllers\GuestOrderController;
use App\Http\Controllers\SupportTicketController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', [CatalogController::class, 'index'])->name('catalog.index');
Route::get('/catalog/{slug}', [CatalogController::class, 'show'])->name('catalog.show');

Route::middleware('throttle:30,1')->group(function (): void {
    Route::post('/checkout/nickname', [CheckoutController::class, 'nickname'])->name('checkout.nickname');
    Route::post('/checkout/quote', [CheckoutController::class, 'quote'])->name('checkout.quote');
});
Route::post('/checkout/orders', [CheckoutController::class, 'store'])
    ->middleware('throttle:10,1')->name('checkout.store');

Route::get('/health/ready', function () {
    try {
        DB::select('SELECT 1');
        Redis::connection()->ping();

        return response()->json(['status' => 'healthy']);
    } catch (Throwable $exception) {
        report($exception);

        return response()->json(['status' => 'unavailable'], 503);
    }
});

Route::middleware('throttle:10,1')->group(function (): void {
    Route::get('/orders/check', [GuestOrderController::class, 'lookup'])->name('guest.orders.lookup');
    Route::post('/orders/check', [GuestOrderController::class, 'verify'])->name('guest.orders.verify');
});
Route::get('/orders/guest/{orderNumber}', [GuestOrderController::class, 'show'])
    ->name('guest.orders.show');

Route::middleware('guest:web')->group(function (): void {
    Route::get('/auth/google/redirect', [GoogleAuthController::class, 'redirect'])
        ->middleware('throttle:10,1')->name('google.redirect');
    Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])
        ->middleware('throttle:10,1')->name('google.callback');
});

Route::middleware('auth:web')->group(function (): void {
    Route::get('/account/phone', [CustomerPhoneController::class, 'edit'])->name('account.phone.edit');
    Route::put('/account/phone', [CustomerPhoneController::class, 'update'])->name('account.phone.update');

    Route::middleware(['phone.required', 'customer.activity'])->group(function (): void {
        Route::get('/account', [CustomerAccountController::class, 'dashboard'])->name('account');
        Route::get('/account/profile', [CustomerAccountController::class, 'profile'])->name('account.profile');
        Route::put('/account/profile', [CustomerAccountController::class, 'update'])->name('account.profile.update');
        Route::put('/account/password', [CustomerAccountController::class, 'password'])->name('account.password.update');
        Route::delete('/account', [CustomerAccountController::class, 'destroy'])->name('account.destroy');
        Route::get('/account/wallet', [CustomerAccountController::class, 'wallet'])->name('account.wallet');
        Route::get('/account/membership', [CustomerAccountController::class, 'membership'])->name('account.membership');
        Route::get('/account/orders', [CustomerOrderController::class, 'index'])->name('account.orders');
        Route::get('/account/orders/{order}', [CustomerOrderController::class, 'show'])->name('account.orders.show');
        Route::get('/account/tickets', [SupportTicketController::class, 'index'])->name('account.tickets');
        Route::post('/account/tickets', [SupportTicketController::class, 'store'])
            ->middleware('throttle:5,1')->name('account.tickets.store');
        Route::get('/account/tickets/{ticket}', [SupportTicketController::class, 'show'])
            ->name('account.tickets.show');
    });
});

Route::middleware('guest:admin')->group(function (): void {
    Route::get('/admin/login', [AdminAuthController::class, 'show'])->name('admin.login');
    Route::post('/admin/login', [AdminAuthController::class, 'login'])
        ->middleware('throttle:admin-login')->name('admin.login.store');
});

Route::middleware(['auth:admin', 'admin.role'])->group(function (): void {
    Route::get('/admin/panel', fn () => Inertia::render('Admin/Dashboard', [
        'admin' => auth('admin')->user()->only('id', 'name', 'email', 'role'),
    ]))->name('admin.panel');
    Route::post('/admin/logout', [AdminAuthController::class, 'logout'])->name('admin.logout');

    Route::middleware('admin.super')->prefix('admin/catalog')->name('admin.catalog.')->group(function (): void {
        Route::get('/', [AdminCatalogController::class, 'index'])->name('index');
        Route::post('/categories', [AdminCatalogController::class, 'category'])->name('categories.store');
        Route::put('/categories/{category}', [AdminCatalogController::class, 'updateCategory'])->name('categories.update');
        Route::post('/products', [AdminCatalogController::class, 'product'])->name('products.store');
        Route::put('/products/{product}', [AdminCatalogController::class, 'updateProduct'])->name('products.update');
        Route::post('/products/{product}/packages', [AdminCatalogController::class, 'package'])->name('packages.store');
        Route::put('/packages/{package}', [AdminCatalogController::class, 'updatePackage'])->name('packages.update');
        Route::put('/products/{product}/fields', [AdminCatalogController::class, 'fields'])->name('fields.update');
        Route::put('/mappings/{mapping}', [AdminCatalogController::class, 'mapping'])->name('mappings.update');
        Route::put('/assets/{asset}', [AdminCatalogController::class, 'asset'])->name('assets.update');
        Route::post('/media/{type}/{id}', [AdminCatalogMediaController::class, 'store'])->name('media.store');
        Route::delete('/media/{type}/{id}', [AdminCatalogMediaController::class, 'destroy'])->name('media.destroy');
    });
});
