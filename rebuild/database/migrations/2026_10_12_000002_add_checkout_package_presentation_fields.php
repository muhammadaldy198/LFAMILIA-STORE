<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_packages', function (Blueprint $table): void {
            $table->boolean('is_promo')->default(false);
            $table->unsignedBigInteger('original_price')->nullable();
            $table->unsignedInteger('points')->nullable();
            $table->text('detail_text')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('product_packages', function (Blueprint $table): void {
            $table->dropColumn(['is_promo', 'original_price', 'points', 'detail_text']);
        });
    }
};
