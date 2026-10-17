<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Rebuild the derived value from phone, not a potentially empty/stale
        // phone_normalized value. This migration intentionally aborts (without
        // merging accounts) if legacy phones collide in canonical form.
        DB::table('users')->whereNotNull('phone')->orderBy('id')->chunkById(500, function ($users): void {
            foreach ($users as $user) {
                $digits = preg_replace('/[^0-9]+/', '', (string) $user->phone) ?: null;
                if ($digits !== null && str_starts_with($digits, '62')) {
                    $digits = substr($digits, 2);
                } elseif ($digits !== null && str_starts_with($digits, '0')) {
                    $digits = substr($digits, 1);
                }
                DB::table('users')->where('id', $user->id)->update(['phone_normalized' => $digits]);
            }
        });

        $duplicates = DB::table('users')
            ->select('phone_normalized', DB::raw('COUNT(*) as aggregate'))
            ->whereNotNull('phone_normalized')
            ->groupBy('phone_normalized')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($duplicates->isNotEmpty()) {
            $examples = $duplicates->take(10)->pluck('phone_normalized')->implode(', ');
            throw new RuntimeException('Duplicate canonical phone numbers prevent the unique index. Resolve accounts explicitly before retrying. Examples: '.$examples);
        }

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
