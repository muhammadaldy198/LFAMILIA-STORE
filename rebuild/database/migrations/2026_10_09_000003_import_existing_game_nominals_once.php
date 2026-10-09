<?php

use App\Services\OneTimeGameNominalImport;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An explicitly curated, one-time import for games already in the shop.
        // No new recurring job, watcher, catalogue importer or product is created.
        if (Schema::hasTable('digiflazz_catalog_items')) {
            app(OneTimeGameNominalImport::class)->run();
        }
    }

    public function down(): void
    {
        // Retain customer-visible packages and historical fulfillment mappings.
        // Do not erase records that could be referenced by past/future payments.
    }
};
