<?php

use App\Models\IntegrationCredential;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $record = IntegrationCredential::query()
            ->where('code', 'kokinpay')
            ->first();

        if (! $record) {
            return;
        }

        $config = is_array($record->config_ciphertext)
            ? $record->config_ciphertext
            : [];

        $defaults = [
            'base_url' => 'https://api.kokinpay.com',
            'nickname_path' => '/v1/check-nickname',
            'region_path' => '/v1/check-region',
            'pln_path' => '/v1/check-pln',
        ];

        $changed = false;
        foreach ($defaults as $key => $value) {
            if (trim((string) ($config[$key] ?? '')) === '') {
                $config[$key] = $value;
                $changed = true;
            }
        }

        if ($changed) {
            $record->config_ciphertext = $config;
            $record->save();
        }
    }

    public function down(): void
    {
        // Data defaults are intentionally retained so an application rollback
        // never discards an administrator's integration configuration.
    }
};
