<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('site_popups')) {
            return;
        }

        $keeperId = DB::table('site_popups')->orderBy('id')->value('id');

        if ($keeperId !== null) {
            DB::table('site_popups')->where('id', '!=', $keeperId)->delete();
            DB::table('site_popups')->where('id', $keeperId)->update([
                'primary_label' => null,
                'primary_href' => null,
                'secondary_label' => null,
                'secondary_href' => null,
                'sort_order' => 0,
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Singleton cleanup is intentionally not reversed.
    }
};
