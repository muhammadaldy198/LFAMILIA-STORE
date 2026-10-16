<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Canonicalize existing phone_normalized values to the 8xx... form
        // so that 081... and 6281... do not collide on the unique index.
        DB::table('users')->whereNotNull('phone_normalized')->orderBy('id')->chunkById(500, function ($users): void {
            foreach ($users as $user) {
                $digits = $user->phone_normalized;
                if (str_starts_with($digits, '62') && strlen($digits) > 2) {
                    $canonical = substr($digits, 2);
                } elseif (str_starts_with($digits, '0') && strlen($digits) > 1) {
                    $canonical = substr($digits, 1);
                } else {
                    $canonical = $digits;
                }
                if ($canonical !== $digits) {
                    DB::table('users')->where('id', $user->id)->update(['phone_normalized' => $canonical]);
                }
            }
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['phone_normalized']);
            $table->unique('phone_normalized', 'users_phone_normalized_unique');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique('users_phone_normalized_unique');
            $table->index('phone_normalized', 'users_phone_normalized_index');
        });
    }
};
