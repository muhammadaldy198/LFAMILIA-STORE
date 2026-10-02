<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nickname_game_codes', function (Blueprint $table): void {
            $table->boolean('supports_nickname_check')->default(false)->after('code');
        });

        // Do not assume every known game supports nickname checking.
        // Preserve support only for game codes that were already used by products
        // with nickname validation enabled before this migration.
        if (Schema::hasTable('products')) {
            $usedCodes = DB::table('products')
                ->where('nickname_check_enabled', true)
                ->whereNotNull('nickname_game_code')
                ->where('nickname_game_code', '<>', '')
                ->pluck('nickname_game_code')
                ->map(fn ($code): string => strtolower(trim((string) $code)))
                ->filter()
                ->unique()
                ->values()
                ->all();

            if ($usedCodes !== []) {
                DB::table('nickname_game_codes')
                    ->whereIn('code', $usedCodes)
                    ->update(['supports_nickname_check' => true]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('nickname_game_codes', function (Blueprint $table): void {
            $table->dropColumn('supports_nickname_check');
        });
    }
};
