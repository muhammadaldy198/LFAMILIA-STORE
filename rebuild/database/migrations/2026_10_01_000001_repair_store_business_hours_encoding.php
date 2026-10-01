<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('system_settings')) {
            return;
        }

        $raw = DB::table('system_settings')
            ->where('key', 'store.business_hours')
            ->value('value');
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        if (is_string($decoded) && str_contains($decoded, 'â€“')) {
            DB::table('system_settings')
                ->where('key', 'store.business_hours')
                ->update([
                    'value' => json_encode(str_replace('â€“', '–', $decoded), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void {}
};
