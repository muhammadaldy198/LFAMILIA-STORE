<?php

use App\Http\Controllers\AdminAccessController;
use App\Http\Controllers\AdminActivationController;
use App\Http\Controllers\AdminAuditController;
use App\Http\Controllers\AdminAuthController;
use App\Http\Controllers\AdminCatalogController;
use App\Http\Controllers\AdminCatalogMediaController;
use App\Http\Controllers\AdminContentController;
use App\Http\Controllers\AdminCustomerController;
use App\Http\Controllers\AdminDashboardController;
use App\Http\Controllers\AdminDigiflazzController;
use App\Http\Controllers\AdminFulfillmentController;
use App\Http\Controllers\AdminHealthController;
use App\Http\Controllers\AdminIntegrationController;
use App\Http\Controllers\AdminNicknameController;
use App\Http\Controllers\AdminNotificationController;
use App\Http\Controllers\AdminOrderDetailController;
use App\Http\Controllers\AdminOrdersController;
use App\Http\Controllers\AdminPaymentController;
use App\Http\Controllers\AdminPresentationController;
use App\Http\Controllers\AdminPromotionController;
use App\Http\Controllers\AdminProviderController;
use App\Http\Controllers\AdminReportController;
use App\Http\Controllers\AdminSearchController;
use App\Http\Controllers\AdminSettingsController;
use App\Http\Controllers\AdminSupportController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\CustomerAccountController;
use App\Http\Controllers\CustomerOrderController;
use App\Http\Controllers\CustomerPhoneController;
use App\Http\Controllers\GoogleAuthController;
use App\Http\Controllers\GuestOrderController;
use App\Http\Controllers\GuestSupportController;
use App\Http\Controllers\LegacyFrontendController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PaymentPageController;
use App\Http\Controllers\ProductReviewController;
use App\Http\Controllers\PublicContentController;
use App\Http\Controllers\PublicOrderTrackingController;
use App\Http\Controllers\SavedGameAccountController;
use App\Http\Controllers\SupportTicketController;
use App\Http\Controllers\WalletTopupController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;

Route::get('/', [CatalogController::class, 'index'])->name('catalog.index');
Route::get('/catalog', [LegacyFrontendController::class, 'catalog'])->name('legacy.catalog');
Route::get('/checkout', [LegacyFrontendController::class, 'checkout'])->name('legacy.checkout');
Route::get('/track', [LegacyFrontendController::class, 'track'])->name('legacy.track');
Route::get('/catalog/{slug}', [CatalogController::class, 'show'])->name('catalog.show');
Route::get('/news', [PublicContentController::class, 'news'])->name('content.news');
Route::get('/news/{slug}', [PublicContentController::class, 'article'])->name('content.article');
Route::get('/faq', [PublicContentController::class, 'faq'])->name('content.faq');
Route::get('/contact', [PublicContentController::class, 'contact'])->name('content.contact');
Route::get('/support', [GuestSupportController::class, 'index'])->name('guest.support');
Route::post('/support/verify', [GuestSupportController::class, 'verify'])->middleware('throttle:support-ticket')->name('guest.support.verify');
Route::post('/support', [GuestSupportController::class, 'store'])->middleware('throttle:support-ticket')->name('guest.support.store');
Route::post('/support/{ticket}/messages', [GuestSupportController::class, 'reply'])->middleware('throttle:support-ticket')->name('guest.support.reply');
Route::get('/terms', [PublicContentController::class, 'terms'])->name('content.terms');
Route::get('/refund', [PublicContentController::class, 'refund'])->name('content.refund');
Route::get('/privacy', [PublicContentController::class, 'privacy'])->name('content.privacy');
Route::get('/leaderboard', [PublicContentController::class, 'leaderboard'])->name('content.leaderboard');
Route::get('/promo', [PublicContentController::class, 'promo'])->name('content.promo');
Route::get('/status', [PublicContentController::class, 'status'])->name('content.status');
Route::get('/tools', [PublicContentController::class, 'tools'])->name('content.tools');
Route::get('/tools/{tool}', [PublicContentController::class, 'tool'])->name('content.tool');

