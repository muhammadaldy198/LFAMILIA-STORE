<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PaymentRouteCatalogService
{
    /**
     * Ensure source-controlled payment protocol routes exist.
     *
     * Existing operational settings (priority, purposes and active state) are
     * preserved. Only protocol-owned fields are refreshed from the repository.
     *
     * @return array{created:int,updated:int,skipped:int,created_channels:int}
     */
    public function sync(): array
    {
        // The route catalog is also used by historical migrations, before
        // payment_categories/category_code exist. Only populate category_code
        // once the migration adding it has run.
        $hasCategories = Schema::hasColumn('payment_channels', 'category_code');
        $result = [
            'created' => 0, 'updated' => 0, 'skipped' => 0,
            'created_channels' => $this->ensureBankChannels($hasCategories)
                + $this->ensureEwalletChannels($hasCategories)
                + $this->ensureRetailChannels($hasCategories),
        ];

        foreach ((array) config('payment_routes', []) as $definition) {
            $channelId = DB::table('payment_channels')
                ->where('code', (string) ($definition['channel'] ?? ''))
                ->value('id');
            $gatewayId = DB::table('payment_gateways')
                ->where('code', (string) ($definition['gateway'] ?? ''))
                ->value('id');

            if (! $channelId || ! $gatewayId) {
                $result['skipped']++;

                continue;
            }

            $protocol = [
                'provider_channel' => $definition['provider_channel'] ?? null,
                'configuration' => isset($definition['configuration'])
                    ? json_encode($definition['configuration'], JSON_THROW_ON_ERROR)
                    : null,
                'updated_at' => now(),
            ];

            $existing = DB::table('payment_routes')
                ->where('payment_channel_id', $channelId)
                ->where('payment_gateway_id', $gatewayId)
                ->first();

            if ($existing) {
                DB::table('payment_routes')->where('id', $existing->id)->update($protocol);
                $result['updated']++;

                continue;
            }

            DB::table('payment_routes')->insert([
                'payment_channel_id' => $channelId,
                'payment_gateway_id' => $gatewayId,
                ...$protocol,
                'priority' => (int) ($definition['priority'] ?? 100),
                'supports_order' => (bool) ($definition['supports_order'] ?? true),
                'supports_wallet_topup' => (bool) ($definition['supports_wallet_topup'] ?? false),
                // Bank VA routes are pre-wired automatically. Gateway credentials,
                // maintenance state and the channel toggle still gate visibility.
                'is_active' => ($definition['gateway'] ?? '') === 'MIDTRANS'
                    && collect(config('payment_bank_channels', []))->contains(
                        fn (array $bank): bool => $bank['code'] === ($definition['channel'] ?? '')
                            && ($bank['initially_active'] ?? true) === true
                    ),
                'created_at' => now(),
            ]);
            $result['created']++;
        }

        return $result;
    }

    /**
     * Seed individual e-wallets only once; never overwrite admin fee or activation choices.
     */
    private function ensureEwalletChannels(bool $hasCategories): int
    {
        $generic = DB::table('payment_channels')->where('code', 'ewallet')->first();
        if (! $generic) {
            return 0;
        }

        $created = 0;
        foreach ((array) config('payment_ewallet_channels', []) as $wallet) {
            if (! empty($wallet['requires_integration'])) {
                continue; // Listed for reference only; no incomplete payment channel.
            }

            $code = (string) ($wallet['code'] ?? '');
            if ($code === '' || DB::table('payment_channels')->where('code', $code)->exists()) {
                continue;
            }

            DB::table('payment_channels')->insert([
                'code' => $code,
                'method' => 'EWALLET',
                ...($hasCategories ? ['category_code' => 'ewallet'] : []),
                'name' => (string) $wallet['name'],
                'description' => 'Bayar langsung melalui '.(string) $wallet['name'].'.',
                'fee_flat_idr' => 0,
                'fee_percent_bps' => 0,
                'supports_order' => (bool) $generic->supports_order,
                'supports_wallet_topup' => (bool) $generic->supports_wallet_topup,
                'sort_order' => (int) $wallet['sort_order'],
                'is_active' => false, // opt-in; merchant account support must be confirmed
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $created++;
        }

        return $created;
    }

    /**
     * Create retail channels without turning on a store method before merchant activation.
     */
    private function ensureRetailChannels(bool $hasCategories): int
    {
        $generic = DB::table('payment_channels')->where('code', 'ewallet')->first();
        if (! $generic) {
            return 0;
        }

        $created = 0;
        foreach ((array) config('payment_retail_channels', []) as $store) {
            if (DB::table('payment_channels')->where('code', $store['code'])->exists()) {
                continue;
            }

            DB::table('payment_channels')->insert([
                'code' => $store['code'],
                'method' => 'RETAIL',
                ...($hasCategories ? ['category_code' => 'retail'] : []),
                'name' => $store['name'],
                'description' => 'Bayar melalui '.(string) $store['name'].'.',
                'fee_flat_idr' => 0,
                'fee_percent_bps' => 0,
                'supports_order' => (bool) $generic->supports_order,
                'supports_wallet_topup' => (bool) $generic->supports_wallet_topup,
                'sort_order' => (int) $store['sort_order'],
                'is_active' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $created++;
        }

        return $created;
    }

    /**
     * Provision bank choices without overwriting operator-edited fees, order,
     * visibility or support flags on subsequent catalog synchronizations.
     */
    private function ensureBankChannels(bool $hasCategories): int
    {
        $generic = DB::table('payment_channels')->where('code', 'virtual_account')->first();
        if (! $generic) {
            return 0;
        }

        $created = 0;
        foreach ((array) config('payment_bank_channels', []) as $bank) {
            $code = (string) ($bank['code'] ?? '');
            if ($code === '' || DB::table('payment_channels')->where('code', $code)->exists()) {
                continue;
            }

            DB::table('payment_channels')->insert([
                'code' => $code,
                'method' => 'VIRTUAL_ACCOUNT',
                ...($hasCategories ? ['category_code' => 'va'] : []),
                'name' => (string) $bank['name'],
                'description' => 'Transfer otomatis melalui '.(string) $bank['name'].'.',
                'fee_flat_idr' => (int) $generic->fee_flat_idr,
                'fee_percent_bps' => (int) $generic->fee_percent_bps,
                'supports_order' => (bool) $generic->supports_order,
                'supports_wallet_topup' => (bool) $generic->supports_wallet_topup,
                'sort_order' => (int) $bank['sort_order'],
                'is_active' => (bool) ($bank['initially_active'] ?? $generic->is_active),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $created++;
        }

        return $created;
    }
}
