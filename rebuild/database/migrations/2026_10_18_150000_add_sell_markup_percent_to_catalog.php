<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->decimal('sell_markup_percent', 8, 4)->nullable();
        });

        Schema::table('product_packages', function (Blueprint $table): void {
            $table->decimal('sell_markup_percent', 8, 4)->nullable();
        });

    }

    public function down(): void
    {
        Schema::table('product_packages', function (Blueprint $table): void {
            $table->dropColumn('sell_markup_percent');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('sell_markup_percent');
        });
    }
};
