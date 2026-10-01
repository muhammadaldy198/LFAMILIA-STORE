<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $productId = DB::table('products')
            ->where('slug', 'xl')
            ->value('id');

        if (! $productId) {
            return;
        }

        DB::table('product_input_fields')
            ->where('product_id', $productId)
            ->where('field_key', 'destination')
            ->update([
                'label' => 'Nomor HP',
                'placeholder' => '08xxxxxxxxxx',
                'type' => 'tel',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        $productId = DB::table('products')
            ->where('slug', 'xl')
            ->value('id');

        if (! $productId) {
            return;
        }

        DB::table('product_input_fields')
            ->where('product_id', $productId)
            ->where('field_key', 'destination')
            ->update([
                'label' => 'User ID',
                'placeholder' => 'Masukkan User ID',
                'type' => 'text',
                'updated_at' => now(),
            ]);
    }
};
