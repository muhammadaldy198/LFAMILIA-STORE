<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('providers', function (Blueprint $table): void {
            $table->string('display_name', 80)->nullable()->after('code');
            $table->string('description', 500)->nullable()->after('display_name');
            $table->unsignedInteger('sort_order')->default(0)->after('is_active');
        });

        $defaults = [
            'DIGIFLAZZ' => ['display_name' => 'Digiflazz', 'description' => 'Penyedia produk otomatis dan sinkronisasi katalog.', 'sort_order' => 10],
            'MANUAL' => ['display_name' => 'Manual', 'description' => 'Pesanan yang diselesaikan langsung oleh Admin.', 'sort_order' => 20],
            'VOUCHER_STOCK' => ['display_name' => 'Stok Voucher', 'description' => 'Pengiriman kode dari stok voucher internal.', 'sort_order' => 30],
        ];

        foreach ($defaults as $code => $values) {
            DB::table('providers')->where('code', $code)->update([
                ...$values,
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('providers', function (Blueprint $table): void {
            $table->dropColumn(['display_name', 'description', 'sort_order']);
        });
    }
};
