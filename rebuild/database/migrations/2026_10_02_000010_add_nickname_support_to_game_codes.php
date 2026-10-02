<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('nickname_game_codes') || Schema::hasColumn('nickname_game_codes', 'supports_nickname_check')) {
            return;
        }

        Schema::table('nickname_game_codes', function (Blueprint $table): void {
            $table->boolean('supports_nickname_check')
                ->default(false)
                ->after('code');
            $table->index(['is_active', 'supports_nickname_check', 'sort_order'], 'nickname_game_codes_support_idx');
        });

        if (! Schema::hasTable('products')) {
            return;
        }

        $supportedCodes = DB::table('products')
            ->where('nickname_check_enabled', true)
            ->whereNotNull('nickname_game_code')
            ->where('nickname_game_code', '<>', '')
            ->pluck('nickname_game_code')
            ->map(fn ($code): string => strtolower(trim((string) $code)))
            ->filter()
            ->unique()
            ->values();

        if ($supportedCodes->isNotEmpty()) {
            DB::table('nickname_game_codes')
                ->whereIn('code', $supportedCodes->all())
                ->update([
                    'supports_nickname_check' => true,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('nickname_game_codes') || ! Schema::hasColumn('nickname_game_codes', 'supports_nickname_check')) {
            return;
        }

        Schema::table('nickname_game_codes', function (Blueprint $table): void {
            $table->dropIndex('nickname_game_codes_support_idx');
            $table->dropColumn('supports_nickname_check');
        });
    }
};
