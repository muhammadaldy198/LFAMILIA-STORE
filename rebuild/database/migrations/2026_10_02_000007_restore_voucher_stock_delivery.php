<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voucher_stock_codes', function (Blueprint $table): void {
            $table->id();
            $table->string('stock_key', 100);
            $table->text('code_ciphertext');
            $table->char('code_hash', 64)->unique();
            $table->string('status', 20)->default('AVAILABLE');
            $table->foreignId('order_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
            $table->index(['stock_key', 'status'], 'voucher_stock_available_idx');
        });

        DB::table('providers')->updateOrInsert(
            ['code' => 'VOUCHER_STOCK'],
            [
                'fulfillment_mode' => 'AUTO_PROVIDER',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('voucher_stock_codes');

        $providerId = DB::table('providers')->where('code', 'VOUCHER_STOCK')->value('id');
        if ($providerId) {
            DB::table('provider_mappings')->where('provider_id', $providerId)->delete();
            DB::table('providers')->where('id', $providerId)->delete();
        }
    }
};
