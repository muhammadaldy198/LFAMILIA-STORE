<?php

use App\Models\IntegrationCredential;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Retire stored non-production profiles without touching the production
     * credentials used for payment verification and fulfillment.
     */
    public function up(): void
    {
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
