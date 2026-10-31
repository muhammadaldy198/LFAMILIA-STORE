<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL's case-insensitive collations cannot distinguish admin-customized casing.
        // Compare fetched strings strictly in PHP; update only untouched legacy defaults.
        $category = DB::table('payment_categories')->where('code', 'wallet')->first();
        if ($category?->name === 'LFAMILIA Cash') {
            DB::table('payment_categories')->where('code', 'wallet')
                ->update(['name' => 'LFAMILIA Coin', 'updated_at' => now()]);
        }

        $channel = DB::table('payment_channels')->where('code', 'saldo')->first();
        if ($channel?->name === 'LFAMILIA Cash') {
            DB::table('payment_channels')->where('code', 'saldo')
                ->update(['name' => 'LFAMILIA Coin', 'updated_at' => now()]);
        }
        if ($channel?->description === 'Bayar instan dari saldo LFAMILIA Cash tanpa biaya admin.') {
            DB::table('payment_channels')->where('code', 'saldo')
                ->update([
                    'description' => 'Bayar instan dari LFAMILIA Coin tanpa biaya admin.',
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        // Display branding does not revert to the retired name.
    }
};
