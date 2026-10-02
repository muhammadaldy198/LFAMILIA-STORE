<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('nickname_game_codes')) {
            return;
        }

        Schema::create('nickname_game_codes', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('code', 100)->unique();
            $table->boolean('requires_server')->default(false);
            $table->boolean('requires_region_check')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });

        $now = now();
        $rows = [
            ['Mobile Legends', 'mobile-legends', true, true],
            ['Free Fire', 'free-fire', false, false],
            ['PUBG Mobile', 'pubg-mobile', false, false],
            ['Call of Duty Mobile', 'call-of-duty-mobile', false, false],
            ['Valorant', 'valorant', false, false],
            ['Genshin Impact', 'genshin-impact', true, false],
            ['Honor of Kings', 'honor-of-kings', false, false],
            ['League of Legends: Wild Rift', 'league-of-legends-wild-rift', false, false],
            ['Arena of Valor', 'arena-of-valor', false, false],
            ['Point Blank', 'point-blank', false, false],
            ['Free Fire Max', 'free-fire-max', false, false],
            ['Whiteout Survival', 'whiteout-survival', false, false],
            ['Honkai Impact 3', 'honkai-impact-3', false, false],
            ['Honkai: Star Rail', 'honkai-star-rail', true, false],
            ['Eggy Party', 'eggy-party', true, false],
            ['Undawn', 'undawn', false, false],
            ['Growtopia', 'growtopia', false, false],
            ['League of Legends PC', 'league-of-legends-pc', false, false],
            ['FC Mobile', 'fc-mobile', false, false],
            ['Super Sus', 'super-sus', false, false],
            ['Harry Potter: Magic Awakened', 'harry-potter-magic-awakened', true, false],
            ['Revelation: Infinite Journey', 'revelation-infinite-journey', false, false],
            ['MU Origin 3', 'mu-origin-3', false, false],
            ['Sausage Man', 'sausage-man', false, false],
            ['Speed Drifters', 'speed-drifters', false, false],
            ['Tom and Jerry: Chase', 'tom-and-jerry-chase', true, false],
            ['Teamfight Tactics Mobile', 'teamfight-tactics-mobile', false, false],
            ['LifeAfter', 'lifeafter', true, false],
            ['Laplace M', 'laplace-m', false, false],
            ['Arena Breakout', 'arena-breakout', false, false],
            ['Zenless Zone Zero', 'zenless-zone-zero', true, false],
            ['AFK Journey', 'afk-journey', false, false],
            ['Magic Chess Go Go', 'magic-chess-go-go', true, false],
            ['Love and Deepspace', 'love-and-deepspace', false, false],
            ['Pokemon Unite', 'pokemon-unite', false, false],
            ['Dragon Raja', 'dragon-raja', false, false],
            ['Football Master 2', 'football-master-2', false, false],
            ['Garena Shell', 'garena-shell', false, false],
            ['Goddess of Victory: Nikke', 'goddess-of-victory-nikke', true, false],
            ['Metal Slug: Awakening', 'metal-slug-awakening', false, false],
            ['Ragnarok M: Eternal Love', 'ragnarok-m-eternal-love', true, false],
        ];

        DB::table('nickname_game_codes')->insert(array_map(
            fn (array $row, int $index): array => [
                'name' => $row[0],
                'code' => $row[1],
                'requires_server' => $row[2],
                'requires_region_check' => $row[3],
                'is_active' => true,
                'sort_order' => $index,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $rows,
            array_keys($rows),
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('nickname_game_codes');
    }
};
