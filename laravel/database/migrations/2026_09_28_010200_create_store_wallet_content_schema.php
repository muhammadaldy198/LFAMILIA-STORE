<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('store_name', 191);
            $table->string('store_short_name', 64);
            $table->string('tagline', 255);
            $table->text('logo_url')->nullable();
            $table->text('announcement')->nullable();
            $table->boolean('banner_enabled')->default(true);
            $table->string('banner_eyebrow', 191);
            $table->string('banner_title', 191);
            $table->string('banner_highlight', 191);
            $table->text('banner_description');
            $table->text('banner_image_url')->nullable();
            $table->string('banner_cta_label', 191);
            $table->text('banner_cta_href');
            $table->string('support_whatsapp', 64)->nullable();
            $table->string('support_email', 191)->nullable();
            $table->text('instagram_url')->nullable();
            $table->text('discord_url')->nullable();
            $table->string('support_hours', 191);
            $table->boolean('support_widget_enabled')->default(true);
            $table->string('merchant_legal_name', 191)->nullable();
            $table->string('merchant_registration_id', 191)->nullable();
            $table->text('merchant_address')->nullable();
            $table->timestamp('updated_at')->useCurrent();
        });

        Schema::create('wallet_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->boolean('is_enabled')->default(false);
            $table->string('method_name', 96)->default('Transfer Bank');
            $table->string('account_name', 191)->default('');
            $table->string('account_number', 191)->default('');
            $table->longText('instructions')->nullable();
            $table->bigInteger('min_topup')->default(10000);
            $table->boolean('doku_topup_enabled')->default(false);
            $table->boolean('doku_checkout_enabled')->default(false);
            $table->timestamp('updated_at')->useCurrent();
        });

        Schema::create('wallet_topups', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->string('customer_id', 64);
            $table->bigInteger('amount');
            $table->string('sender_name', 191);
            $table->string('payment_method', 64);
            $table->text('proof_url');
            $table->string('source', 32)->default('manual');
            $table->string('reference_id', 191)->nullable()->unique();
            $table->string('external_checkout_key', 191)->nullable();
            $table->string('payment_gateway', 32)->nullable();
            $table->string('payment_gateway_mode', 32)->nullable();
            $table->string('gateway_environment', 32)->nullable();
            $table->string('gateway_request_id', 191)->nullable();
            $table->string('gateway_reference_no', 191)->nullable();
            $table->string('gateway_payment_no', 191)->nullable();
            $table->longText('gateway_qr_content')->nullable();
            $table->string('gateway_payment_name', 191)->nullable();
            $table->text('gateway_payment_url')->nullable();
            $table->timestamp('gateway_expired_at')->nullable();
            $table->string('doku_environment', 32)->nullable();
            $table->string('doku_request_id', 191)->nullable();
            $table->string('doku_token_id', 191)->nullable();
            $table->string('doku_reference_no', 191)->nullable();
            $table->string('doku_payment_no', 191)->nullable();
            $table->longText('doku_qr_content')->nullable();
            $table->string('doku_payment_name', 191)->nullable();
            $table->text('doku_payment_url')->nullable();
            $table->timestamp('doku_expired_at')->nullable();
            $table->timestamp('doku_status_checked_at')->nullable();
            $table->bigInteger('payment_fee')->default(0);
            $table->bigInteger('payment_total')->default(0);
            $table->string('status', 32)->default('pending');
            $table->text('admin_notes')->nullable();
            $table->string('reviewed_by', 191)->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->foreign('customer_id')->references('id')->on('customer_users')->cascadeOnDelete();
            $table->index(['customer_id', 'created_at'], 'wallet_topups_customer_created_idx');
            $table->index(['status', 'created_at'], 'wallet_topups_status_created_idx');
            $table->index(['payment_gateway', 'status', 'created_at'], 'wallet_topups_gateway_status_idx');
            $table->unique(['customer_id', 'external_checkout_key'], 'wallet_topups_external_checkout_key_unique');
        });

        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->string('customer_id', 64);
            $table->string('direction', 16);
            $table->bigInteger('amount');
            $table->bigInteger('balance_before');
            $table->bigInteger('balance_after');
            $table->string('reference', 191)->unique();
            $table->text('description');
            $table->timestamp('created_at')->useCurrent();
            $table->foreign('customer_id')->references('id')->on('customer_users')->cascadeOnDelete();
            $table->index(['customer_id', 'created_at'], 'wallet_transactions_customer_created_idx');
        });

        Schema::create('product_reviews', function (Blueprint $table) {
            $table->id();
            $table->string('customer_id', 64)->nullable();
            $table->string('order_id', 64)->nullable()->unique();
            $table->string('reviewer_name', 191)->default('Pelanggan');
            $table->string('product_slug', 191);
            $table->unsignedTinyInteger('rating');
            $table->string('title', 191)->nullable();
            $table->longText('body');
            $table->boolean('is_verified_purchase')->default(true);
            $table->boolean('is_visible')->default(true);
            $table->timestamps();
            $table->foreign('customer_id')->references('id')->on('customer_users')->nullOnDelete();
            $table->foreign('order_id')->references('id')->on('orders')->nullOnDelete();
            $table->unique(['customer_id', 'product_slug'], 'product_reviews_customer_product_unique');
            $table->index(['product_slug', 'is_visible', 'created_at'], 'product_reviews_product_visible_idx');
        });

        Schema::create('home_banners', function (Blueprint $table) {
            $table->id();
            $table->string('title', 191);
            $table->string('subtitle', 255)->default('');
            $table->text('image_url');
            $table->string('cta_label', 191)->default('');
            $table->text('cta_href')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->index(['is_active', 'sort_order'], 'home_banners_active_sort_idx');
        });

        Schema::create('site_popups', function (Blueprint $table) {
            $table->id();
            $table->string('title', 191);
            $table->longText('body');
            $table->string('primary_label', 191)->nullable();
            $table->text('primary_href')->nullable();
            $table->string('secondary_label', 191)->nullable();
            $table->text('secondary_href')->nullable();
            $table->unsignedSmallInteger('dismiss_days')->default(7);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->index(['is_active', 'sort_order'], 'site_popups_active_sort_idx');
        });

        Schema::create('news_articles', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 191)->unique();
            $table->string('title', 191);
            $table->text('summary');
            $table->longText('body');
            $table->text('cover_url')->nullable();
            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->index(['is_published', 'published_at', 'sort_order'], 'news_articles_published_sort_idx');
        });

        Schema::create('product_categories', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 191)->unique();
            $table->string('name', 191);
            $table->string('icon', 64)->default('grid');
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->index(['is_active', 'sort_order'], 'product_categories_active_sort_idx');
        });

        Schema::create('discount_vouchers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 191)->unique();
            $table->string('name', 191);
            $table->text('description');
            $table->string('discount_type', 32);
            $table->bigInteger('discount_value');
            $table->bigInteger('min_purchase')->default(0);
            $table->bigInteger('max_discount')->nullable();
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->unsignedInteger('reserved_count')->default(0);
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['is_active', 'starts_at', 'ends_at'], 'discount_vouchers_active_period_idx');
        });

        Schema::create('flash_sales', function (Blueprint $table) {
            $table->id();
            $table->string('product_slug', 191);
            $table->string('package_sku', 191);
            $table->bigInteger('sale_price');
            $table->string('badge', 96)->default('Flash Sale');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->unsignedInteger('stock_limit')->nullable();
            $table->unsignedInteger('sold_count')->default(0);
            $table->unsignedInteger('reserved_count')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['product_slug', 'package_sku'], 'flash_sales_product_package_idx');
            $table->index(['is_active', 'starts_at', 'ends_at'], 'flash_sales_active_period_idx');
        });

        Schema::create('promotion_reservations', function (Blueprint $table) {
            $table->string('order_id', 64)->primary();
            $table->string('voucher_code', 191)->nullable();
            $table->unsignedBigInteger('flash_sale_id')->nullable();
            $table->string('status', 32);
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            $table->index(['status', 'expires_at'], 'promotion_reservations_expiry_idx');
        });

        Schema::create('admin_users', function (Blueprint $table) {
            $table->id();
            $table->string('email', 191)->unique();
            $table->string('name', 191);
            $table->string('role', 32);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['role', 'is_active'], 'admin_users_role_active_idx');
        });

        Schema::create('faq_entries', function (Blueprint $table) {
            $table->id();
            $table->string('question', 255);
            $table->longText('answer');
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->index(['is_active', 'sort_order'], 'faq_entries_active_sort_idx');
        });

        Schema::create('customer_support_requests', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->string('customer_id', 64);
            $table->string('kind', 64);
            $table->string('order_reference', 191)->nullable();
            $table->string('subject', 255);
            $table->longText('message');
            $table->string('status', 32)->default('open');
            $table->longText('staff_reply')->nullable();
            $table->string('handled_by', 191)->nullable();
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();
            $table->foreign('customer_id')->references('id')->on('customer_users')->cascadeOnDelete();
            $table->index(['customer_id', 'created_at'], 'customer_support_customer_created_idx');
            $table->index(['status', 'created_at'], 'customer_support_status_created_idx');
        });

        Schema::create('member_tier_settings', function (Blueprint $table) {
            $table->string('tier', 32)->primary();
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->text('benefits');
            $table->timestamp('updated_at')->useCurrent();
        });

        Schema::create('payment_page_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->longText('config_json');
            $table->timestamp('updated_at')->useCurrent();
        });

        Schema::create('media_assets', function (Blueprint $table) {
            $table->string('media_key', 191)->primary();
            $table->string('content_type', 191);
            $table->binary('data');
            $table->string('etag', 191);
            $table->string('original_name', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index('created_at', 'media_assets_created_at_idx');
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE media_assets MODIFY data LONGBLOB NOT NULL');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('media_assets');
        Schema::dropIfExists('payment_page_settings');
        Schema::dropIfExists('member_tier_settings');
        Schema::dropIfExists('customer_support_requests');
        Schema::dropIfExists('faq_entries');
        Schema::dropIfExists('admin_users');
        Schema::dropIfExists('promotion_reservations');
        Schema::dropIfExists('flash_sales');
        Schema::dropIfExists('discount_vouchers');
        Schema::dropIfExists('product_categories');
        Schema::dropIfExists('news_articles');
        Schema::dropIfExists('site_popups');
        Schema::dropIfExists('home_banners');
        Schema::dropIfExists('product_reviews');
        Schema::dropIfExists('wallet_transactions');
        Schema::dropIfExists('wallet_topups');
        Schema::dropIfExists('wallet_settings');
        Schema::dropIfExists('store_settings');
    }
};