Route::post('/checkout/nickname', [CheckoutController::class, 'nickname'])
    ->middleware('throttle:checkout-nickname')->name('checkout.nickname');
Route::post('/checkout/quote', [CheckoutController::class, 'quote'])
    ->middleware('throttle:checkout-quote')->name('checkout.quote');
Route::match(['get', 'post'], '/checkout/vouchers', [CheckoutController::class, 'vouchers'])
    ->middleware('throttle:checkout-quote')->name('checkout.vouchers');
Route::post('/checkout/orders', [CheckoutController::class, 'store'])
    ->middleware('throttle:checkout-create')->name('checkout.store');
Route::post('/payments/orders/{orderNumber}', [PaymentController::class, 'create'])
    ->middleware('throttle:payment-create')->name('payments.orders.create');
Route::get('/payment', [PaymentPageController::class, 'show'])->name('payment.show');

Route::get('/health/ready', function () {
    try {
        DB::select('SELECT 1');
        Redis::connection()->ping();

        return response()->json(['status' => 'healthy']);
    } catch (Throwable $exception) {
        Log::warning('Readiness check failed.', ['exception_class' => $exception::class]);

        return response()->json(['status' => 'unavailable'], 503);
    }
});

Route::middleware('throttle:guest-order')->group(function (): void {
    Route::get('/orders/check', [PublicOrderTrackingController::class, 'index'])->name('guest.orders.lookup');
    Route::post('/orders/check', [GuestOrderController::class, 'verify'])->name('guest.orders.verify');
    Route::post('/orders/track/search', [PublicOrderTrackingController::class, 'search'])->name('guest.orders.track.search');
    Route::get('/orders/track/feed', [PublicOrderTrackingController::class, 'feed'])->name('guest.orders.track.feed');
    Route::post('/orders/track/status', [PublicOrderTrackingController::class, 'status'])->name('guest.orders.track.status');
    Route::post('/reviews', [ProductReviewController::class, 'store'])->name('reviews.store');
});
Route::get('/orders/guest/{orderNumber}', [GuestOrderController::class, 'show'])
    ->name('guest.orders.show');
Route::get('/orders/guest/{orderNumber}/events', [GuestOrderController::class, 'events'])
    ->middleware('throttle:guest-order')->name('guest.orders.events');

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
        Route::put('/account/profile', [CustomerAccountController::class, 'update'])
            ->middleware('throttle:account-sensitive')->name('account.profile.update');
        Route::put('/account/password', [CustomerAccountController::class, 'password'])
            ->middleware('throttle:account-sensitive')->name('account.password.update');
        Route::delete('/account', [CustomerAccountController::class, 'destroy'])
            ->middleware('throttle:account-sensitive')->name('account.destroy');
        Route::get('/account/wallet', [CustomerAccountController::class, 'wallet'])->name('account.wallet');
        Route::post('/account/wallet/topups/quote', [WalletTopupController::class, 'quote'])
            ->middleware('throttle:wallet-topup')->name('account.wallet.topups.quote');
        Route::post('/account/wallet/topups', [WalletTopupController::class, 'store'])
            ->middleware('throttle:wallet-topup')->name('account.wallet.topups.store');
        Route::get('/account/membership', [CustomerAccountController::class, 'membership'])->name('account.membership');
        Route::get('/account/codes', [CustomerAccountController::class, 'codes'])->name('account.codes');
        Route::get('/account/game-accounts', [CustomerAccountController::class, 'gameAccounts'])->name('account.game-accounts');
        Route::get('/account/notifications', [CustomerAccountController::class, 'notifications'])->name('account.notifications');
        Route::get('/account/orders', [CustomerOrderController::class, 'index'])->name('account.orders');
        Route::get('/account/orders/{order}', [CustomerOrderController::class, 'show'])->name('account.orders.show');
        Route::get('/account/tickets', [SupportTicketController::class, 'index'])->name('account.tickets');
        Route::post('/account/tickets', [SupportTicketController::class, 'store'])
            ->middleware('throttle:support-ticket')->name('account.tickets.store');
        Route::get('/account/tickets/{ticket}', [SupportTicketController::class, 'show'])
            ->name('account.tickets.show');
        Route::post('/account/tickets/{ticket}/messages', [SupportTicketController::class, 'reply'])
            ->middleware('throttle:support-ticket')->name('account.tickets.reply');
        Route::post('/account/game-accounts', [SavedGameAccountController::class, 'store'])
            ->middleware('throttle:account-sensitive')->name('account.game-accounts.store');
        Route::put('/account/game-accounts/{savedGameAccount}', [SavedGameAccountController::class, 'update'])
            ->middleware('throttle:account-sensitive')->name('account.game-accounts.update');
        Route::delete('/account/game-accounts/{savedGameAccount}', [SavedGameAccountController::class, 'destroy'])
            ->middleware('throttle:account-sensitive')->name('account.game-accounts.destroy');
    });
});

