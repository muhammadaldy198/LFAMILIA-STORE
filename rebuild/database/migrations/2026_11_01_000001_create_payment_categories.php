<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_categories', function (Blueprint $table): void {
            $table->string('code', 40)->primary();
            $table->string('name', 100);
            $table->string('description', 160)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $defaults = [
            ['wallet', 'LFAMILIA Cash', 'Bayar dengan saldo akun LFAMILIA.', 10],
            ['qris', 'QRIS', 'Scan dari aplikasi pembayaran yang mendukung QRIS.', 20],
            ['ewallet', 'E-Wallet', 'Bayar menggunakan dompet digital.', 30],
            ['va', 'Virtual Account', 'Transfer bank melalui nomor VA.', 40],
            ['retail', 'Gerai Retail', 'Bayar di gerai pembayaran yang tersedia.', 50],
            ['other', 'Metode Lainnya', 'Pilihan pembayaran lainnya.', 60],
        ];
        foreach ($defaults as [$code, $name, $description, $order]) {
            DB::table('payment_categories')->insert([
                'code' => $code, 'name' => $name, 'description' => $description,
                'sort_order' => $order, 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        Schema::table('payment_channels', function (Blueprint $table): void {
            $table->string('category_code', 40)->default('other')->after('method')->index();
        });

        foreach ([
            'WALLET' => 'wallet', 'QRIS' => 'qris', 'EWALLET' => 'ewallet',
            'VIRTUAL_ACCOUNT' => 'va', 'RETAIL' => 'retail', 'OTHER' => 'other',
        ] as $method => $code) {
            DB::table('payment_channels')->where('method', $method)->update(['category_code' => $code]);
        }
    }

    public function down(): void
    {
        Schema::table('payment_channels', function (Blueprint $table): void {
            $table->dropIndex(['category_code']);
            $table->dropColumn('category_code');
        });
        Schema::dropIfExists('payment_categories');
    }
};
