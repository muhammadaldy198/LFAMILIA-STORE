<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_gateways', function (Blueprint $table): void {
            $table->unsignedInteger('sort_order')->default(0)->after('is_maintenance');
        });

        Schema::table('payment_channels', function (Blueprint $table): void {
            $table->string('method', 30)->default('OTHER')->after('code');
            $table->string('description', 160)->nullable()->after('name');
        });

        Schema::table('payment_routes', function (Blueprint $table): void {
            $table->boolean('supports_order')->default(true)->after('priority');
            $table->boolean('supports_wallet_topup')->default(false)->after('supports_order');
        });

        foreach ([
            'MIDTRANS' => 10,
            'DOKU' => 20,
            'MANUAL_QRIS' => 30,
            'WALLET' => 40,
        ] as $code => $sortOrder) {
            DB::table('payment_gateways')->where('code', $code)->update([
                'sort_order' => $sortOrder,
                'updated_at' => now(),
            ]);
        }

        $channels = [
            'qris' => [
                'method' => 'QRIS',
                'description' => 'Scan QR menggunakan aplikasi pembayaran yang mendukung QRIS.',
            ],
            'virtual_account' => [
                'method' => 'VIRTUAL_ACCOUNT',
                'description' => 'Transfer melalui Virtual Account bank yang tersedia.',
            ],
            'ewallet' => [
                'method' => 'EWALLET',
                'description' => 'Bayar menggunakan dompet digital yang tersedia.',
            ],
            'manual_qris' => [
                'method' => 'QRIS',
                'description' => 'Scan QRIS lalu tunggu konfirmasi pembayaran dari Admin.',
            ],
            'saldo' => [
                'method' => 'WALLET',
                'description' => 'Bayar langsung menggunakan saldo akun LFAMILIA.',
            ],
        ];

        foreach ($channels as $code => $values) {
            DB::table('payment_channels')->where('code', $code)->update([
                ...$values,
                'updated_at' => now(),
            ]);
        }

        DB::table('payment_channels')->orderBy('id')->get(['id', 'supports_order', 'supports_wallet_topup'])
            ->each(function (object $channel): void {
                DB::table('payment_routes')->where('payment_channel_id', $channel->id)->update([
                    'supports_order' => (bool) $channel->supports_order,
                    'supports_wallet_topup' => (bool) $channel->supports_wallet_topup,
                    'updated_at' => now(),
                ]);
            });

        DB::table('system_settings')->updateOrInsert(
            ['key' => 'wallet.topup_enabled'],
            [
                'value' => json_encode(true),
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('system_settings')->where('key', 'wallet.topup_enabled')->delete();

        Schema::table('payment_routes', function (Blueprint $table): void {
            $table->dropColumn(['supports_order', 'supports_wallet_topup']);
        });

        Schema::table('payment_channels', function (Blueprint $table): void {
            $table->dropColumn(['method', 'description']);
        });

        Schema::table('payment_gateways', function (Blueprint $table): void {
            $table->dropColumn('sort_order');
        });
    }
};
