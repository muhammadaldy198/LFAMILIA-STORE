<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // The no-refund policy (PRD section 10) replaced the FAQ failed-order
        // card's wording. A previously saved admin override for either of
        // these two keys would keep showing the old "Gagal / Refund" text,
        // because saved values win over the defaults. Clear exactly those
        // two overrides so the current defaults apply; every other saved
        // presentation value is left untouched.
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

    public function down(): void
    {
        // Cleared copy overrides are not restored; the old wording pointed
        // customers at a refund policy the store does not offer.
    }
};
