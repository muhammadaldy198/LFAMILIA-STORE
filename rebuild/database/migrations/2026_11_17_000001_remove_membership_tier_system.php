<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Remove the membership tier system: table membership_tiers and related
     * columns from users. This system was never used (all tier benefits and
     * discounts were always zero/null).
     */
    public function up(): void
    {
        // Drop foreign keys from users table first
        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['membership_tier_code']);
            $table->dropForeign(['membership_override_code']);
        });

        // Drop membership-related columns from users
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['membership_mode', 'membership_override_code']);
            $table->dropColumn([
                'membership_tier_code',
                'membership_mode',
                'membership_override_code',
                'membership_progress_bonus_idr',
            ]);
        });

        // Drop membership_tiers table
        Schema::dropIfExists('membership_tiers');
    }

    /**
     * Restore the membership tier system exactly as it was before removal,
     * including the original 6 tier seeds with empty requirements/benefits.
     */
    public function down(): void
    {
        // Recreate membership_tiers table
        Schema::create('membership_tiers', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 20)->unique();
            $table->unsignedTinyInteger('rank')->unique();
            $table->boolean('is_active')->default(true);
            $table->json('requirements')->nullable();
            $table->json('benefits')->nullable();
            $table->timestamps();
        });

        // Seed the original 6 tiers exactly as created initially
        $now = now();
        DB::table('membership_tiers')->insert(
            collect(['BASIC', 'SILVER', 'GOLD', 'DIAMOND', 'PLATINUM', 'MAFIA'])
                ->map(fn (string $code, int $rank): array => [
                    'code' => $code,
                    'rank' => $rank + 1,
                    'is_active' => true,
                    'requirements' => null,
                    'benefits' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all()
        );

        // Restore membership columns to users table
        Schema::table('users', function (Blueprint $table): void {
            $table->string('membership_tier_code', 20)->default('BASIC')->after('google_sub');
            $table->foreign('membership_tier_code')->references('code')->on('membership_tiers')->restrictOnDelete();
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->string('membership_mode', 16)->default('AUTO')->after('membership_tier_code');
            $table->string('membership_override_code', 20)->nullable()->after('membership_mode');
            $table->unsignedBigInteger('membership_progress_bonus_idr')->default(0)->after('membership_override_code');
            $table->foreign('membership_override_code')->references('code')->on('membership_tiers')->nullOnDelete();
            $table->index(['membership_mode', 'membership_override_code']);
        });
    }
};
