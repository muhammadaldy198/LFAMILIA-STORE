<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AdminCompatController;
use App\Http\Controllers\AdminContentController;
use App\Http\Controllers\AdminCustomerController;
use App\Http\Controllers\AdminDigiflazzController;
use App\Http\Controllers\AdminOrdersController;
use App\Http\Controllers\AdminPaymentController;
use App\Http\Controllers\AdminProductController;
use App\Http\Controllers\AdminPromotionController;
use App\Http\Controllers\AdminReviewController;
use App\Http\Controllers\AdminSessionController;
use App\Http\Controllers\AdminSummaryController;
use App\Http\Controllers\AdminSupportController;
use App\Http\Controllers\AdminTeamController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DigiflazzCallbackController;
use App\Http\Controllers\DokuCallbackController;
use App\Http\Controllers\ExternalCheckoutController;
use App\Http\Controllers\GoogleAuthController;
use App\Http\Controllers\MidtransNotificationController;
use App\Http\Controllers\NicknameController;
use App\Http\Controllers\OrderSearchController;
use App\Http\Controllers\OrderStatusController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\PublicCompatController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\StorefrontController;
use App\Http\Controllers\SupportController;
use App\Http\Controllers\SystemStatusController;
use App\Http\Controllers\TurnstileController;
use App\Http\Controllers\WalletCheckoutController;
use App\Http\Controllers\WalletTopupController;
use Illuminate\Support\Facades\Route;

Route::get('/health', static fn () => response()->json([
    'ok' => true,
    'service' => 'lfamilia-laravel',
    'environment' => app()->environment(),
]));

Route::get('/system-status', SystemStatusController::class);
Route::get('/storefront', [StorefrontController::class, 'storefront']);
Route::get('/home-content', [StorefrontController::class, 'home']);
Route::get('/news', [StorefrontController::class, 'news']);
Route::get('/security/turnstile', [TurnstileController::class, 'show']);
Route::get('/account/summary', [PublicCompatController::class, 'accountSummary']);
Route::get('/account/membership', [PublicCompatController::class, 'membership']);
Route::match(['GET','POST','PATCH','DELETE'], '/account/game-accounts', [PublicCompatController::class, 'gameAccounts']);
Route::get('/leaderboard', [PublicCompatController::class, 'leaderboard']);
Route::get('/payment-methods', [PublicCompatController::class, 'paymentMethods']);
Route::get('/payment-page-settings', [PublicCompatController::class, 'paymentPageSettings']);
Route::get('/promotions', [PublicCompatController::class, 'promotions']);
Route::post('/promotions/quote', [PublicCompatController::class, 'promotionQuote']);
Route::get('/media/{key}', [PublicCompatController::class, 'media'])->where('key', 'media-[0-9a-fA-F-]{36}\\.(?:jpg|png|webp|gif)');

Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/logout', [AuthController::class, 'logout']);
Route::get('/auth/google/status', [GoogleAuthController::class, 'status']);
Route::post('/auth/google', [GoogleAuthController::class, 'login']);
Route::post('/auth/forgot-password', [PasswordResetController::class, 'request']);
Route::post('/auth/reset-password', [PasswordResetController::class, 'reset']);

Route::get('/account', [AccountController::class, 'show']);
Route::patch('/account', [AccountController::class, 'update']);
Route::get('/account/support', [SupportController::class, 'index']);
Route::post('/account/support', [SupportController::class, 'create']);
Route::post('/account/topups', [WalletTopupController::class, 'create']);

Route::get('/products', [ProductController::class, 'index']);
Route::get('/reviews', [ReviewController::class, 'index']);
Route::post('/reviews', [ReviewController::class, 'create']);
Route::post('/nickname', [NicknameController::class, 'verify']);
Route::post('/payments/wallet/create', [WalletCheckoutController::class, 'create']);
Route::post('/payments/auto/create', [ExternalCheckoutController::class, 'create']);
Route::get('/wallet', [WalletTopupController::class, 'settings']);

Route::get('/orders/search', [OrderSearchController::class, 'recent']);
Route::post('/orders/search', [OrderSearchController::class, 'search']);
Route::post('/orders/status', [OrderStatusController::class, 'show']);

