<?php

use App\Http\Controllers\AdminAccessController;
use App\Http\Controllers\AdminAuthController;
use App\Http\Controllers\AdminCatalogController;
use App\Http\Controllers\AdminCatalogMediaController;
use App\Http\Controllers\AdminDashboardController;
use App\Http\Controllers\AdminFulfillmentController;
use App\Http\Controllers\AdminIntegrationController;
use App\Http\Controllers\AdminNotificationController;
use App\Http\Controllers\AdminPaymentController;
use App\Http\Controllers\AdminWorkspaceController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\CustomerAccountController;
use App\Http\Controllers\CustomerOrderController;
use App\Http\Controllers\CustomerPhoneController;
use App\Http\Controllers\GoogleAuthController;
use App\Http\Controllers\GuestOrderController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\SupportTicketController;
use App\Http\Controllers\WalletTopupController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;

Route::get('/', [CatalogController::class, 'index'])->name('catalog.index');
Route::get('/catalog/{slug}', [CatalogController::class, 'show'])->name('catalog.show');

Route::post('/checkout/nickname', [CheckoutController::class, 'nickname'])
    ->middleware('throttle:checkout-nickname')->name('checkout.nickname');
Route::post('/checkout/quote', [CheckoutController::class, 'quote'])
    ->middleware('throttle:checkout-quote')->name('checkout.quote');
Route::post('/checkout/orders', [CheckoutController::class, 'store'])
    ->middleware('throttle:checkout-create')->name('checkout.store');
Route::post('/payments/orders/{orderNumber}', [PaymentController::class, 'create'])
    ->middleware('throttle:payment-create')->name('payments.orders.create');

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

Route::middleware('throttle:guest-order')->group(function (): void {
    Route::get('/orders/check', [GuestOrderController::class, 'lookup'])->name('guest.orders.lookup');
    Route::post('/orders/check', [GuestOrderController::class, 'verify'])->name('guest.orders.verify');
});
Route::get('/orders/guest/{orderNumber}', [GuestOrderController::class, 'show'])
    ->name('guest.orders.show');

