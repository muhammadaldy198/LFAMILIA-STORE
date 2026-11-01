<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Old one-mapping-per-order uniqueness prevented an approved, new attempt
     * on the original SKU. Transaction locks and latest-attempt/order-state
     * guards now enforce uniqueness of active fulfillment.
     */
    public function up(): void
    {
        Schema::table('fulfillment_attempts', function (Blueprint $table): void {
            $table->dropUnique('fulfillment_order_mapping_unique');
            $table->index(['order_id', 'provider_mapping_id'], 'fulfillment_order_mapping_lookup');
        });
    }

    public function down(): void
    {
        $duplicatesExist = DB::table('fulfillment_attempts')
            ->select('order_id', 'provider_mapping_id')
            ->groupBy('order_id', 'provider_mapping_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($duplicatesExist) {
            throw new RuntimeException('Cannot restore the historical unique constraint after quarantine resolution.');
        }

        Schema::table('fulfillment_attempts', function (Blueprint $table): void {
            $table->dropIndex('fulfillment_order_mapping_lookup');
            $table->unique(['order_id', 'provider_mapping_id'], 'fulfillment_order_mapping_unique');
        });
    }
};
