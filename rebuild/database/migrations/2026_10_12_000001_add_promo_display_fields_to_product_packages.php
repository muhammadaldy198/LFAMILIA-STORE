<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_packages', function (Blueprint $table) {
            $table->boolean('is_promo')->default(false)->after('is_active');
            $table->unsignedBigInteger('original_price_idr')->nullable()->after('sell_price_idr');
            $table->unsignedInteger('points_earned')->default(0)->after('original_price_idr');
            $table->text('detail_text')->nullable()->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('product_packages', function (Blueprint $table) {
            $table->dropColumn(['is_promo', 'original_price_idr', 'points_earned', 'detail_text']);
        });
    }
};
