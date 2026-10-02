<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vouchers', function (Blueprint $table): void {
            $table->string('name', 100)->nullable()->after('code');
            $table->string('description', 300)->nullable()->after('name');
            $table->unsignedBigInteger('max_discount_idr')->nullable()->after('discount_value');
        });
    }

    public function down(): void
    {
        Schema::table('vouchers', function (Blueprint $table): void {
            $table->dropColumn(['name', 'description', 'max_discount_idr']);
        });
    }
};
