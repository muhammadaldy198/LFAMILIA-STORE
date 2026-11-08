<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Saved admin overrides for the FAQ failed-order card would keep
        // showing the old "Gagal / Refund" text, because saved presentation
        // values win over the defaults. Clear an override while it still
        // promises refunds — the exact old default text, or any customised
        // wording that still mentions a refund or "pengembalian dana".
        // Customised copy without a refund promise is the admin's content
        // and stays untouched, as does every other saved value.
        $row = DB::table('system_settings')->where('key', 'store.customer_presentation')->first();
        if ($row === null) {
            return;
        }

        $value = json_decode((string) $row->value, true);
        if (! is_array($value) || ! isset($value['text']) || ! is_array($value['text'])) {
            return;
        }

        $changed = false;
        foreach (['faq.status.04.title', 'faq.status.04.body'] as $key) {
            $saved = $value['text'][$key] ?? null;
            if (is_string($saved) && (stripos($saved, 'refund') !== false || stripos($saved, 'pengembalian dana') !== false)) {
                unset($value['text'][$key]);
                $changed = true;
            }
        }
        if (! $changed) {
            return;
        }

        DB::table('system_settings')->where('key', 'store.customer_presentation')
            ->update(['value' => json_encode($value), 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Cleared copy overrides are not restored; the old wording pointed
        // customers at a refund policy the store does not offer.
    }
};
