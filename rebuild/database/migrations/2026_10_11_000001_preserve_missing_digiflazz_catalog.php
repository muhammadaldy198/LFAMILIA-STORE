<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('digiflazz_catalog_items', function (Blueprint $table): void {
            // Keep the last known supplier row even when absent from a full sync.
            $table->boolean('is_present')->default(true)->index();
        });

        Schema::table('provider_mappings', function (Blueprint $table): void {
            // Distinguish a sync-induced disable from a deliberate operator disable.
            $table->boolean('disabled_by_sync')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('provider_mappings', fn (Blueprint $table) => $table->dropColumn('disabled_by_sync'));
        Schema::table('digiflazz_catalog_items', fn (Blueprint $table) => $table->dropColumn('is_present'));
    }
};
