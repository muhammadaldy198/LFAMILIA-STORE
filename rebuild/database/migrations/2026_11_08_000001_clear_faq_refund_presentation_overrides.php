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
        // still promises refunds (see promisesRefund()); copy that merely
        // mentions refunds to say there are none, and copy with no refund
        // promise at all, is the admin's content and stays untouched, as
        // does every other saved value.
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
            if (is_string($saved) && $this->promisesRefund($saved, $key === 'faq.status.04.title')) {
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

    /**
     * Whether saved copy still offers buyers a refund. Denials are checked
     * first, so wording like "tidak ada refund" is preserved; the old
     * defaults and any phrasing that points buyers at a refund policy, a
     * refund application, or a refund outcome count as promises. A title
     * is only a label, so any refund mention in it frames the card as
     * being about refunds and counts as well.
     */
    private function promisesRefund(string $text, bool $isTitle): bool
    {
        $t = mb_strtolower($text);

        $denials = [
            'tidak ada refund', 'tidak ada pengembalian dana',
            'tidak melayani refund', 'tidak melayani pengembalian dana',
            'refund tidak tersedia', 'pengembalian dana tidak tersedia',
            'tidak dapat direfund', 'tidak bisa direfund',
            'tidak akan direfund', 'tidak direfund',
            'tanpa refund', 'tanpa pengembalian dana',
            'bukan refund', 'no refund',
        ];
        foreach ($denials as $denial) {
            if (str_contains($t, $denial)) {
                return false;
            }
        }

        if ($isTitle) {
            return str_contains($t, 'refund') || str_contains($t, 'pengembalian dana');
        }

        $promises = [
            'kebijakan refund', 'kebijakan pengembalian',
            'refund hanya tersedia', 'pengembalian dana hanya tersedia',
            'direfund',
        ];
        foreach ($promises as $promise) {
            if (str_contains($t, $promise)) {
                return true;
            }
        }

        // Application wording counts only together with a refund term, so
        // unrelated guidance ("pengajuan pengecekan", "ajukan pertanyaan")
        // is never treated as a refund promise.
        $hasApplicationWord = str_contains($t, 'pengajuan') || str_contains($t, 'mengajukan') || str_contains($t, 'ajukan');
        $hasRefundTerm = str_contains($t, 'refund') || str_contains($t, 'pengembalian');

        return $hasApplicationWord && $hasRefundTerm;
    }

    public function down(): void
    {
        // Cleared copy overrides are not restored; the old wording pointed
        // customers at a refund policy the store does not offer.
    }
};
