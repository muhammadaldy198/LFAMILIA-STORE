<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'member_tier_snapshot')) {
                $table->string('member_tier_snapshot', 32)->nullable()->after('discount_amount');
            }
            if (!Schema::hasColumn('orders', 'member_discount_percent_snapshot')) {
                $table->decimal('member_discount_percent_snapshot', 5, 2)->default(0)->after('member_tier_snapshot');
            }
            if (!Schema::hasColumn('orders', 'member_discount_amount')) {
                $table->bigInteger('member_discount_amount')->default(0)->after('member_discount_percent_snapshot');
            }
            if (!Schema::hasColumn('orders', 'voucher_discount_amount')) {
                $table->bigInteger('voucher_discount_amount')->default(0)->after('member_discount_amount');
            }
        });

        Schema::table('store_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('store_settings', 'footer_banner_desktop_url')) {
                $table->text('footer_banner_desktop_url')->nullable()->after('logo_url');
            }
            if (!Schema::hasColumn('store_settings', 'footer_banner_mobile_url')) {
                $table->text('footer_banner_mobile_url')->nullable()->after('footer_banner_desktop_url');
            }
        });

        DB::table('member_tier_settings')->updateOrInsert(
            ['tier' => 'mafia'],
            [
                'discount_percent' => 0,
                'benefits' => '',
                'updated_at' => now(),
            ],
        );

        DB::table('store_settings')->where('id', 1)->update([
            'announcement' => null,
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('member_tier_settings')->where('tier', 'mafia')->delete();

        Schema::table('orders', function (Blueprint $table) {
            $columns = array_values(array_filter([
                Schema::hasColumn('orders', 'member_tier_snapshot') ? 'member_tier_snapshot' : null,
                Schema::hasColumn('orders', 'member_discount_percent_snapshot') ? 'member_discount_percent_snapshot' : null,
                Schema::hasColumn('orders', 'member_discount_amount') ? 'member_discount_amount' : null,
                Schema::hasColumn('orders', 'voucher_discount_amount') ? 'voucher_discount_amount' : null,
            ]));
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });

        Schema::table('store_settings', function (Blueprint $table) {
            $columns = array_values(array_filter([
                Schema::hasColumn('store_settings', 'footer_banner_desktop_url') ? 'footer_banner_desktop_url' : null,
                Schema::hasColumn('store_settings', 'footer_banner_mobile_url') ? 'footer_banner_mobile_url' : null,
            ]));
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
