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

        // Existing rows came from the previous nickname-code registry, where
        // presence in this table meant the game was supported for nickname checks.
        DB::table('nickname_game_codes')->update(['supports_nickname_check' => true]);
    }

    public function down(): void
    {
        Schema::table('nickname_game_codes', function (Blueprint $table): void {
            $table->dropColumn('supports_nickname_check');
        });
    }
};
