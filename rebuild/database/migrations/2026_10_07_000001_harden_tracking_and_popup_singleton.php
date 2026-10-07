<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('guest_phone_normalized', 20)->nullable()->after('guest_phone')->index();
        });

        DB::table('orders')->whereNotNull('guest_phone')->orderBy('id')->chunkById(500, function ($orders): void {
            foreach ($orders as $order) {
                $digits = preg_replace('/\D+/', '', (string) $order->guest_phone) ?: null;
                DB::table('orders')->where('id', $order->id)->update(['guest_phone_normalized' => $digits]);
            }
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->string('phone_normalized', 20)->nullable()->after('phone')->index();
        });

        DB::table('users')->whereNotNull('phone')->orderBy('id')->chunkById(500, function ($users): void {
            foreach ($users as $user) {
                $digits = preg_replace('/\D+/', '', (string) $user->phone) ?: null;
                DB::table('users')->where('id', $user->id)->update(['phone_normalized' => $digits]);
            }
        });

        $keepPopupId = DB::table('site_popups')->orderBy('id')->value('id');
        if ($keepPopupId !== null) {
            $duplicatePopupIds = DB::table('site_popups')->where('id', '!=', $keepPopupId)->pluck('id');
            if ($duplicatePopupIds->isNotEmpty()) {
                DB::table('media')
                    ->where('model_type', 'App\\Models\\SitePopup')
                    ->whereIn('model_id', $duplicatePopupIds)
                    ->delete();
                DB::table('site_popups')->whereIn('id', $duplicatePopupIds)->delete();
            }
        }

        Schema::table('site_popups', function (Blueprint $table): void {
            $table->unsignedTinyInteger('singleton_key')->default(1)->unique();
        });
    }

    public function down(): void
    {
        Schema::table('site_popups', function (Blueprint $table): void {
            $table->dropUnique(['singleton_key']);
            $table->dropColumn('singleton_key');
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['phone_normalized']);
            $table->dropColumn('phone_normalized');
        });
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex(['guest_phone_normalized']);
            $table->dropColumn('guest_phone_normalized');
        });
    }
};
