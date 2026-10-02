<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AdminOrderPresentation
{
    private array $fieldLabels = [];

    public const STATUSES = [
        'PENDING_PAYMENT' => 'Menunggu pembayaran', 'PAID' => 'Pembayaran diterima',
        'PROCESSING' => 'Sedang diproses', 'SUCCESS' => 'Berhasil', 'FAILED' => 'Gagal',
        'EXPIRED' => 'Kedaluwarsa', 'CANCELLED' => 'Dibatalkan', 'REFUND' => 'Dana dikembalikan',
        'CREATING' => 'Menyiapkan pembayaran', 'PENDING' => 'Menunggu kepastian',
        'REFUNDED' => 'Dana dikembalikan', 'CREATED' => 'Menunggu pengiriman',
        'SENDING' => 'Sedang dikirim', 'UNKNOWN' => 'Perlu pemeriksaan',
        'FAILED_CONFIRMED' => 'Gagal dikonfirmasi penyedia', 'BLOCKED' => 'Pengiriman tertahan',
        'MANUAL_PENDING' => 'Menunggu penanganan manual', 'MANUAL_FAILED' => 'Penanganan manual gagal',
    ];

    public const EVENTS = [
        'ORDER_CREATED' => 'Pesanan dibuat', 'PAYMENT_CREATED' => 'Pembayaran disiapkan',
        'PAYMENT_PAID' => 'Pembayaran diterima', 'ORDER_PAID' => 'Pembayaran diterima',
        'PAYMENT_FAILED' => 'Pembayaran gagal', 'PAYMENT_EXPIRED' => 'Pembayaran kedaluwarsa',
        'PAYMENT_CANCELLED' => 'Pembayaran dibatalkan', 'PAYMENT_REFUNDED' => 'Dana dikembalikan',
        'FULFILLMENT_QUEUED' => 'Pesanan masuk antrean pengiriman',
        'MANUAL_FULFILLMENT_QUEUED' => 'Pesanan masuk antrean penanganan manual',
        'FULFILLMENT_SUCCEEDED' => 'Pesanan berhasil diselesaikan',
        'MANUAL_FULFILLMENT_SUCCEEDED' => 'Pesanan manual berhasil diselesaikan',
        'MANUAL_FULFILLMENT_FAILED' => 'Pesanan manual dinyatakan gagal',
        'FULFILLMENT_FAILED_CONFIRMED' => 'Penyedia mengonfirmasi kegagalan',
        'FULFILLMENT_PENDING' => 'Menunggu hasil dari penyedia',
        'FULFILLMENT_UNKNOWN' => 'Hasil pengiriman perlu diperiksa',
        'FULFILLMENT_BLOCKED' => 'Pengiriman tertahan',
        'FULFILLMENT_RETRY_QUEUED' => 'Pengiriman ulang masuk antrean',
        'FULFILLMENT_FAILOVER_QUEUED' => 'Dialihkan ke penyedia berikutnya',
        'FULFILLMENT_EXHAUSTED' => 'Tidak ada penyedia lain yang tersedia',
        'FULFILLMENT_CONFLICT_IGNORED' => 'Hasil berbeda diterima; status akhir dipertahankan',
        'FULFILLMENT_PRICE_GUARD_BREACH' => 'Harga penyedia melebihi batas',
        'ADMIN_MANUAL_ORDER_CREATED' => 'Pesanan manual dicatat oleh admin',
    ];

    public function status(?string $status): string
    {
        return self::STATUSES[$status ?? ''] ?? 'Status belum tersedia';
    }

    public function date(?string $date): ?string
    {
        return $date ? Carbon::parse($date, 'UTC')->toIso8601String() : null;
    }

    public function json(mixed $value): array
    {
        return is_array($value) ? $value : (json_decode((string) $value, true) ?: []);
    }

    public function row(object $row): array
    {
        $snapshot = $this->json($row->snapshot);
        $input = $this->json($row->customer_input);
        $fields = $this->fieldLabels[$row->product_id] ??= DB::table('product_input_fields')->where('product_id', $row->product_id)->pluck('label', 'field_key')->all();
        $known = ['destination' => 'Tujuan', 'user_id' => 'ID pengguna', 'userId' => 'ID pengguna',
            'server_id' => 'ID server', 'serverId' => 'ID server', 'zone_id' => 'ID zona',
            'phone' => 'Nomor telepon', 'customer_no' => 'Nomor pelanggan', 'nickname' => 'Nama akun',
            'account_id' => 'ID akun', 'meter_number' => 'Nomor meter', 'email' => 'Email'];
        $destinations = [];
        foreach ($input as $key => $value) {
            if (is_scalar($value) && (string) $value !== '') {
                $destinations[] = ['label' => $fields[$key] ?? $known[$key] ?? 'Data tujuan', 'value' => (string) $value];
            }
        }
        $provider = data_get($snapshot, 'provider.code') ?: ($row->provider_code ?? null);
        $channel = data_get($snapshot, 'payment.channel_name') ?: ($row->channel_name ?? null);
        $channelCode = data_get($snapshot, 'payment.channel_code');
        return [
            'id' => (int) $row->id, 'order_number' => $row->order_number,
            'status' => $row->status, 'status_label' => $this->status($row->status),
            'total_idr' => (int) $row->total_idr, 'created_at' => $this->date($row->created_at),
            'paid_at' => $this->date($row->paid_at),
            'product_name' => data_get($snapshot, 'product.name') ?: ($snapshot['product_name'] ?? $row->product_name),
            'package_name' => data_get($snapshot, 'package.name') ?: ($snapshot['package_name'] ?? $row->package_name),
            'buyer_name' => $snapshot['buyer_name'] ?? ($row->buyer_name ?: 'Pelanggan tamu'),
            'buyer_email' => $row->buyer_email ?: $row->guest_email,
            'buyer_phone' => $row->buyer_phone ?: $row->guest_phone,
            'customer_id' => $row->user_id, 'destinations' => $destinations,
            'nickname' => data_get($snapshot, 'nickname.nickname') ?: data_get($snapshot, 'nickname.name'),
            'provider' => $provider === 'MANUAL' ? 'Penanganan manual' : ($provider ? ucfirst(strtolower($provider)) : 'Belum ditentukan'),
            'payment_method' => $channel ?: match ($channelCode) {
                'WALLET' => 'Saldo akun', 'ADMIN_MANUAL' => 'Pembayaran dicatat admin',
                default => 'Belum dipilih',
            },
            'needs_attention' => (bool) ($row->needs_attention ?? false),
            'delivery' => $this->json($row->delivery_payload ?? null),
            'manual' => data_get($snapshot, 'product.fulfillment_mode') === 'MANUAL',
        ];
    }
}