Route::middleware('guest:web')->group(function (): void {
    Route::get('/auth/google/redirect', [GoogleAuthController::class, 'redirect'])
        ->middleware('throttle:google-oauth')->name('google.redirect');
    Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])
        ->middleware('throttle:google-oauth')->name('google.callback');
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
        Route::post('/account/wallet/topups/quote', [WalletTopupController::class, 'quote'])
            ->middleware('throttle:wallet-topup')->name('account.wallet.topups.quote');
        Route::post('/account/wallet/topups', [WalletTopupController::class, 'store'])
            ->middleware('throttle:wallet-topup')->name('account.wallet.topups.store');
        Route::get('/account/membership', [CustomerAccountController::class, 'membership'])->name('account.membership');
        Route::get('/account/orders', [CustomerOrderController::class, 'index'])->name('account.orders');
        Route::get('/account/orders/{order}', [CustomerOrderController::class, 'show'])->name('account.orders.show');
        Route::get('/account/tickets', [SupportTicketController::class, 'index'])->name('account.tickets');
        Route::post('/account/tickets', [SupportTicketController::class, 'store'])
            ->middleware('throttle:support-ticket')->name('account.tickets.store');
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
    Route::post('/admin/logout', [AdminAuthController::class, 'logout'])->name('admin.logout');

    Route::get('/admin/panel', AdminDashboardController::class)
        ->middleware('admin.permission:dashboard.view')->name('admin.panel');

    Route::middleware('admin.permission:notifications.view')->prefix('admin/notifications')
        ->name('admin.notifications.')->group(function (): void {
            Route::get('/', [AdminNotificationController::class, 'index'])->name('index');
            Route::post('/read-all', [AdminNotificationController::class, 'readAll'])->name('read-all');
            Route::post('/{id}/read', [AdminNotificationController::class, 'read'])->name('read');
        });

    Route::middleware('admin.permission:payments.manage')->group(function (): void {
        Route::get('/admin/payments', [AdminPaymentController::class, 'index'])->name('admin.payments.index');
        Route::post('/admin/payments/manual/{paymentId}/confirm', [AdminPaymentController::class, 'confirmManual'])
            ->name('admin.payments.manual.confirm');
    });

    Route::middleware('admin.permission:fulfillment.manage')->prefix('admin/fulfillment')
        ->name('admin.fulfillment.')->group(function (): void {
            Route::get('/', [AdminFulfillmentController::class, 'index'])->name('index');
            Route::post('/{attemptId}/complete', [AdminFulfillmentController::class, 'completeManual'])->name('complete');
            Route::post('/{attemptId}/fail', [AdminFulfillmentController::class, 'failManual'])->name('fail');
            Route::post('/{attemptId}/retry', [AdminFulfillmentController::class, 'retry'])->name('retry');
        });

    Route::get('/admin/catalog', [AdminCatalogController::class, 'index'])
        ->middleware('admin.permission:catalog.manage,content.manage')->name('admin.catalog.index');

    Route::middleware('admin.permission:catalog.manage')->prefix('admin/catalog')
        ->name('admin.catalog.')->group(function (): void {
            Route::post('/categories', [AdminCatalogController::class, 'category'])->name('categories.store');
            Route::put('/categories/{category}', [AdminCatalogController::class, 'updateCategory'])->name('categories.update');
            Route::post('/products', [AdminCatalogController::class, 'product'])->name('products.store');
            Route::put('/products/{product}', [AdminCatalogController::class, 'updateProduct'])->name('products.update');
            Route::post('/products/{product}/packages', [AdminCatalogController::class, 'package'])->name('packages.store');
            Route::put('/packages/{package}', [AdminCatalogController::class, 'updatePackage'])->name('packages.update');
            Route::put('/products/{product}/fields', [AdminCatalogController::class, 'fields'])->name('fields.update');
            Route::put('/mappings/{mapping}', [AdminCatalogController::class, 'mapping'])->name('mappings.update');
        });

    Route::middleware('admin.permission:content.manage')->prefix('admin/catalog')->name('admin.catalog.')
        ->group(function (): void {
            Route::put('/assets/{asset}', [AdminCatalogController::class, 'asset'])->name('assets.update');
            Route::post('/media/{type}/{id}', [AdminCatalogMediaController::class, 'store'])->name('media.store');
            Route::delete('/media/{type}/{id}', [AdminCatalogMediaController::class, 'destroy'])->name('media.destroy');
        });

    Route::middleware('admin.permission:providers.manage')->group(function (): void {
        Route::get('/admin/providers', [AdminWorkspaceController::class, 'providers'])->name('admin.providers');
        Route::put('/admin/providers/{id}', [AdminWorkspaceController::class, 'updateProvider'])->name('admin.providers.update');
    });

    Route::get('/admin/orders', [AdminWorkspaceController::class, 'orders'])
        ->middleware('admin.permission:orders.view')->name('admin.orders');

    Route::get('/admin/customers', [AdminWorkspaceController::class, 'customers'])
        ->middleware('admin.permission:customers.view')->name('admin.customers');

    Route::middleware('admin.super')->group(function (): void {
        Route::post('/admin/customers/{userId}/wallet', [AdminWorkspaceController::class, 'adjustWallet'])
            ->name('admin.customers.wallet');
        Route::put('/admin/customers/{userId}/membership', [AdminWorkspaceController::class, 'updateMembership'])
            ->name('admin.customers.membership');
    });

    Route::middleware('admin.permission:vouchers.manage')->prefix('admin/vouchers')
        ->name('admin.vouchers.')->group(function (): void {
            Route::get('/', [AdminWorkspaceController::class, 'vouchers'])->name('index');
            Route::post('/', [AdminWorkspaceController::class, 'storeVoucher'])->name('store');
            Route::put('/{id}', [AdminWorkspaceController::class, 'updateVoucher'])->name('update');
        });

    Route::middleware('admin.permission:support.manage')->prefix('admin/support')
        ->name('admin.support.')->group(function (): void {
            Route::get('/', [AdminWorkspaceController::class, 'support'])->name('index');
            Route::put('/{id}', [AdminWorkspaceController::class, 'updateSupport'])->name('update');
        });

    Route::get('/admin/reports', [AdminWorkspaceController::class, 'reports'])
        ->middleware('admin.permission:reports.view')->name('admin.reports');

    Route::middleware('admin.permission:settings.manage')->group(function (): void {
        Route::get('/admin/settings', [AdminWorkspaceController::class, 'settings'])->name('admin.settings');
        Route::put('/admin/settings', [AdminWorkspaceController::class, 'updateSettings'])->name('admin.settings.update');
        Route::put('/admin/settings/membership/{code}', [AdminWorkspaceController::class, 'updateTier'])
            ->name('admin.settings.membership.update');
    });

    Route::middleware(['admin.super', 'throttle:admin-sensitive'])->group(function (): void {
        Route::get('/admin/access', [AdminAccessController::class, 'index'])->name('admin.access');
        Route::post('/admin/access', [AdminAccessController::class, 'store'])->name('admin.access.store');
        Route::put('/admin/access/{admin}', [AdminAccessController::class, 'update'])->name('admin.access.update');

        Route::get('/admin/integrations', [AdminIntegrationController::class, 'index'])->name('admin.integrations');
        Route::put('/admin/integrations/{code}', [AdminIntegrationController::class, 'update'])->name('admin.integrations.update');
        Route::post('/admin/integrations/{code}/reveal/{field}', [AdminIntegrationController::class, 'reveal'])
            ->middleware('throttle:secret-reveal')->name('admin.integrations.reveal');
        Route::post('/admin/integrations/{code}/test', [AdminIntegrationController::class, 'test'])
            ->name('admin.integrations.test');

        Route::get('/admin/health', [AdminWorkspaceController::class, 'health'])->name('admin.health');
        Route::get('/admin/audit', [AdminWorkspaceController::class, 'audit'])->name('admin.audit');

        Route::prefix('admin/payments')->name('admin.payments.')->group(function (): void {
            Route::put('/gateways/{id}', [AdminPaymentController::class, 'gateway'])->name('gateways.update');
            Route::put('/channels/{id}', [AdminPaymentController::class, 'channel'])->name('channels.update');
            Route::post('/routes', [AdminPaymentController::class, 'route'])->name('routes.store');
            Route::put('/routes/{id}', [AdminPaymentController::class, 'updateRoute'])->name('routes.update');
            Route::put('/settings', [AdminPaymentController::class, 'settings'])->name('settings.update');
        });
    });
});
