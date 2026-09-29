<?php

namespace App\Services;

use App\Exceptions\CheckoutValidationException;
use App\Support\NominalLabel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CheckoutService
{
    /** @return array<string,mixed>|null */
    public function resolveItem(string $productSlug, string $packageSku): ?array
    {
        $row = DB::table('products as p')
            ->join('product_packages as pp', 'pp.product_id', '=', 'p.id')
            ->where('p.slug', trim($productSlug))
            ->where('pp.sku', trim($packageSku))
            ->where('p.is_active', 1)
            ->where('pp.is_active', 1)
            ->select([
                'p.slug as product_slug',
                'p.name as product_name',
                'p.needs_server',
                'p.fulfillment_type',
                'p.target_template',
                'p.input_label',
                'p.input_placeholder',
                'p.input_fields_json',
                'p.manual_instructions',
                'p.manual_open_time',
                'p.manual_close_time',
                'p.manual_timezone',
                'pp.id as package_id',
                'pp.sku as package_sku',
                'pp.label as package_label',
                'pp.price',
                'pp.provider_code',
                'pp.provider_sku',
                'pp.supplier_price',
                'pp.provider_max_price',
            ])
            ->first();

        if (!$row) {
            return null;
        }

        return [
            'productSlug' => (string) $row->product_slug,
            'productName' => (string) $row->product_name,
            'needsServer' => (bool) $row->needs_server,
            'fulfillmentType' => (string) $row->fulfillment_type,
            'targetTemplate' => (string) $row->target_template,
            'inputFields' => $this->parseInputFields($row),
            'manualInstructions' => $row->manual_instructions,
            'manualOpenTime' => $row->manual_open_time,
            'manualCloseTime' => $row->manual_close_time,
            'manualTimezone' => $row->manual_timezone ?: 'Asia/Jakarta',
            'packageId' => (int) $row->package_id,
            'packageSku' => (string) $row->package_sku,
            'packageLabel' => NominalLabel::clean((string) $row->product_name, (string) $row->package_label),
            'price' => (int) $row->price,
            'providerCode' => trim(strtolower((string) ($row->provider_code ?? ''))) ?: null,
            'providerSku' => trim((string) ($row->provider_sku ?? '')) ?: null,
            'supplierCost' => $row->supplier_price === null ? null : (int) $row->supplier_price,
            'providerMaxPrice' => $row->provider_max_price === null ? null : (int) $row->provider_max_price,
        ];
    }

    public function assertPurchasable(array $item, int $quantity): void
    {
        if ($quantity < 1 || $quantity > 5) {
            throw new CheckoutValidationException('Jumlah pembelian harus antara 1 sampai 5.');
        }

        if ($quantity > 1 && $item['providerCode'] === 'voucher-stock') {
            throw new CheckoutValidationException('Produk kode voucher hanya dapat dibeli 1 item per pesanan.');
        }

        if ($item['fulfillmentType'] === 'manual') {
            $this->assertManualOpen($item);
            return;
        }

        if (!$item['providerCode'] || !$item['providerSku']) {
            throw new CheckoutValidationException('Konfigurasi pemrosesan otomatis belum lengkap.');
        }

        if ($item['providerCode'] === 'voucher-stock') {
            $available = DB::table('voucher_codes')
                ->where('stock_key', $item['providerSku'])
                ->where('status', 'available')
                ->exists();
            if (!$available) {
                throw new CheckoutValidationException('Nominal otomatis sedang tidak tersedia.');
            }
            return;
        }

        if ($item['providerCode'] !== 'digiflazz') {
            throw new CheckoutValidationException('Sistem pemrosesan otomatis belum tersedia.');
        }

        $monitor = DB::table('digiflazz_seller_monitor')
            ->where('package_id', $item['packageId'])
            ->first();

        if (!$monitor
            || !(bool) $monitor->buyer_product_status
            || !(bool) $monitor->seller_product_status
            || (!(bool) $monitor->unlimited_stock && (int) $monitor->stock <= 0)) {
            throw new CheckoutValidationException('Nominal otomatis sedang tidak tersedia.');
        }

        $maxPrice = $item['providerMaxPrice'];
        if ($maxPrice !== null && $monitor->current_price !== null && (int) $monitor->current_price > $maxPrice) {
            throw new CheckoutValidationException('Harga provider melebihi Max Price. Nominal sementara dinonaktifkan.');
        }

        if ($this->cutoffActive(
            $monitor->start_cut_off ?? null,
            $monitor->end_cut_off ?? null,
            'Asia/Jakarta',
        )) {
            throw new CheckoutValidationException('Nominal otomatis sedang berada dalam waktu cut-off.');
        }
    }

    /**
     * @param array<int,array{id:string,value:string}> $submitted
     * @return array{values:array<int,array{id:string,label:string,value:string}>,destination:string,server:?string}
     */
    public function normalizeCustomerInputs(
        array $item,
        array $submitted,
        string $legacyDestination = '',
        ?string $legacyServer = null,
    ): array {
        $byId = [];
        foreach ($submitted as $entry) {
            $byId[(string) $entry['id']] = trim((string) $entry['value']);
        }

        $values = [];
        foreach ($item['inputFields'] as $index => $field) {
            $fallback = $index === 0
                ? trim($legacyDestination)
                : ($index === 1 ? trim((string) $legacyServer) : '');
            $value = trim((string) ($byId[$field['id']] ?? $fallback));

            if (($field['required'] ?? true) && $value === '') {
                throw new CheckoutValidationException($field['label'].' wajib diisi.');
            }
            if (mb_strlen($value) > 300) {
                throw new CheckoutValidationException($field['label'].' terlalu panjang.');
            }

            $values[] = [
                'id' => $field['id'],
                'label' => $field['label'],
                'value' => $value,
            ];
        }

        return [
            'values' => $values,
            'destination' => $values[0]['value'] ?? '-',
            'server' => ($values[1]['value'] ?? '') !== '' ? $values[1]['value'] : null,
        ];
    }

    /** @return array{id:string,referenceId:string} */
    public function identity(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'referenceId' => 'LF'.now('UTC')->format('ymd').strtoupper(substr(str_replace('-', '', (string) Str::uuid()), 0, 14)),
        ];
    }

    public function renderCustomerNo(array $item, string $destination, ?string $server, array $customerInputs): string
    {
        $tokens = [
            'destination' => trim($destination),
            'server' => trim((string) $server),
        ];
        foreach ($customerInputs as $input) {
            $tokens[strtolower(trim((string) $input['id']))] = trim((string) $input['value']);
        }

        $value = preg_replace_callback(
            '/\{\{([a-z0-9-]+)\}\}/i',
            static fn (array $match) => $tokens[strtolower($match[1])] ?? $match[0],
            (string) $item['targetTemplate'],
        );

        $value = trim((string) $value);
        if ($value === '' || str_contains($value, '{{') || mb_strlen($value) > 120) {
            throw new CheckoutValidationException('Format tujuan produk belum valid atau masih memiliki data yang belum terisi.');
        }

        return $value;
    }

    public function insertPendingOrder(array $data): void
    {
        DB::table('orders')->insert([
            'id' => $data['id'],
            'customer_id' => $data['customerId'] ?? null,
            'wallet_checkout_key' => $data['walletCheckoutKey'] ?? null,
            'external_checkout_key' => $data['externalCheckoutKey'] ?? null,
            'reference_id' => $data['referenceId'],
            'product_slug' => $data['item']['productSlug'],
            'product_name' => $data['item']['productName'],
            'package_sku' => $data['item']['packageSku'],
            'package_label' => $data['item']['packageLabel'],
            'provider_code' => $data['item']['providerCode'],
            'provider_sku' => $data['item']['providerSku'],
            'fulfillment_type' => $data['item']['fulfillmentType'],
            'delivery_mode' => $data['item']['fulfillmentType'] === 'manual'
                ? 'manual'
                : ($data['item']['providerCode'] === 'voucher-stock' ? 'voucher' : 'direct'),
            'supplier_cost_snapshot' => $data['item']['supplierCost'] === null
                ? null
                : $data['item']['supplierCost'] * $data['quantity'],
            'provider_max_price_snapshot' => $data['item']['providerMaxPrice'],
            'target_template' => $data['item']['targetTemplate'],
            'destination' => $data['destination'],
            'server' => $data['server'],
            'nickname' => $data['nickname'],
            'customer_no' => $this->renderCustomerNo(
                $data['item'],
                $data['destination'],
                $data['server'],
                $data['customerInputs'],
            ),
            'buyer_name' => $data['buyerName'],
            'buyer_email' => $data['buyerEmail'],
            'buyer_phone' => $data['buyerPhone'],
            'customer_notes' => $data['customerNotes'],
            'customer_inputs_json' => json_encode($data['customerInputs'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'quantity' => $data['quantity'],
            'base_subtotal' => $data['promotion']['basePrice'],
            'subtotal' => $data['promotion']['sellingPrice'],
            'discount_amount' => $data['promotion']['discountAmount'],
            'member_tier_snapshot' => $data['promotion']['memberTier'] ?? null,
            'member_discount_percent_snapshot' => (float) ($data['promotion']['memberDiscountPercent'] ?? 0),
            'member_discount_amount' => (int) ($data['promotion']['memberDiscountAmount'] ?? 0),
            'voucher_discount_amount' => (int) ($data['promotion']['voucherDiscountAmount'] ?? 0),
            'voucher_code' => $data['promotion']['voucherCode'],
            'flash_sale_id' => $data['promotion']['flashSaleId'],
            'admin_fee' => $data['adminFee'] ?? 0,
            'total' => $data['promotion']['finalPrice'] + ($data['adminFee'] ?? 0),
            'payment_method' => $data['paymentMethod'],
            'payment_channel' => $data['paymentChannel'],
            'payment_gateway' => $data['paymentGateway'] ?? null,
            'payment_gateway_mode' => $data['paymentGatewayMode'] ?? null,
            'payment_gateway_environment' => $data['paymentGatewayEnvironment'] ?? null,
            'payment_status' => 'pending',
            'fulfillment_status' => 'waiting_payment',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($data['item']['fulfillmentType'] === 'automatic'
            && $data['item']['providerCode'] === 'digiflazz'
            && $data['quantity'] > 1) {
            for ($index = 1; $index <= $data['quantity']; $index++) {
                DB::table('order_fulfillment_units')->insert([
                    'order_id' => $data['id'],
                    'unit_index' => $index,
                    'provider_ref_id' => $data['referenceId'].'-Q'.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
                    'provider_status' => 'waiting',
                    'attempts' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /** @return array<int,array{id:string,label:string,placeholder:string,required:bool}> */
    private function parseInputFields(object $row): array
    {
        $decoded = json_decode((string) ($row->input_fields_json ?? ''), true);
        $fields = [];
        if (is_array($decoded)) {
            foreach (array_slice($decoded, 0, 12) as $index => $field) {
                if (!is_array($field)) {
                    continue;
                }
                $label = trim((string) ($field['label'] ?? ''));
                if ($label === '') {
                    continue;
                }
                $fields[] = [
                    'id' => trim((string) ($field['id'] ?? '')) ?: 'field-'.($index + 1),
                    'label' => $label,
                    'placeholder' => trim((string) ($field['placeholder'] ?? '')),
                    'required' => ($field['required'] ?? true) !== false,
                ];
            }
        }

        if ($fields !== []) {
            return $fields;
        }

        $fields[] = [
            'id' => 'account-id',
            'label' => (string) $row->input_label,
            'placeholder' => (string) $row->input_placeholder,
            'required' => true,
        ];
        if ((bool) $row->needs_server) {
            $fields[] = [
                'id' => 'server-zone',
                'label' => 'Server / Zone ID',
                'placeholder' => 'Contoh: 1234',
                'required' => true,
            ];
        }

        return $fields;
    }

    private function assertManualOpen(array $item): void
    {
        $open = trim((string) ($item['manualOpenTime'] ?? ''));
        $close = trim((string) ($item['manualCloseTime'] ?? ''));
        if ($open === '' || $close === '') {
            return;
        }

        $zone = $item['manualTimezone'] ?: 'Asia/Jakarta';
        $now = CarbonImmutable::now($zone);
        $minute = $now->hour * 60 + $now->minute;
        $openMinute = $this->clockMinute($open);
        $closeMinute = $this->clockMinute($close);

        if ($openMinute === null || $closeMinute === null) {
            throw new CheckoutValidationException('Jam operasional produk belum valid.');
        }

        $openNow = $openMinute === $closeMinute
            || ($openMinute < $closeMinute
                ? $minute >= $openMinute && $minute < $closeMinute
                : $minute >= $openMinute || $minute < $closeMinute);

        if (!$openNow) {
            throw new CheckoutValidationException('Layanan manual sedang di luar jam operasional.');
        }
    }

    private function cutoffActive(?string $start, ?string $end, string $zone): bool
    {
        $startMinute = $this->clockMinute((string) $start);
        $endMinute = $this->clockMinute((string) $end);
        if ($startMinute === null || $endMinute === null || $startMinute === $endMinute) {
            return false;
        }

        $now = CarbonImmutable::now($zone);
        $minute = $now->hour * 60 + $now->minute;

        return $startMinute < $endMinute
            ? $minute >= $startMinute && $minute < $endMinute
            : $minute >= $startMinute || $minute < $endMinute;
    }

    private function clockMinute(string $value): ?int
    {
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', trim($value), $match)) {
            return null;
        }
        $hour = (int) $match[1];
        $minute = (int) $match[2];

        return $hour <= 23 && $minute <= 59 ? $hour * 60 + $minute : null;
    }
}
