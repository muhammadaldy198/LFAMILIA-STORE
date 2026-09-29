<?php

namespace App\Services;

use App\Exceptions\CheckoutValidationException;
use Illuminate\Support\Facades\DB;

class PaymentChannelService
{
    /** @return array<string,mixed>|null */
    public function activeChannel(string $method, string $channel): ?array
    {
        $row = DB::table('payment_channels')
            ->where('method', $method)
            ->where('channel', $channel)
            ->where('is_active', 1)
            ->first();

        if (!$row || !in_array($row->gateway, ['midtrans', 'doku'], true)) {
            return null;
        }

        $gatewayActive = DB::table('payment_gateway_settings')
            ->where('gateway', $row->gateway)
            ->where('is_active', 1)
            ->exists();

        if (!$gatewayActive) {
            return null;
        }

        $config = json_decode((string) ($row->gateway_config_json ?? '{}'), true);
        if (!is_array($config)) {
            $config = [];
        }
        $config = array_filter($config, fn ($value, $key) => is_string($key) && is_string($value), ARRAY_FILTER_USE_BOTH);

        return [
            'id' => (int) $row->id,
            'method' => (string) $row->method,
            'channel' => (string) $row->channel,
            'name' => (string) $row->name,
            'gateway' => (string) $row->gateway,
            'gatewayConfig' => [
                'customerFeeEnabled' => 'true',
                'customerFeeMode' => 'fixed',
                'customerFeeBps' => '0',
                'customerFeeFixed' => '0',
                ...$config,
            ],
        ];
    }

    public function customerFee(int $amount, array $config): int
    {
        if ($amount < 0) {
            throw new CheckoutValidationException('Nominal pembayaran tidak valid.');
        }

        $rawEnabled = $config['customerFeeEnabled'] ?? null;
        $enabled = !in_array(strtolower(trim((string) $rawEnabled)), ['false', '0'], true);
        if ($rawEnabled === null || $rawEnabled === '') {
            $enabled = true;
        }
        if (!$enabled) {
            return 0;
        }

        $mode = strtolower(trim((string) ($config['customerFeeMode'] ?? '')));
        $bps = $this->integer($config['customerFeeBps'] ?? 0);
        $fixed = $this->integer($config['customerFeeFixed'] ?? 0);
        if (!in_array($mode, ['percent', 'fixed'], true)) {
            $mode = $bps > 0 ? 'percent' : 'fixed';
        }

        if ($bps < 0 || $bps >= 10000) {
            throw new CheckoutValidationException('Persentase biaya payment gateway tidak valid.');
        }
        if ($fixed < 0 || $fixed > 100000000) {
            throw new CheckoutValidationException('Biaya tetap payment gateway tidak valid.');
        }

        if ($mode === 'fixed') {
            return $fixed;
        }
        if ($bps === 0) {
            return 0;
        }

        $total = (int) ceil(($amount * 10000) / (10000 - $bps));
        if ($total < $amount) {
            throw new CheckoutValidationException('Total pembayaran setelah biaya tidak valid.');
        }

        return $total - $amount;
    }

    public function paymentType(string $gateway, string $method, string $channel, array $config): ?string
    {
        $custom = trim((string) ($config['paymentType'] ?? ''));
        if ($custom !== '') {
            return $gateway === 'doku'
                ? $this->canonicalDokuType($method, $custom)
                : $custom;
        }

        $key = $method.':'.$channel;
        if ($gateway === 'midtrans') {
            return [
                'va:bca' => 'bca_va',
                'va:mandiri' => 'echannel',
                'va:bni' => 'bni_va',
                'va:bri' => 'bri_va',
                'va:cimb' => 'cimb_va',
                'va:permata' => 'permata_va',
                'va:danamon' => 'danamon_va',
                'ewallet:gopay' => 'gopay',
                'ewallet:ovo' => 'ovo',
                'ewallet:dana' => 'dana',
                'ewallet:shopeepay' => 'shopeepay',
                'qris:mpm' => 'other_qris',
                'qris:qris' => 'other_qris',
            ][$key] ?? null;
        }

        return [
            'va:doku' => 'VIRTUAL_ACCOUNT_DOKU',
            'va:bca' => 'VIRTUAL_ACCOUNT_BCA',
            'va:mandiri' => 'VIRTUAL_ACCOUNT_BANK_MANDIRI',
            'va:bsi' => 'VIRTUAL_ACCOUNT_BANK_SYARIAH_MANDIRI',
            'va:bri' => 'VIRTUAL_ACCOUNT_BRI',
            'va:bni' => 'VIRTUAL_ACCOUNT_BNI',
            'va:permata' => 'VIRTUAL_ACCOUNT_BANK_PERMATA',
            'va:cimb' => 'VIRTUAL_ACCOUNT_BANK_CIMB',
            'va:danamon' => 'VIRTUAL_ACCOUNT_BANK_DANAMON',
            'va:btn' => 'VIRTUAL_ACCOUNT_BTN',
            'va:bnc' => 'VIRTUAL_ACCOUNT_BNC',
            'va:bss' => 'VIRTUAL_ACCOUNT_BSS',
            'va:bjb' => 'VIRTUAL_ACCOUNT_BJB',
            'va:sinarmas' => 'VIRTUAL_ACCOUNT_Sinarmas',
            'ewallet:ovo' => 'EMONEY_OVO',
            'ewallet:shopeepay' => 'EMONEY_SHOPEE_PAY',
            'ewallet:doku' => 'EMONEY_DOKU',
            'ewallet:linkaja' => 'EMONEY_LINKAJA',
            'ewallet:dana' => 'EMONEY_DANA',
            'qris:mpm' => 'QRIS',
            'qris:qris' => 'QRIS',
        ][$key] ?? null;
    }

    private function canonicalDokuType(string $method, string $value): ?string
    {
        $allowed = [
            'va' => [
                'VIRTUAL_ACCOUNT_DOKU',
                'VIRTUAL_ACCOUNT_BCA',
                'VIRTUAL_ACCOUNT_BANK_MANDIRI',
                'VIRTUAL_ACCOUNT_BANK_SYARIAH_MANDIRI',
                'VIRTUAL_ACCOUNT_BRI',
                'VIRTUAL_ACCOUNT_BNI',
                'VIRTUAL_ACCOUNT_BANK_PERMATA',
                'VIRTUAL_ACCOUNT_BANK_CIMB',
                'VIRTUAL_ACCOUNT_BANK_DANAMON',
                'VIRTUAL_ACCOUNT_BTN',
                'VIRTUAL_ACCOUNT_BNC',
                'VIRTUAL_ACCOUNT_BSS',
                'VIRTUAL_ACCOUNT_BJB',
                'VIRTUAL_ACCOUNT_Sinarmas',
            ],
            'ewallet' => [
                'EMONEY_OVO',
                'EMONEY_SHOPEE_PAY',
                'EMONEY_DOKU',
                'EMONEY_LINKAJA',
                'EMONEY_DANA',
            ],
            'qris' => ['QRIS'],
        ][$method] ?? [];

        $upper = strtoupper($value);
        $upper = $upper === 'EMONEY_SHOPEEPAY' ? 'EMONEY_SHOPEE_PAY' : $upper;
        $upper = $upper === 'VIRTUAL_ACCOUNT_SINARMAS' ? 'VIRTUAL_ACCOUNT_SINARMAS' : $upper;

        foreach ($allowed as $candidate) {
            if (strtoupper($candidate) === $upper) {
                return $candidate;
            }
        }

        return null;
    }

    private function integer(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', trim($value))) {
            return (int) trim($value);
        }

        return 0;
    }
}
