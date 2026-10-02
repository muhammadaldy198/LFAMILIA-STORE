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
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });

        $now = now();
        $rows = [
            ['Mobile Legends', 'mobile-legends', true],
            ['Free Fire', 'free-fire', false],
            ['PUBG Mobile', 'pubg-mobile', false],
            ['Call of Duty Mobile', 'call-of-duty-mobile', false],
            ['Valorant', 'valorant', false],
            ['Genshin Impact', 'genshin-impact', true],
            ['Honor of Kings', 'honor-of-kings', false],
            ['League of Legends: Wild Rift', 'league-of-legends-wild-rift', false],
            ['Arena of Valor', 'arena-of-valor', false],
            ['Point Blank', 'point-blank', false],
            ['Free Fire Max', 'free-fire-max', false],
            ['Whiteout Survival', 'whiteout-survival', false],
            ['Honkai Impact 3', 'honkai-impact-3', false],
            ['Honkai: Star Rail', 'honkai-star-rail', true],
            ['Eggy Party', 'eggy-party', true],
            ['Undawn', 'undawn', false],
            ['Growtopia', 'growtopia', false],
            ['League of Legends PC', 'league-of-legends-pc', false],
            ['FC Mobile', 'fc-mobile', false],
            ['Super Sus', 'super-sus', false],
            ['Harry Potter: Magic Awakened', 'harry-potter-magic-awakened', true],
            ['Revelation: Infinite Journey', 'revelation-infinite-journey', false],
            ['MU Origin 3', 'mu-origin-3', false],
            ['Sausage Man', 'sausage-man', false],
            ['Speed Drifters', 'speed-drifters', false],
            ['Tom and Jerry: Chase', 'tom-and-jerry-chase', true],
            ['Teamfight Tactics Mobile', 'teamfight-tactics-mobile', false],
            ['LifeAfter', 'lifeafter', true],
            ['Laplace M', 'laplace-m', false],
            ['Arena Breakout', 'arena-breakout', false],
            ['Zenless Zone Zero', 'zenless-zone-zero', true],
            ['AFK Journey', 'afk-journey', false],
            ['Magic Chess Go Go', 'magic-chess-go-go', true],
            ['Love and Deepspace', 'love-and-deepspace', false],
            ['Pokemon Unite', 'pokemon-unite', false],
            ['Dragon Raja', 'dragon-raja', false],
            ['Football Master 2', 'football-master-2', false],
            ['Garena Shell', 'garena-shell', false],
            ['Goddess of Victory: Nikke', 'goddess-of-victory-nikke', true],
            ['Metal Slug: Awakening', 'metal-slug-awakening', false],
            ['Ragnarok M: Eternal Love', 'ragnarok-m-eternal-love', true],
        ];

        DB::table('nickname_game_codes')->insert(array_map(
            fn (array $row, int $index): array => [
                'name' => $row[0],
                'code' => $row[1],
                'requires_server' => $row[2],
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
