<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_channels', function (Blueprint $table) {
            $table->id();
            $table->string('method', 32);
            $table->string('channel', 96);
            $table->string('name', 191);
            $table->text('description');
            $table->text('image_url')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->string('gateway', 32)->default('doku');
            $table->longText('gateway_config_json')->default('{}');
            $table->timestamps();
            $table->unique(['method', 'channel'], 'payment_channels_method_channel_unique');
            $table->index(['is_active', 'sort_order'], 'payment_channels_active_sort_idx');
        });

        Schema::create('payment_gateway_settings', function (Blueprint $table) {
            $table->string('gateway', 32)->primary();
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });

        Schema::create('integration_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 64);
            $table->string('mode', 32);
            $table->string('environment', 32);
            $table->longText('encrypted_config');
            $table->timestamps();
            $table->unique(['provider', 'mode', 'environment'], 'integration_profiles_scope_unique');
        });

        Schema::create('integration_settings', function (Blueprint $table) {
            $table->string('setting_key', 191)->primary();
            $table->longText('value');
            $table->timestamp('updated_at')->useCurrent();
        });

        Schema::create('security_rate_limits', function (Blueprint $table) {
            $table->string('scope', 96);
            $table->bigInteger('bucket_start');
            $table->string('key_hash', 128);
            $table->unsignedInteger('hits')->default(0);
            $table->primary(['scope', 'bucket_start', 'key_hash'], 'security_rate_limits_pk');
        });

        Schema::create('admin_activity_logs', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->unsignedBigInteger('admin_id');
            $table->string('admin_name', 191);
            $table->string('admin_role', 32);
            $table->string('action', 191);
            $table->text('target');
            $table->timestamp('created_at')->useCurrent();
            $table->index('created_at', 'admin_activity_logs_created_idx');
        });

        Schema::create('digiflazz_pricing_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->boolean('is_auto_sync')->default(true);
            $table->string('margin_type', 32)->default('fixed');
            $table->integer('margin_value')->default(0);
            $table->timestamp('updated_at')->useCurrent();
        });

        Schema::create('digiflazz_seller_monitor', function (Blueprint $table) {
            $table->unsignedBigInteger('package_id')->primary();
            $table->string('seller_name', 191)->nullable();
            $table->bigInteger('current_price')->nullable();
            $table->bigInteger('baseline_price')->nullable();
            $table->boolean('buyer_product_status')->nullable();
            $table->boolean('seller_product_status')->nullable();
            $table->boolean('unlimited_stock')->nullable();
            $table->integer('stock')->nullable();
            $table->boolean('multi')->nullable();
            $table->string('start_cut_off', 16)->nullable();
            $table->string('end_cut_off', 16)->nullable();
            $table->text('description')->nullable();
            $table->string('health', 32)->default('unknown');
            $table->text('alert_reason')->nullable();
            $table->timestamp('last_checked_at')->useCurrent();
            $table->index(['health', 'last_checked_at'], 'digiflazz_seller_monitor_health_idx');
        });

        Schema::create('digiflazz_pricelist_cache', function (Blueprint $table) {
            $table->string('buyer_sku_code', 191)->primary();
            $table->string('product_name', 191);
            $table->string('category', 191)->default('');
            $table->string('brand', 191)->default('');
            $table->string('type', 96)->default('');
            $table->string('seller_name', 191)->default('');
            $table->bigInteger('price');
            $table->boolean('buyer_product_status')->default(true);
            $table->boolean('seller_product_status')->default(true);
            $table->boolean('unlimited_stock')->default(false);
            $table->integer('stock')->default(0);
            $table->boolean('multi')->default(false);
            $table->string('start_cut_off', 16)->default('00:00');
            $table->string('end_cut_off', 16)->default('00:00');
            $table->text('description');
            $table->timestamp('synced_at');
            $table->index(['brand', 'product_name'], 'digiflazz_pricelist_cache_brand_idx');
        });

        Schema::create('digiflazz_pricelist_sync_state', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('lock_token', 191)->nullable();
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('last_started_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
        });

        Schema::create('digiflazz_runtime_state', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('maintenance_token', 191)->nullable();
            $table->timestamp('maintenance_until')->nullable();
            $table->unsignedBigInteger('generation')->default(1);
            $table->timestamp('last_changed_at')->nullable();
        });

        Schema::create('one_time_operations', function (Blueprint $table) {
            $table->string('operation_key', 191)->primary();
            $table->timestamp('completed_at')->useCurrent();
        });

        DB::table('customer_cleanup_settings')->insertOrIgnore([
            'id' => 1, 'enabled' => 1, 'inactivity_days' => 30, 'last_deleted_count' => 0,
        ]);
        DB::table('digiflazz_pricing_settings')->insertOrIgnore([
            'id' => 1, 'is_auto_sync' => 1, 'margin_type' => 'fixed', 'margin_value' => 0,
        ]);
        DB::table('digiflazz_pricelist_sync_state')->insertOrIgnore(['id' => 1]);
        DB::table('digiflazz_runtime_state')->insertOrIgnore(['id' => 1]);

        foreach (['basic', 'gold', 'diamond', 'platinum'] as $tier) {
            DB::table('member_tier_settings')->insertOrIgnore([
                'tier' => $tier, 'discount_percent' => 0, 'benefits' => '',
            ]);
        }

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::unprepared("
                CREATE TRIGGER digiflazz_order_maintenance_guard
                BEFORE INSERT ON orders
                FOR EACH ROW
                BEGIN
                    IF LOWER(TRIM(COALESCE(NEW.provider_code, ''))) = 'digiflazz'
                       AND EXISTS (
                           SELECT 1 FROM digiflazz_runtime_state
                           WHERE id = 1
                             AND maintenance_token IS NOT NULL
                             AND maintenance_until > CURRENT_TIMESTAMP
                       )
                    THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'DIGIFLAZZ_CONFIG_MAINTENANCE';
                    END IF;
                END
            ");
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::unprepared('DROP TRIGGER IF EXISTS digiflazz_order_maintenance_guard');
        }

        Schema::dropIfExists('one_time_operations');
        Schema::dropIfExists('digiflazz_runtime_state');
        Schema::dropIfExists('digiflazz_pricelist_sync_state');
        Schema::dropIfExists('digiflazz_pricelist_cache');
        Schema::dropIfExists('digiflazz_seller_monitor');
        Schema::dropIfExists('digiflazz_pricing_settings');
        Schema::dropIfExists('admin_activity_logs');
        Schema::dropIfExists('security_rate_limits');
        Schema::dropIfExists('integration_settings');
        Schema::dropIfExists('integration_profiles');
        Schema::dropIfExists('payment_gateway_settings');
        Schema::dropIfExists('payment_channels');
    }
};
