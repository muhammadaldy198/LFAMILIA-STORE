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
        // phone_normalized value. Canonical form keeps the international country
        // code prefix (e.g. 62812... for Indonesia) so phone namespaces across
        // 248 countries never collide.
        DB::table('users')->whereNotNull('phone')->orderBy('id')->chunkById(500, function ($users): void {
            foreach ($users as $user) {
                $digits = preg_replace('/[^0-9]+/', '', (string) $user->phone) ?: null;
                if ($digits !== null) {
                    if (str_starts_with($digits, '0') && strlen($digits) > 1) {
                        $digits = '62'.substr($digits, 1);
                    } elseif (str_starts_with($digits, '8') && strlen($digits) >= 8) {
                        $digits = '62'.$digits;
                    }
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
            $maskedExamples = $duplicates->take(10)->map(function ($row): string {
                $raw = (string) $row->phone_normalized;
                $len = strlen($raw);
                if ($len <= 4) {
                    return str_repeat('*', $len);
                }

                return substr($raw, 0, 2).str_repeat('*', max(1, $len - 4)).substr($raw, -2);
            })->implode(', ');

            throw new RuntimeException('Duplicate canonical phone numbers prevent the unique index. Resolve accounts explicitly before retrying. Collision count: '.$duplicates->count().' (masked examples: '.$maskedExamples.')');
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
