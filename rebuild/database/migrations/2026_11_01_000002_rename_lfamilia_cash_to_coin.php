<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Rename only untouched legacy defaults. Merchant-customized labels remain editable.
        DB::table('payment_categories')
            ->where('code', 'wallet')
            ->where('name', 'LFAMILIA Cash')
            ->update(['name' => 'LFAMILIA Coin', 'updated_at' => now()]);

        DB::table('payment_channels')
            ->where('code', 'saldo')
            ->where('name', 'LFAMILIA Cash')
            ->update(['name' => 'LFAMILIA Coin', 'updated_at' => now()]);

        DB::table('payment_channels')
            ->where('code', 'saldo')
            ->where('description', 'Bayar instan dari saldo LFAMILIA Cash tanpa biaya admin.')
            ->update([
                'description' => 'Bayar instan dari LFAMILIA Coin tanpa biaya admin.',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Display branding does not revert to the retired name.
    }
};