Route::redirect('/admin', '/admin/panel', 302);
Route::redirect('/admin/panel/login', '/admin/login', 302);
Route::redirect('/panel', '/admin/panel', 302);
Route::redirect('/panel/login', '/admin/login', 302);
Route::redirect('/panel/admin', '/admin/panel', 302);
Route::redirect('/panel/admin/login', '/admin/login', 302);

Route::get('/admin/activate', [AdminActivationController::class, 'show'])->middleware('throttle:admin-login');
Route::post('/admin/activate', [AdminActivationController::class, 'store'])->middleware('throttle:admin-login');

Route::middleware('guest:admin')->group(function (): void {
    Route::get('/admin/login', [AdminAuthController::class, 'show'])->name('admin.login');
    Route::post('/admin/login', [AdminAuthController::class, 'login'])
        ->middleware('throttle:admin-login')->name('admin.login.store');
});

Route::middleware(['auth:admin', 'admin.role'])->group(function (): void {
    Route::get('/admin/search', AdminSearchController::class)->middleware('throttle:admin-sensitive')->name('admin.search');
    Route::post('/admin/logout', [AdminAuthController::class, 'logout'])->name('admin.logout');

    Route::get('/admin/panel', AdminDashboardController::class)
        ->middleware('admin.permission:dashboard.view')->name('admin.panel');

    Route::middleware('admin.permission:notifications.view')->prefix('admin/notifications')
        ->name('admin.notifications.')->group(function (): void {
            Route::get('/', [AdminNotificationController::class, 'index'])->name('index');
            Route::post('/read-all', [AdminNotificationController::class, 'readAll'])->name('read-all');
            Route::post('/{id}/read', [AdminNotificationController::class, 'read'])->name('read');
        });

    Route::middleware('admin.super')->prefix('admin/nickname-tools')
        ->name('admin.nickname-tools.')->group(function (): void {
            Route::get('/', [AdminNicknameController::class, 'index'])->name('index');
            Route::post('/check', [AdminNicknameController::class, 'check'])
                ->middleware('throttle:admin-sensitive')->name('check');
            Route::post('/game-codes', [AdminNicknameController::class, 'storeGameCode'])
                ->middleware('throttle:admin-sensitive')->name('game-codes.store');
            Route::put('/game-codes/reorder', [AdminNicknameController::class, 'reorderGameCodes'])
                ->middleware('throttle:admin-sensitive')->name('game-codes.reorder');
            Route::put('/game-codes/{gameCode}', [AdminNicknameController::class, 'updateGameCode'])
                ->middleware('throttle:admin-sensitive')->name('game-codes.update');
            Route::delete('/game-codes/{gameCode}', [AdminNicknameController::class, 'destroyGameCode'])
                ->middleware('throttle:admin-sensitive')->name('game-codes.destroy');
        });

    Route::middleware('admin.permission:payments.manage')->prefix('admin/payments')
        ->name('admin.payments.')->group(function (): void {
            Route::get('/', [AdminPaymentController::class, 'index'])->name('index');
            Route::post('/manual/{paymentId}/confirm', [AdminPaymentController::class, 'confirmManual'])
                ->middleware('throttle:admin-sensitive')->name('manual.confirm');
            Route::post('/manual-qris/image', [AdminPaymentController::class, 'uploadManualQris'])
                ->middleware('throttle:admin-sensitive')->name('manual-qris.image');
            Route::put('/manual-qris', [AdminPaymentController::class, 'toggleManualQris'])
                ->middleware('throttle:admin-sensitive')->name('manual-qris.update');

            Route::put('/page-settings', [AdminPaymentController::class, 'pageSettings'])
                ->name('page-settings.update');
            Route::post('/page-settings/header', [AdminPaymentController::class, 'uploadPageHeader'])
                ->name('page-settings.header');

        });

    Route::middleware(['admin.permission:payments.manage', 'admin.super'])->prefix('admin/payments')
        ->name('admin.payments.')->group(function (): void {
            Route::post('/channels/sync', [AdminPaymentController::class, 'syncChannels'])
                ->middleware('throttle:admin-sensitive')->name('channels.sync');
            Route::post('/channels', [AdminPaymentController::class, 'storeChannel'])
                ->middleware('throttle:admin-sensitive')->name('channels.store');
            Route::put('/channels/{id}', [AdminPaymentController::class, 'channel'])
                ->middleware('throttle:admin-sensitive')->name('channels.update');
            Route::delete('/channels/{id}', [AdminPaymentController::class, 'destroyChannel'])
                ->middleware('throttle:admin-sensitive')->name('channels.destroy');
            Route::post('/channels/{id}/logo', [AdminPaymentController::class, 'uploadChannelLogo'])
                ->middleware('throttle:admin-sensitive')->name('channels.logo');
            Route::put('/gateways/{id}', [AdminPaymentController::class, 'gateway'])
                ->middleware('throttle:admin-sensitive')->name('gateways.update');
            Route::put('/routes/{id}', [AdminPaymentController::class, 'updateRoute'])
                ->middleware('throttle:admin-sensitive')->name('routes.update');
            Route::put('/settings', [AdminPaymentController::class, 'settings'])
                ->middleware('throttle:admin-sensitive')->name('settings.update');
        });
    Route::middleware('admin.permission:fulfillment.manage')->prefix('admin/fulfillment')
        ->name('admin.fulfillment.')->group(function (): void {
            Route::get('/', [AdminFulfillmentController::class, 'index'])->name('index');
            Route::post('/{attemptId}/complete', [AdminFulfillmentController::class, 'completeManual'])
                ->middleware('throttle:admin-sensitive')->name('complete');
            Route::post('/{attemptId}/fail', [AdminFulfillmentController::class, 'failManual'])
                ->middleware('throttle:admin-sensitive')->name('fail');
            Route::post('/{attemptId}/reconcile', [AdminFulfillmentController::class, 'reconcile'])
                ->middleware('throttle:admin-sensitive')->name('reconcile');
            Route::post('/{attemptId}/retry', [AdminFulfillmentController::class, 'retry'])
                ->middleware('throttle:admin-sensitive')->name('retry');
        });

    Route::get('/admin/catalog', [AdminCatalogController::class, 'index'])
        ->middleware('admin.permission:catalog.manage')->name('admin.catalog.index');

    Route::middleware('admin.permission:catalog.manage')->prefix('admin/catalog')
        ->name('admin.catalog.')->group(function (): void {
            Route::put('/margin', [AdminCatalogController::class, 'globalMargin']);
            Route::put('/products/{product}/packages/reorder', [AdminCatalogController::class, 'reorderPackages']);
            Route::post('/products/{product}/sync', [AdminDigiflazzController::class, 'syncProduct'])->middleware('throttle:admin-sensitive');
            Route::post('/products/{product}/import', [AdminDigiflazzController::class, 'import']);
            Route::post('/mappings/{mapping}/sync', [AdminDigiflazzController::class, 'syncMapping'])->middleware('throttle:admin-sensitive');
            Route::post('/categories', [AdminCatalogController::class, 'category'])->name('categories.store');
            Route::put('/categories/{category}', [AdminCatalogController::class, 'updateCategory'])->name('categories.update');
            Route::delete('/categories/{category}', [AdminCatalogController::class, 'destroyCategory'])->name('categories.destroy');
            Route::post('/products', [AdminCatalogController::class, 'product'])->name('products.store');
            Route::put('/products/{product}', [AdminCatalogController::class, 'updateProduct'])->name('products.update');
            Route::delete('/products/{product}', [AdminCatalogController::class, 'destroyProduct'])->name('products.destroy');
            Route::post('/products/{product}/packages', [AdminCatalogController::class, 'package'])->name('packages.store');
            Route::post('/packages/{package}/voucher-stock', [AdminCatalogController::class, 'voucherStock'])->name('packages.voucher-stock');
            Route::put('/packages/{package}', [AdminCatalogController::class, 'updatePackage'])->name('packages.update');
            Route::delete('/packages/{package}', [AdminCatalogController::class, 'destroyPackage'])->name('packages.destroy');
            Route::post('/packages/{package}/duplicate', [AdminCatalogController::class, 'duplicatePackage'])->name('packages.duplicate');
            Route::put('/products/{product}/fields', [AdminCatalogController::class, 'fields'])->name('fields.update');
            Route::post('/products/{product}/notices', [AdminCatalogController::class, 'storeNotice'])->name('notices.store');
            Route::put('/notices/{notice}', [AdminCatalogController::class, 'updateNotice'])->name('notices.update');
            Route::delete('/notices/{notice}', [AdminCatalogController::class, 'destroyNotice'])->name('notices.destroy');
            Route::put('/mappings/{mapping}', [AdminCatalogController::class, 'mapping'])->name('mappings.update');
        });

    Route::middleware('admin.permission:content.manage')->group(function (): void {
        Route::get('/admin/content/presentation', [AdminPresentationController::class, 'index'])->name('admin.content.presentation');
        Route::put('/admin/content/presentation', [AdminPresentationController::class, 'update'])->name('admin.content.presentation.update');
        Route::get('/admin/content', [AdminContentController::class, 'index'])->name('admin.content');
        Route::put('/admin/content/settings', [AdminContentController::class, 'updateSettings'])->name('admin.content.settings');
        Route::post('/admin/content/banners', [AdminContentController::class, 'storeBanner'])->name('admin.content.banners.store');
        Route::put('/admin/content/banners/{banner}', [AdminContentController::class, 'updateBanner'])->name('admin.content.banners.update');
        Route::delete('/admin/content/banners/{banner}', [AdminContentController::class, 'destroyBanner'])->name('admin.content.banners.destroy');
        Route::post('/admin/content/popups', [AdminContentController::class, 'storePopup'])->name('admin.content.popups.store');
        Route::put('/admin/content/popups/{popup}', [AdminContentController::class, 'updatePopup'])->name('admin.content.popups.update');
        Route::delete('/admin/content/popups/{popup}', [AdminContentController::class, 'destroyPopup'])->name('admin.content.popups.destroy');
        Route::post('/admin/content/news', [AdminContentController::class, 'storeNews'])->name('admin.content.news.store');
        Route::put('/admin/content/news/{news}', [AdminContentController::class, 'updateNews'])->name('admin.content.news.update');
        Route::delete('/admin/content/news/{news}', [AdminContentController::class, 'destroyNews'])->name('admin.content.news.destroy');
        Route::post('/admin/content/faqs', [AdminContentController::class, 'storeFaq'])->name('admin.content.faqs.store');
        Route::put('/admin/content/faqs/{faq}', [AdminContentController::class, 'updateFaq'])->name('admin.content.faqs.update');
        Route::delete('/admin/content/faqs/{faq}', [AdminContentController::class, 'destroyFaq'])->name('admin.content.faqs.destroy');
        Route::put('/admin/content/reviews/{review}', [AdminContentController::class, 'updateReview'])->name('admin.content.reviews.update');
        Route::put('/admin/content/pages/{key}', [AdminContentController::class, 'updatePage'])->name('admin.content.pages.update');

        Route::prefix('admin/catalog')->name('admin.catalog.')->group(function (): void {
            Route::put('/assets/{asset}', [AdminCatalogController::class, 'asset'])->name('assets.update');
            Route::post('/media/{type}/{id}', [AdminCatalogMediaController::class, 'store'])->name('media.store');
            Route::delete('/media/{type}/{id}', [AdminCatalogMediaController::class, 'destroy'])->name('media.destroy');
        });
    });

    Route::middleware('admin.permission:providers.manage')->group(function (): void {
        Route::get('/admin/digiflazz', [AdminDigiflazzController::class, 'index'])->name('admin.digiflazz');
        Route::put('/admin/digiflazz/settings', [AdminDigiflazzController::class, 'settings']);
        Route::post('/admin/digiflazz/sync', [AdminDigiflazzController::class, 'sync'])->middleware('throttle:admin-sensitive');
        Route::put('/admin/digiflazz/baseline/{id}', [AdminDigiflazzController::class, 'baseline'])
            ->middleware('throttle:admin-sensitive');

        Route::get('/admin/providers', [AdminProviderController::class, 'index'])->name('admin.providers');
        Route::put('/admin/providers/{provider}', [AdminProviderController::class, 'update'])->name('admin.providers.update');
    });

    Route::get('/admin/orders/export', [AdminOrdersController::class, 'export'])
        ->middleware('admin.permission:orders.view')->name('admin.orders.export');
    Route::post('/admin/orders/manual', [AdminOrdersController::class, 'manual'])
        ->middleware(['admin.permission:orders.view', 'admin.permission:fulfillment.manage', 'admin.permission:payments.manage', 'throttle:30,1'])->name('admin.orders.manual');
    Route::post('/admin/orders/{id}/check-payment', [AdminOrdersController::class, 'refreshPayment'])
        ->middleware(['admin.permission:orders.view', 'admin.permission:payments.manage', 'throttle:10,1'])->name('admin.orders.check-payment');
    Route::post('/admin/orders/{id}/check-process', [AdminOrdersController::class, 'refreshFulfillment'])
        ->middleware(['admin.permission:orders.view', 'admin.permission:fulfillment.manage', 'throttle:10,1'])->name('admin.orders.check-process');
    Route::post('/admin/orders/{id}/resend-delivery', [AdminOrdersController::class, 'resendDelivery'])
        ->middleware(['admin.permission:orders.view', 'admin.permission:fulfillment.manage', 'throttle:10,1'])->name('admin.orders.resend-delivery');
    Route::get('/admin/orders/{id}', AdminOrderDetailController::class)->middleware('admin.permission:orders.view')->name('admin.orders.show');
    Route::get('/admin/orders', [AdminOrdersController::class, 'index'])
        ->middleware('admin.permission:orders.view')->name('admin.orders');

    Route::get('/admin/customers', [AdminCustomerController::class, 'index'])
        ->middleware('admin.permission:customers.view')->name('admin.customers');
    Route::get('/admin/customers/{userId}', [AdminCustomerController::class, 'show'])
        ->middleware('admin.permission:customers.view')->name('admin.customers.show');

    Route::middleware(['admin.permission:customers.view', 'admin.super'])->group(function (): void {
        Route::put('/admin/customers/cleanup/settings', [AdminCustomerController::class, 'cleanupSettings'])
            ->name('admin.customers.cleanup.settings');
        Route::post('/admin/customers/cleanup/run', [AdminCustomerController::class, 'cleanup'])
            ->middleware('throttle:admin-sensitive')->name('admin.customers.cleanup.run');
        Route::delete('/admin/customers/{userId}', [AdminCustomerController::class, 'destroy'])
            ->middleware('throttle:admin-sensitive')->name('admin.customers.destroy');
        Route::post('/admin/customers/{userId}/wallet', [AdminCustomerController::class, 'adjustWallet'])
            ->middleware('throttle:admin-sensitive')->name('admin.customers.wallet');
        Route::put('/admin/customers/{userId}/membership', [AdminCustomerController::class, 'updateMembership'])
            ->middleware('throttle:admin-sensitive')->name('admin.customers.membership');
    });

    Route::middleware('admin.permission:vouchers.manage')->prefix('admin/vouchers')
        ->name('admin.vouchers.')->group(function (): void {
            Route::get('/', [AdminPromotionController::class, 'index'])->name('index');
            Route::post('/', [AdminPromotionController::class, 'store'])->name('store');
            Route::put('/popular/{productId}', [AdminPromotionController::class, 'updatePopular'])
                ->name('popular.update');
            Route::put('/{id}', [AdminPromotionController::class, 'update'])->name('update');
            Route::delete('/{id}', [AdminPromotionController::class, 'destroy'])->name('destroy');
        });

    Route::middleware('admin.permission:support.manage')->prefix('admin/support')
        ->name('admin.support.')->group(function (): void {
            Route::get('/', [AdminSupportController::class, 'index'])->name('index');
            Route::put('/quick-replies', [AdminSupportController::class, 'quickReplies'])
                ->name('quick-replies.update');
            Route::put('/{id}', [AdminSupportController::class, 'update'])->name('update');
        });

    Route::middleware('admin.permission:reports.view')->group(function (): void {
        Route::get('/admin/reports', [AdminReportController::class, 'index'])->name('admin.reports');
        Route::get('/admin/reports/export', [AdminReportController::class, 'export'])
            ->name('admin.reports.export');
    });

    Route::middleware('admin.permission:settings.manage')->group(function (): void {
        Route::get('/admin/settings', [AdminSettingsController::class, 'index'])->name('admin.settings');
        Route::put('/admin/settings', [AdminSettingsController::class, 'updateStore'])
            ->name('admin.settings.update');
        Route::put('/admin/settings/membership/{code}', [AdminSettingsController::class, 'updateTier'])
            ->name('admin.settings.membership.update');
    });

    Route::middleware('admin.super')->group(function (): void {
        Route::get('/admin/access', [AdminAccessController::class, 'index'])->name('admin.access');
        Route::post('/admin/access', [AdminAccessController::class, 'store'])
            ->middleware('throttle:admin-sensitive')->name('admin.access.store');
        Route::put('/admin/access/{admin}', [AdminAccessController::class, 'update'])
            ->middleware('throttle:admin-sensitive')->name('admin.access.update');
        Route::delete('/admin/access/{admin}', [AdminAccessController::class, 'destroy'])
            ->middleware('throttle:admin-sensitive')->name('admin.access.destroy');

        Route::get('/admin/integrations', [AdminIntegrationController::class, 'index'])->name('admin.integrations');
        Route::put('/admin/integrations/{code}', [AdminIntegrationController::class, 'update'])
            ->middleware('throttle:admin-sensitive')->name('admin.integrations.update');
        Route::post('/admin/integrations/{code}/reveal/{field}', [AdminIntegrationController::class, 'reveal'])
            ->middleware('throttle:secret-reveal')->name('admin.integrations.reveal');
        Route::post('/admin/integrations/{code}/test', [AdminIntegrationController::class, 'test'])
            ->middleware('throttle:admin-sensitive')->name('admin.integrations.test');

        Route::get('/admin/health', [AdminHealthController::class, 'index'])->name('admin.health');
        Route::get('/admin/audit', [AdminAuditController::class, 'index'])->name('admin.audit');
        Route::get('/admin/configuration/export', [AdminSettingsController::class, 'exportConfiguration'])
            ->middleware('throttle:admin-sensitive')->name('admin.configuration.export');

    });
});
