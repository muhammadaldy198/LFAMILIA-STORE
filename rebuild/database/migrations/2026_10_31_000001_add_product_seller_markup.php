<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('price_markup_type', 10)->default('PERCENT');
            $table->unsignedBigInteger('price_markup_fixed_idr')->default(0);
        });

        // Existing automatic products with the old zero placeholder adopt the
        // 12% import default. Explicit nonzero percentages stay unchanged.
        DB::table('products')->where('fulfillment_mode', 'AUTO_PROVIDER')
            ->where('margin_percent', 0)->update(['margin_percent' => 12]);
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn(['price_markup_type', 'price_markup_fixed_idr']);
        });
    }
};