Route::get('/admin/session', [AdminSessionController::class, 'session']);
Route::get('/admin/summary', [AdminSummaryController::class, 'show']);
Route::get('/admin/dashboard-integrations', [AdminCompatController::class, 'dashboardIntegrations']);
Route::match(['GET','PUT'], '/admin/integrations', [AdminCompatController::class, 'integrations']);
Route::post('/admin/media', [AdminCompatController::class, 'mediaUpload']);
Route::post('/admin/nickname-tools', [AdminCompatController::class, 'nicknameTools']);
Route::match(['GET','PUT'], '/admin/payment-page', [AdminCompatController::class, 'paymentPage']);
Route::match(['GET','PUT'], '/admin/product-content', [AdminCompatController::class, 'productContent']);
Route::match(['GET','PATCH'], '/admin/product-input', [AdminCompatController::class, 'productInput']);
Route::patch('/admin/product-package-provider', [AdminCompatController::class, 'packageProvider']);
Route::patch('/admin/product-package-status', [AdminCompatController::class, 'packageStatus']);
Route::match(['GET','PUT'], '/admin/wallet', [AdminCompatController::class, 'wallet']);
Route::get('/admin/wallet/proof', [AdminCompatController::class, 'walletProof']);
Route::get('/admin/orders', [AdminOrdersController::class, 'index']);
Route::post('/admin/orders', [AdminOrdersController::class, 'create']);
Route::patch('/admin/orders', [AdminOrdersController::class, 'update']);
Route::get('/admin/payment-routing', [AdminPaymentController::class, 'routing']);
Route::put('/admin/payment-routing', [AdminPaymentController::class, 'updateRouting']);
Route::get('/admin/payment-methods', [AdminPaymentController::class, 'methods']);
Route::post('/admin/payment-methods', [AdminPaymentController::class, 'saveMethod']);
Route::delete('/admin/payment-methods', [AdminPaymentController::class, 'deleteMethod']);
Route::get('/admin/products', [AdminProductController::class, 'index']);
Route::post('/admin/products', [AdminProductController::class, 'create']);
Route::patch('/admin/products', [AdminProductController::class, 'update']);
Route::delete('/admin/products', [AdminProductController::class, 'destroy']);

Route::get('/admin/members', [AdminCustomerController::class, 'members']);
Route::put('/admin/members', [AdminCustomerController::class, 'saveMemberSettings']);
Route::patch('/admin/members', [AdminCustomerController::class, 'updateMember']);
Route::delete('/admin/members', [AdminCustomerController::class, 'deleteMember']);
Route::get('/admin/balances', [AdminCustomerController::class, 'balances']);
Route::put('/admin/balances', [AdminCustomerController::class, 'adjustBalance']);
Route::get('/admin/customer-cleanup', [AdminCustomerController::class, 'cleanupSettings']);
Route::put('/admin/customer-cleanup', [AdminCustomerController::class, 'saveCleanupSettings']);
Route::post('/admin/customer-cleanup', [AdminCustomerController::class, 'runCleanup']);

Route::get('/admin/promotions', [AdminPromotionController::class, 'index']);
Route::post('/admin/promotions', [AdminPromotionController::class, 'save']);
Route::delete('/admin/promotions', [AdminPromotionController::class, 'destroy']);

Route::get('/admin/digiflazz-pricing', [AdminDigiflazzController::class, 'pricing']);
Route::post('/admin/digiflazz-pricing', [AdminDigiflazzController::class, 'syncPricing']);
Route::put('/admin/digiflazz-pricing', [AdminDigiflazzController::class, 'updatePricing']);
Route::get('/admin/digiflazz-monitor', [AdminDigiflazzController::class, 'monitor']);
Route::post('/admin/digiflazz-monitor', [AdminDigiflazzController::class, 'refreshMonitor']);

Route::get('/admin/reviews', [AdminReviewController::class, 'index']);
Route::patch('/admin/reviews', [AdminReviewController::class, 'update']);
Route::get('/admin/support', [AdminSupportController::class, 'index']);
Route::patch('/admin/support', [AdminSupportController::class, 'update']);
Route::get('/admin/content', [AdminContentController::class, 'content']);
Route::post('/admin/content', [AdminContentController::class, 'saveContent']);
Route::delete('/admin/content', [AdminContentController::class, 'deleteContent']);
Route::get('/admin/storefront', [AdminContentController::class, 'storefront']);
Route::put('/admin/storefront', [AdminContentController::class, 'saveStorefront']);
Route::get('/admin/faqs', [AdminContentController::class, 'faqs']);
Route::post('/admin/faqs', [AdminContentController::class, 'saveFaq']);
Route::delete('/admin/faqs', [AdminContentController::class, 'deleteFaq']);
Route::get('/admin/categories', [AdminContentController::class, 'categories']);
Route::post('/admin/categories', [AdminContentController::class, 'saveCategory']);
Route::delete('/admin/categories', [AdminContentController::class, 'deleteCategory']);
Route::get('/admin/team', [AdminTeamController::class, 'index']);
Route::post('/admin/team', [AdminTeamController::class, 'save']);
Route::delete('/admin/team', [AdminTeamController::class, 'destroy']);

Route::get('/payments/midtrans/snap/notification', [MidtransNotificationController::class, 'show']);
Route::post('/payments/midtrans/snap/notification', [MidtransNotificationController::class, 'handle']);
Route::get('/payments/doku/callback', [DokuCallbackController::class, 'show']);
Route::post('/payments/doku/callback', [DokuCallbackController::class, 'handle']);
Route::post('/fulfillment/digiflazz/callback', [DigiflazzCallbackController::class, 'handle']);

// Remaining migration work is focused on UI parity, background scheduler/process
// deployment, production data import, and final VPS cutover validation.
