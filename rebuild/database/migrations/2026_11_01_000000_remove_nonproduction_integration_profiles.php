<?php

use App\Models\IntegrationCredential;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Retire stored non-production profiles without touching the production
     * credentials used for payment verification and fulfillment.
     */
    public function up(): void
    {
        // Existing nonterminal attempts have no trusted environment origin.
        // The same Digiflazz endpoint creates real purchases, so never replay
        // them with credentials selected after this environment cutover.
        DB::table('fulfillment_attempts as attempts')
            ->join('provider_mappings as mappings', 'mappings.id', '=', 'attempts.provider_mapping_id')
            ->join('providers', 'providers.id', '=', 'mappings.provider_id')
            ->where('providers.code', 'DIGIFLAZZ')
            ->whereIn('attempts.status', ['CREATED', 'SENDING', 'PENDING', 'UNKNOWN'])
            ->select(['attempts.id', 'attempts.order_id'])
            ->orderBy('attempts.id')
            ->chunkById(100, function ($attempts): void {
                foreach ($attempts as $candidate) {
                    DB::transaction(function () use ($candidate): void {
                        $attempt = DB::table('fulfillment_attempts')
                            ->where('id', $candidate->id)->lockForUpdate()->first();
                        if (! $attempt || ! in_array($attempt->status, ['CREATED', 'SENDING', 'PENDING', 'UNKNOWN'], true)) {
                            return;
                        }

                        $order = DB::table('orders')->where('id', $attempt->order_id)->lockForUpdate()->first();
                        $reason = 'Attempt sebelum penguncian Production; asal environment belum bisa diverifikasi. Jangan kirim ulang otomatis.';
                        DB::table('fulfillment_attempts')->where('id', $attempt->id)->update([
                            'status' => 'BLOCKED',
                            'safe_to_failover' => false,
                            'last_error' => $reason,
                            'reconcile_claimed_at' => null,
                            'updated_at' => now(),
                        ]);
                        if ($order && in_array($order->status, ['PAID', 'PROCESSING'], true)) {
                            DB::table('orders')->where('id', $order->id)->update([
                                'status' => 'BLOCKED',
                                'updated_at' => now(),
                            ]);
                            DB::table('order_events')->insert([
                                'order_id' => $order->id,
                                'event_type' => 'FULFILLMENT_ENV_QUARANTINED',
                                'from_status' => $order->status,
                                'to_status' => 'BLOCKED',
                                'correlation_id' => (string) $attempt->correlation_id,
                                'metadata' => json_encode(['attempt_id' => $attempt->id], JSON_THROW_ON_ERROR),
                                'created_at' => now(),
                            ]);
                        }

                        DB::table('admin_notifications')->insert([
                            'event_type' => 'fulfillment.environment.quarantined',
                            'severity' => 'ERROR',
                            'title' => 'Pengiriman lama dikarantina saat peralihan Production',
                            'message' => 'Attempt #'.$attempt->id.' dicegah dikirim ulang otomatis karena environment asal belum pasti. Periksa status penyedia dan tangani manual.',
                            'target_type' => 'fulfillment_attempt',
                            'target_id' => (string) $attempt->id,
                            'data' => json_encode(['order_id' => $attempt->order_id], JSON_THROW_ON_ERROR),
                            'created_at' => now(),
                        ]);
                    }, 3);
                }
            }, 'attempts.id', 'id');

        IntegrationCredential::query()
            ->whereIn('code', ['midtrans', 'doku', 'digiflazz', 'telegram', 'turnstile'])
            ->each(function (IntegrationCredential $credential): void {
                try {
                    $stored = $credential->config_ciphertext;
                } catch (DecryptException) {
                    // An unreadable secret must never be overwritten.
                    return;
                }

                if (! is_array($stored) || ! is_array($stored['profiles'] ?? null)) {
                    // Legacy flat records are left intact until the production
                    // profile can be identified; never guess which key is live.
                    return;
                }

                $profiles = $stored['profiles'];
                if (! array_key_exists('test', $profiles) && ! array_key_exists('sandbox', $profiles)) {
                    return;
                }

                $stored['profiles'] = isset($profiles['production']) && is_array($profiles['production'])
                    ? ['production' => $profiles['production']]
                    : [];
                $stored['environment'] = 'production';
                unset($stored['testing'], $stored['is_production']);
                $credential->config_ciphertext = $stored;
                $credential->save();
            });
    }

    public function down(): void
    {
        // Deleted test credentials cannot be recovered and must not be fabricated.
    }
};
