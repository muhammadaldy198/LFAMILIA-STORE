<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        foreach (['footer_banner_desktop', 'footer_banner_mobile'] as $key) {
            DB::table('store_assets')->updateOrInsert(
                ['key' => $key],
                ['target_url' => null, 'is_active' => false, 'updated_at' => $now, 'created_at' => $now]
            );
        }
    }

    public function down(): void
    {
        DB::table('store_assets')->whereIn('key', [
            'footer_banner_desktop', 'footer_banner_mobile',
        ])->delete();
    }
};
