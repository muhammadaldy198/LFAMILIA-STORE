<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_packages', function (Blueprint $table): void {
            $table->string('pricing_mode', 20)->default('PRODUCT_MARGIN');
            $table->decimal('margin_percent', 8, 4)->nullable();
            $table->unsignedBigInteger('margin_fixed_idr')->nullable();
            $table->unsignedBigInteger('sell_price_idr')->nullable();
        });
        Schema::create('digiflazz_catalog_items', function (Blueprint $table): void {
            $table->id();
            $table->string('buyer_sku_code', 120)->unique();
            $table->string('product_name');
            $table->string('category')->default('');
            $table->string('brand')->default('');
            $table->string('type')->default('');
            $table->string('seller_name')->default('');
            $table->unsignedBigInteger('price_idr');
            $table->unsignedBigInteger('baseline_price_idr');
            $table->boolean('buyer_active');
            $table->boolean('seller_active');
            $table->boolean('unlimited_stock');
            $table->unsignedBigInteger('stock')->default(0);
            $table->string('start_cut_off', 5)->default('00:00');
            $table->string('end_cut_off', 5)->default('00:00');
            $table->text('description')->nullable();
            $table->timestamp('synced_at');
            $table->timestamps();
            $table->index(['category', 'brand']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('digiflazz_catalog_items');
        Schema::table('product_packages', fn (Blueprint $table) => $table->dropColumn([
            'pricing_mode', 'margin_percent', 'margin_fixed_idr', 'sell_price_idr',
        ]));
    }
};
