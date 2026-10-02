<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('digiflazz_catalog_items', 'multi')) {
            Schema::table('digiflazz_catalog_items', function (Blueprint $table): void {
                $table->boolean('multi')->default(false)->after('stock');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('digiflazz_catalog_items', 'multi')) {
            Schema::table('digiflazz_catalog_items', function (Blueprint $table): void {
                $table->dropColumn('multi');
            });
        }
    }
};
