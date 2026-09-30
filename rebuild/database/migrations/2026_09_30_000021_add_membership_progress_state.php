<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('membership_mode', 16)->default('AUTO')->after('membership_tier_code');
            $table->string('membership_override_code', 20)->nullable()->after('membership_mode');
            $table->unsignedBigInteger('membership_progress_bonus_idr')->default(0)->after('membership_override_code');
            $table->foreign('membership_override_code')->references('code')->on('membership_tiers')->nullOnDelete();
            $table->index(['membership_mode', 'membership_override_code']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['membership_override_code']);
            $table->dropIndex(['membership_mode', 'membership_override_code']);
            $table->dropColumn(['membership_mode', 'membership_override_code', 'membership_progress_bonus_idr']);
        });
    }
};
