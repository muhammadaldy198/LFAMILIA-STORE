<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_mappings', function (Blueprint $table): void {
            $table->boolean('is_manual_price')->default(false)->after('max_price_idr');
        });
    }

    public function down(): void
    {
        Schema::table('provider_mappings', function (Blueprint $table): void {
            $table->dropColumn('is_manual_price');
        });
    }
};
