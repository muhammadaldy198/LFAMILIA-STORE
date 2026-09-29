<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_gateways', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('internal_name', 100);
            $table->string('kind', 20);
            $table->boolean('is_active')->default(false);
            $table->boolean('is_maintenance')->default(false);
            $table->timestamps();
        });
        DB::statement("ALTER TABLE payment_gateways ADD CONSTRAINT payment_gateways_kind_check CHECK (kind IN ('EXTERNAL', 'MANUAL', 'INTERNAL'))");

        Schema::create('payment_channels', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 60)->unique();
            $table->string('name', 100);
            $table->unsignedBigInteger('fee_flat_idr')->default(0);
            $table->unsignedInteger('fee_percent_bps')->default(0);
            $table->boolean('supports_order')->default(true);
            $table->boolean('supports_wallet_topup')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });
        DB::statement('ALTER TABLE payment_channels ADD CONSTRAINT payment_channel_fee_check CHECK (fee_percent_bps <= 10000)');

        Schema::create('payment_routes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_channel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_gateway_id')->constrained()->restrictOnDelete();
            $table->string('provider_channel', 100)->nullable();
            $table->json('configuration')->nullable();
            $table->unsignedInteger('priority')->default(0);
            $table->boolean('is_active')->default(false);
            $table->timestamps();
            $table->unique(['payment_channel_id', 'payment_gateway_id'], 'payment_channel_gateway_unique');
            $table->index(['payment_channel_id', 'is_active', 'priority'], 'payment_route_lookup_idx');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignId('payment_channel_id')->nullable()->after('voucher_id')
                ->constrained()->restrictOnDelete();
            $table->foreignId('payment_route_id')->nullable()->after('payment_channel_id')
                ->constrained()->restrictOnDelete();
        });

        Schema::table('wallet_topups', function (Blueprint $table): void {
            $table->foreignId('payment_channel_id')->nullable()->after('user_id')
                ->constrained()->restrictOnDelete();
            $table->foreignId('payment_route_id')->nullable()->after('payment_channel_id')
                ->constrained()->restrictOnDelete();
            $table->string('request_fingerprint', 64)->nullable()->after('idempotency_key');
            $table->timestamp('expires_at')->nullable()->after('paid_at');
        });

        Schema::table('payment_transactions', function (Blueprint $table): void {
            $table->foreignId('payment_route_id')->nullable()->after('wallet_topup_id')
                ->constrained()->restrictOnDelete();
            $table->string('merchant_reference', 120)->nullable()->unique()->after('channel_code');
            $table->string('request_fingerprint', 64)->nullable()->after('merchant_reference');
            $table->json('public_payload')->nullable()->after('status');
            $table->json('gateway_payload')->nullable()->after('public_payload');
            $table->timestamp('expires_at')->nullable()->after('verified_at');
            $table->timestamp('last_callback_at')->nullable()->after('expires_at');
        });

        DB::table('payment_gateways')->insert([
            ['code' => 'MIDTRANS', 'internal_name' => 'Midtrans Snap', 'kind' => 'EXTERNAL', 'is_active' => false, 'is_maintenance' => false, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'DOKU', 'internal_name' => 'DOKU Direct API', 'kind' => 'EXTERNAL', 'is_active' => false, 'is_maintenance' => false, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'MANUAL_QRIS', 'internal_name' => 'Manual QRIS', 'kind' => 'MANUAL', 'is_active' => false, 'is_maintenance' => false, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'WALLET', 'internal_name' => 'LFAMILIA Wallet', 'kind' => 'INTERNAL', 'is_active' => false, 'is_maintenance' => false, 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('payment_channels')->insert([
            ['code' => 'qris', 'name' => 'QRIS', 'supports_order' => true, 'supports_wallet_topup' => true, 'sort_order' => 10, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'virtual_account', 'name' => 'Virtual Account', 'supports_order' => true, 'supports_wallet_topup' => true, 'sort_order' => 20, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'ewallet', 'name' => 'E-Wallet', 'supports_order' => true, 'supports_wallet_topup' => true, 'sort_order' => 30, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'manual_qris', 'name' => 'QRIS Manual', 'supports_order' => true, 'supports_wallet_topup' => false, 'sort_order' => 40, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'saldo', 'name' => 'Saldo LFAMILIA', 'supports_order' => true, 'supports_wallet_topup' => false, 'sort_order' => 50, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $gatewayIds = DB::table('payment_gateways')->pluck('id', 'code');
        $channelIds = DB::table('payment_channels')->pluck('id', 'code');
        DB::table('payment_routes')->insert([
            [
                'payment_channel_id' => $channelIds['manual_qris'],
                'payment_gateway_id' => $gatewayIds['MANUAL_QRIS'],
                'priority' => 0,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'payment_channel_id' => $channelIds['saldo'],
                'payment_gateway_id' => $gatewayIds['WALLET'],
                'priority' => 0,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        if (! DB::table('store_assets')->where('key', 'manual_qris')->exists()) {
            DB::table('store_assets')->insert([
                'key' => 'manual_qris',
                'is_active' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('system_settings')->updateOrInsert(
            ['key' => 'wallet.minimum_topup_idr'],
            ['value' => json_encode(10000), 'version' => 1, 'created_at' => now(), 'updated_at' => now()]
        );
    }

    public function down(): void
    {
        $manualAssetId = DB::table('store_assets')->where('key', 'manual_qris')->value('id');
        if ($manualAssetId) {
            DB::table('media')
                ->where('model_type', 'App\\Models\\StoreAsset')
                ->where('model_id', $manualAssetId)
                ->delete();
        }
        DB::table('store_assets')->where('key', 'manual_qris')->delete();
        DB::table('system_settings')->where('key', 'wallet.minimum_topup_idr')->delete();

        Schema::table('payment_transactions', function (Blueprint $table): void {
            $table->dropForeign(['payment_route_id']);
            $table->dropUnique(['merchant_reference']);
            $table->dropColumn([
                'payment_route_id', 'merchant_reference', 'request_fingerprint',
                'public_payload', 'gateway_payload', 'expires_at', 'last_callback_at',
            ]);
        });

        Schema::table('wallet_topups', function (Blueprint $table): void {
            $table->dropForeign(['payment_channel_id']);
            $table->dropForeign(['payment_route_id']);
            $table->dropColumn(['payment_channel_id', 'payment_route_id', 'request_fingerprint', 'expires_at']);
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropForeign(['payment_channel_id']);
            $table->dropForeign(['payment_route_id']);
            $table->dropColumn(['payment_channel_id', 'payment_route_id']);
        });

        Schema::dropIfExists('payment_routes');
        Schema::dropIfExists('payment_channels');
        Schema::dropIfExists('payment_gateways');
    }
};
