<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Index untuk jendela feed pesanan publik: feed hanya mengambil
     * pesanan yang dibayar dalam 24 jam terakhir (filter paid_at), dan
     * tanpa index ini query harus memindai riwayat orders yang terus
     * tumbuh setiap kali feed dimuat.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->index('paid_at', 'orders_paid_at_idx');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_paid_at_idx');
        });
    }
};
