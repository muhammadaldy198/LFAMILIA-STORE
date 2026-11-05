<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the optional product region and register additional H2H game codes.
     */
    public function up(): void
    {
        // Nullable: existing products remain unrestricted unless admin opts in.
        if (Schema::hasTable('products') && ! Schema::hasColumn('products', 'nickname_required_region')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->string('nickname_required_region', 8)->nullable();
            });
        }

        if (! Schema::hasTable('nickname_game_codes')) {
            return;
        }

        $now = now();
        $nextOrder = (int) DB::table('nickname_game_codes')->max('sort_order') + 1;
        foreach ([
            ['Blood Strike', 'blood-strike'],
            ['Blood Strike Max', 'blood-strike-max'],
            ['Delta Force Garena', 'delta-force-garena'],
        ] as [$name, $code]) {
            DB::table('nickname_game_codes')->insertOrIgnore([
                'name' => $name,
                'code' => $code,
                'supports_nickname_check' => true,
                'requires_server' => false,
                'requires_region_check' => false,
                'is_active' => true,
                'sort_order' => $nextOrder++,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Drop the region column while retaining game codes that might already be used.
     */
    public function down(): void
    {
        // Seeded game codes may already be in use; do not delete them on rollback.
        if (Schema::hasTable('products') && Schema::hasColumn('products', 'nickname_required_region')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->dropColumn('nickname_required_region');
            });
        }
    }
};
