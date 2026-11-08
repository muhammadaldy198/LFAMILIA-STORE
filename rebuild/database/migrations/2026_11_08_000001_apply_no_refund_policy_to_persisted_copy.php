<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->clearFaqOverrides();
        $this->updatePersistedRefundPage();
    }

    public function down(): void
    {
        // Persisted copy is not reverted to refund-promising wording.
    }

    /**
     * Saved admin overrides for the FAQ failed-order card would keep showing
     * the old "Gagal / Refund" text, because saved presentation values win
     * over the defaults. Clear exactly those two overrides so the current
     * defaults apply; every other saved value is left untouched.
     */
    private function clearFaqOverrides(): void
    {
        $row = DB::table('system_settings')->where('key', 'store.customer_presentation')->first();
        if ($row === null) {
            return;
        }

        $value = json_decode((string) $row->value, true);
        if (! is_array($value) || ! isset($value['text']) || ! is_array($value['text'])) {
            return;
        }

        unset($value['text']['faq.status.04.title'], $value['text']['faq.status.04.body']);

        DB::table('system_settings')->where('key', 'store.customer_presentation')
            ->update(['value' => json_encode($value), 'updated_at' => now()]);
    }

    /**
     * The /refund page renders from content_pages, so correcting the seed
     * JSON alone never reaches an already-migrated store. Replace the
     * persisted page with the current seed only while it still carries the
     * old refund-promise wording; a page the admin has already rewritten
     * (including with the new text) does not match the signatures and is
     * left untouched.
     */
    private function updatePersistedRefundPage(): void
    {
        $page = DB::table('content_pages')->where('key', 'refund')->first();
        if ($page === null) {
            return;
        }

        $stillPromisesRefunds = str_contains((string) $page->intro, 'refund hanya tersedia untuk kondisi tertentu')
            || str_contains((string) $page->body, 'Cara mengajukan refund');
        if (! $stillPromisesRefunds) {
            return;
        }

        $seed = json_decode(file_get_contents(database_path('data/legal-pages-2026-10-02.json')), true, 512, JSON_THROW_ON_ERROR);
        DB::table('content_pages')->where('key', 'refund')->update([
            'title' => $seed['refund']['title'],
            'intro' => $seed['refund']['intro'],
            'body' => $seed['refund']['body'],
            'updated_at' => now(),
        ]);
    }
};
