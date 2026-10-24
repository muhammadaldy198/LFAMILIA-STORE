<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_packages', function (Blueprint $table): void {
            // Nominal yang dinonaktifkan karena SKU-nya hilang dari Digiflazz,
            // bukan karena admin mematikannya sendiri.
            $table->boolean('disabled_by_sync')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('product_packages', fn (Blueprint $table) => $table->dropColumn('disabled_by_sync'));
    }
};
