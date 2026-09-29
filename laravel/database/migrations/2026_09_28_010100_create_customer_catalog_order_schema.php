<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_users', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->string('email', 191)->unique();
            $table->string('name', 191);
            $table->string('phone', 32)->default('');
            $table->timestamp('phone_verified_at')->nullable();
            $table->text('password_hash');
            $table->text('password_salt');
            $table->bigInteger('balance')->default(0);
            $table->string('tier_mode', 32)->default('automatic');
            $table->string('tier_override', 32)->nullable();
            $table->bigInteger('tier_progress_bonus')->default(0);
            $table->boolean('leaderboard_opt_in')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
            $table->index(['leaderboard_opt_in', 'is_active'], 'customer_users_leaderboard_idx');
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE customer_users ADD verified_phone VARCHAR(32) GENERATED ALWAYS AS (CASE WHEN phone_verified_at IS NOT NULL AND TRIM(phone) <> '' THEN phone ELSE NULL END) STORED");
            DB::statement('CREATE UNIQUE INDEX customer_users_verified_phone_unique ON customer_users (verified_phone)');
        }

        Schema::create('customer_cleanup_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->boolean('enabled')->default(true);
            $table->unsignedSmallInteger('inactivity_days')->default(30);
            $table->timestamp('last_run_at')->nullable();
            $table->unsignedInteger('last_deleted_count')->default(0);
            $table->timestamp('updated_at')->useCurrent();
        });

        Schema::create('customer_sessions', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->string('customer_id', 64);
            $table->string('token_hash', 128)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('created_at')->useCurrent();
            $table->foreign('customer_id')->references('id')->on('customer_users')->cascadeOnDelete();
            $table->index(['customer_id', 'expires_at'], 'customer_sessions_customer_expiry_idx');
        });

        Schema::create('customer_phone_otp_challenges', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->string('customer_id', 64);
            $table->string('phone', 32);
            $table->string('otp_hash', 191);
            $table->string('otp_salt', 191);
            $table->timestamp('expires_at');
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->timestamp('sent_at')->useCurrent();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->foreign('customer_id')->references('id')->on('customer_users')->cascadeOnDelete();
            $table->index(['customer_id', 'created_at'], 'customer_phone_otp_customer_created_idx');
            $table->index(['expires_at', 'consumed_at'], 'customer_phone_otp_expiry_idx');
        });

        Schema::create('customer_oauth_accounts', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->string('customer_id', 64);
            $table->string('provider', 32);
            $table->string('provider_subject', 191);
            $table->string('provider_email', 191)->nullable();
            $table->text('avatar_url')->nullable();
            $table->timestamps();
            $table->foreign('customer_id')->references('id')->on('customer_users')->cascadeOnDelete();
            $table->unique(['provider', 'provider_subject'], 'customer_oauth_provider_subject_unique');
            $table->unique(['provider', 'customer_id'], 'customer_oauth_provider_customer_unique');
        });

        Schema::create('customer_game_accounts', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->string('customer_id', 64);
            $table->string('product_slug', 191);
            $table->string('label', 191);
            $table->longText('values_json')->default('[]');
            $table->string('nickname', 191)->nullable();
            $table->timestamps();
            $table->foreign('customer_id')->references('id')->on('customer_users')->cascadeOnDelete();
            $table->index(['customer_id', 'product_slug', 'updated_at'], 'customer_game_accounts_customer_product_idx');
        });

        Schema::create('customer_password_reset_tokens', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->string('customer_id', 64);
            $table->string('token_hash', 128)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->foreign('customer_id')->references('id')->on('customer_users')->cascadeOnDelete();
            $table->index(['customer_id', 'created_at'], 'customer_password_reset_customer_created_idx');
            $table->index(['expires_at', 'used_at'], 'customer_password_reset_expiry_idx');
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 191)->unique();
            $table->string('name', 191);
            $table->string('publisher', 191)->default('');
            $table->string('category', 96);
            $table->text('image_url')->nullable();
            $table->text('banner_url')->nullable();
            $table->longText('description')->nullable();
            $table->string('initials', 32);
            $table->string('accent', 64);
            $table->string('input_label', 191);
            $table->string('input_placeholder', 191);
            $table->longText('input_fields_json')->nullable();
            $table->string('nickname_game_code', 191)->nullable();
            $table->boolean('needs_server')->default(false);
            $table->boolean('popular')->default(false);
            $table->boolean('instant')->default(false);
            $table->string('fulfillment_type', 32)->default('automatic');
            $table->text('target_template')->default('{{destination}}{{server}}');
            $table->longText('manual_instructions')->nullable();
            $table->string('manual_open_time', 16)->nullable();
            $table->string('manual_close_time', 16)->nullable();
            $table->string('manual_timezone', 64)->default('Asia/Jakarta');
            $table->boolean('package_tabs_enabled')->default(false);
            $table->longText('package_tabs_json')->default('[]');
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->index(['is_active', 'sort_order'], 'products_active_sort_idx');
        });

        Schema::create('product_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('sku', 191)->unique();
            $table->string('label', 191);
            $table->bigInteger('price');
            $table->text('note')->nullable();
            $table->string('package_group', 96)->nullable();
            $table->text('image_url')->nullable();
            $table->string('provider_code', 64)->nullable();
            $table->string('provider_sku', 191)->nullable();
            $table->bigInteger('supplier_price')->nullable();
            $table->bigInteger('provider_max_price')->nullable();
            $table->string('pricing_mode', 32)->default('manual');
            $table->string('margin_type', 32)->default('fixed');
            $table->integer('margin_value')->default(0);
            $table->timestamp('supplier_synced_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->index(['product_id', 'is_active', 'sort_order'], 'product_packages_product_sort_idx');
        });

        Schema::create('product_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('title', 191);
            $table->longText('body');
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->index(['product_id', 'is_active', 'sort_order'], 'product_notices_product_active_sort_idx');
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->string('customer_id', 64)->nullable();
            $table->string('wallet_checkout_key', 191)->nullable();
            $table->string('external_checkout_key', 191)->nullable()->unique();
            $table->string('reference_id', 191)->unique();
            $table->string('product_slug', 191);
            $table->string('product_name', 191);
            $table->string('package_sku', 191);
            $table->string('package_label', 191);
            $table->string('provider_code', 64)->nullable();
            $table->string('provider_sku', 191)->nullable();
            $table->string('fulfillment_type', 32);
            $table->string('delivery_mode', 32)->nullable();
            $table->bigInteger('supplier_cost_snapshot')->nullable();
            $table->bigInteger('provider_max_price_snapshot')->nullable();
            $table->text('target_template');
            $table->string('destination', 191);
            $table->string('server', 191)->nullable();
            $table->string('nickname', 191)->nullable();
            $table->string('customer_no', 191)->nullable();
            $table->string('buyer_name', 191);
            $table->string('buyer_email', 191);
            $table->string('buyer_phone', 32);
            $table->longText('customer_notes')->nullable();
            $table->longText('customer_inputs_json')->default('[]');
            $table->unsignedInteger('quantity')->default(1);
            $table->bigInteger('base_subtotal')->default(0);
            $table->bigInteger('subtotal');
            $table->bigInteger('discount_amount')->default(0);
            $table->string('voucher_code', 191)->nullable();
            $table->unsignedBigInteger('flash_sale_id')->nullable();
            $table->bigInteger('admin_fee')->default(0);
            $table->bigInteger('total');
            $table->string('payment_method', 64);
            $table->string('payment_channel', 96);
            $table->string('payment_status', 32)->default('pending');
            $table->string('fulfillment_status', 32)->default('waiting_payment');
            $table->string('payment_gateway', 32)->nullable();
            $table->string('payment_gateway_mode', 32)->nullable();
            $table->string('payment_gateway_environment', 32)->nullable();
            $table->string('gateway_request_id', 191)->nullable();
            $table->string('gateway_reference_no', 191)->nullable();
            $table->string('gateway_payment_no', 191)->nullable();
            $table->longText('gateway_qr_content')->nullable();
            $table->text('gateway_payment_url')->nullable();
            $table->timestamp('gateway_expired_at')->nullable();
            $table->timestamp('gateway_status_checked_at')->nullable();
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
            $table->string('provider_ref_id', 191)->nullable();
            $table->string('provider_status', 64)->nullable();
            $table->text('provider_message')->nullable();
            $table->text('provider_serial_number')->nullable();
            $table->timestamps();

            $table->foreign('customer_id')->references('id')->on('customer_users')->nullOnDelete();
            $table->unique(['customer_id', 'wallet_checkout_key'], 'orders_wallet_checkout_key_unique');
            $table->unique(['provider_code', 'provider_ref_id'], 'orders_provider_ref_id_unique');
            $table->index(['payment_status', 'fulfillment_status'], 'orders_payment_fulfillment_idx');
            $table->index(['payment_gateway', 'payment_status', 'created_at'], 'orders_payment_gateway_status_idx');
            $table->index('created_at', 'orders_created_at_idx');
            $table->index(['customer_id', 'created_at'], 'orders_customer_created_idx');
        });

        Schema::create('order_fulfillment_units', function (Blueprint $table) {
            $table->id();
            $table->string('order_id', 64);
            $table->unsignedInteger('unit_index');
            $table->string('provider_ref_id', 191)->unique();
            $table->string('provider_status', 64)->default('waiting');
            $table->text('provider_message')->nullable();
            $table->text('provider_serial_number')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamps();
            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            $table->unique(['order_id', 'unit_index'], 'order_fulfillment_units_order_index_unique');
            $table->index(['order_id', 'provider_status'], 'order_fulfillment_units_order_status_idx');
        });

        Schema::create('order_events', function (Blueprint $table) {
            $table->id();
            $table->string('order_id', 64);
            $table->string('source', 32);
            $table->string('event_id', 191);
            $table->string('status', 64);
            $table->longText('payload_json');
            $table->timestamp('created_at')->useCurrent();
            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            $table->unique(['source', 'event_id'], 'order_events_source_event_unique');
            $table->index(['order_id', 'created_at'], 'order_events_order_created_idx');
        });

        Schema::create('voucher_codes', function (Blueprint $table) {
            $table->id();
            $table->string('stock_key', 191);
            $table->longText('code_ciphertext');
            $table->text('code_iv');
            $table->text('code_tag');
            $table->string('code_hash', 191)->unique();
            $table->string('status', 32)->default('available');
            $table->string('order_id', 64)->nullable()->unique();
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->foreign('order_id')->references('id')->on('orders')->nullOnDelete();
            $table->index(['stock_key', 'status', 'id'], 'voucher_codes_stock_status_idx');
        });

        Schema::create('voucher_deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('order_id', 64);
            $table->unsignedBigInteger('voucher_code_id');
            $table->string('channel', 32);
            $table->string('status', 32)->default('pending');
            $table->string('provider_id', 191)->nullable();
            $table->text('provider_message')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamps();
            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            $table->foreign('voucher_code_id')->references('id')->on('voucher_codes')->cascadeOnDelete();
            $table->unique(['order_id', 'channel'], 'voucher_deliveries_order_channel_unique');
            $table->index(['status', 'updated_at'], 'voucher_deliveries_status_updated_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voucher_deliveries');
        Schema::dropIfExists('voucher_codes');
        Schema::dropIfExists('order_events');
        Schema::dropIfExists('order_fulfillment_units');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('product_notices');
        Schema::dropIfExists('product_packages');
        Schema::dropIfExists('products');
        Schema::dropIfExists('customer_password_reset_tokens');
        Schema::dropIfExists('customer_game_accounts');
        Schema::dropIfExists('customer_oauth_accounts');
        Schema::dropIfExists('customer_phone_otp_challenges');
        Schema::dropIfExists('customer_sessions');
        Schema::dropIfExists('customer_cleanup_settings');
        Schema::dropIfExists('customer_users');
    }
};
