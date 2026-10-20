<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_mappings', function (Blueprint $table): void {
            $table->unsignedBigInteger('store_cost_idr')->nullable();
            $table->unsignedBigInteger('seller_reference_price_idr')->nullable();
        });

        DB::table('system_settings')->updateOrInsert(
            ['key' => 'pricing.cost_uplift_percent'],
            ['value' => json_encode(2), 'created_at' => now(), 'updated_at' => now()]
        );

        DB::table('system_settings')->updateOrInsert(
            ['key' => 'pricing.sell_markup_percent'],
            ['value' => json_encode(10), 'created_at' => now(), 'updated_at' => now()]
        );
    }

    public function down(): void
    {
        DB::table('system_settings')->whereIn('key', [
            'pricing.cost_uplift_percent',
            'pricing.sell_markup_percent',
        ])->delete();

        Schema::table('provider_mappings', function (Blueprint $table): void {
            $table->dropColumn(['store_cost_idr', 'seller_reference_price_idr']);
        });
    }
};
