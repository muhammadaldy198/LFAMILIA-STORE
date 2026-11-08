<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Saved admin overrides for the FAQ failed-order card would keep
        // showing the old "Gagal / Refund" text, because saved presentation
        // values win over the defaults. Clear an override only while it
        // still holds the exact old default text; copy an admin has
        // customised — refund-related or not — is their content and stays
        // untouched, as does every other saved value.
        $row = DB::table('system_settings')->where('key', 'store.customer_presentation')->first();
        if ($row === null) {
            return;
        }

        $value = json_decode((string) $row->value, true);
        if (! is_array($value) || ! isset($value['text']) || ! is_array($value['text'])) {
            return;
        }

        $oldDefaults = [
            'faq.status.04.title' => 'Gagal / Refund',
            'faq.status.04.body' => 'Jika transaksi gagal final, tim akan menangani sesuai kebijakan refund.',
        ];
        $changed = false;
        foreach ($oldDefaults as $key => $oldDefault) {
            if (($value['text'][$key] ?? null) === $oldDefault) {
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
