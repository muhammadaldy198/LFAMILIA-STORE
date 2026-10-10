<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_routes', function (Blueprint $table): void {
            // NULL preserves the legacy channel fee. Non-NULL overrides
            // the fee for one specific gateway/route.
            $table->unsignedBigInteger('fee_flat_idr')->nullable();
            $table->unsignedInteger('fee_percent_bps')->nullable();
        });

        // Do not override merchant-edited labels/order. Only normalize
        // untouched seed defaults for clearer customer/admin presentation.
        $defaults = [
            'saldo' => ['Saldo LFAMILIA', 'LFAMILIA Cash', 50, 10, 'Bayar instan dari saldo LFAMILIA Cash tanpa biaya admin.'],
            'qris' => ['QRIS', 'QRIS Otomatis', 10, 20, 'Scan QRIS melalui aplikasi bank atau e-wallet.'],
            'ewallet' => ['E-Wallet', 'Dompet Digital', 30, 30, 'Bayar melalui dompet digital yang tersedia.'],
            'virtual_account' => ['Virtual Account', 'Transfer Bank (VA)', 20, 40, 'Transfer melalui Virtual Account bank.'],
            'manual_qris' => ['QRIS Manual', 'QRIS Manual', 40, 90, 'Scan QRIS dan tunggu konfirmasi admin.'],
        ];

        foreach ($defaults as $code => [$oldName, $newName, $oldPosition, $newPosition, $description]) {
            $row = DB::table('payment_channels')->where('code', $code)->first();
            if (! $row) {
                continue;
            }

            $changes = [];
            if ($row->name === $oldName) {
                $changes['name'] = $newName;
            }
            if ((int) $row->sort_order === $oldPosition) {
                $changes['sort_order'] = $newPosition;
            }
            if (! $row->description) {
                $changes['description'] = $description;
            }
            if ($changes !== []) {
                DB::table('payment_channels')->where('id', $row->id)
                    ->update([...$changes, 'updated_at' => now()]);
            }
        }

        $banks = [
            'va_bca' => ['VA BCA', 'BCA Virtual Account', 21, 41],
            'va_bni' => ['VA BNI', 'BNI Virtual Account', 22, 42],
            'va_bri' => ['VA BRI', 'BRI Virtual Account', 23, 43],
            'va_mandiri' => ['VA Mandiri', 'Mandiri Virtual Account', 24, 44],
            'va_permata' => ['VA Permata', 'Permata Virtual Account', 25, 45],
            'va_cimb' => ['VA CIMB Niaga', 'CIMB Niaga Virtual Account', 26, 46],
        ];
        foreach ($banks as $code => [$oldName, $newName, $oldPosition, $newPosition]) {
            $row = DB::table('payment_channels')->where('code', $code)->first();
            if (! $row) {
                continue;
            }
            $changes = [];
            if ($row->name === $oldName) {
                $changes['name'] = $newName;
            }
            if ((int) $row->sort_order === $oldPosition) {
                $changes['sort_order'] = $newPosition;
            }
            if ($changes !== []) {
                DB::table('payment_channels')->where('id', $row->id)
                    ->update([...$changes, 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        // Keep merchant-edited method names/sort positions on rollback.
        Schema::table('payment_routes', function (Blueprint $table): void {
            $table->dropColumn(['fee_flat_idr', 'fee_percent_bps']);
        });
    }
};
