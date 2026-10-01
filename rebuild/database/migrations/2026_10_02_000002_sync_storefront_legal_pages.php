<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $pages = json_decode(file_get_contents(database_path('data/legal-pages-2026-10-02.json')), true, 512, JSON_THROW_ON_ERROR);
        DB::transaction(function () use ($pages): void {
            foreach ($pages as $key => $page) {
                DB::table('content_pages')->updateOrInsert(['key' => $key], [
                    'title' => $page['title'],
                    'intro' => $page['intro'],
                    'body' => $page['body'],
                    'is_active' => true,
                    'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        // Preserve store-managed legal content when rolling back code.
    }
};
