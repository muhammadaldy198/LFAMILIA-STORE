<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Throwable;

class TransactionNotificationService
{
    public function __construct(private readonly ResendService $resend)
    {
    }

    public function notifyOrderSuccessById(string $orderId): void
    {
        if (!$this->emailConfigured()) {
            return;
        }

        $order = DB::table('orders')
            ->where('id', $orderId)
            ->first([
                'id', 'buyer_name', 'buyer_email', 'product_name', 'package_label',
                'quantity', 'total', 'reference_id', 'fulfillment_status',
            ]);

        if (!$order || $order->fulfillment_status !== 'success' || trim((string) $order->buyer_email) === '') {
            return;
        }

        $eventId = 'customer-email-order-success-'.$order->reference_id;
        $claimed = DB::table('order_events')->insertOrIgnore([
            'order_id' => $order->id,
            'source' => 'admin',
            'event_id' => $eventId,
            'status' => 'notified',
            'payload_json' => json_encode(['channel' => 'email', 'type' => 'order_success']),
            'created_at' => now(),
        ]);

        if ($claimed === 0) {
            return;
        }

        $quantity = max(1, (int) $order->quantity);
        $detail = $this->escape((string) $order->product_name)
            .' — '.$this->escape((string) $order->package_label)
            .($quantity > 1 ? ' × '.$quantity : '')
            .'. Produk berhasil dikirim.';

        $html = '<div style="font-family:Arial,sans-serif;max-width:600px;margin:auto;color:#151515">'
            .'<h1>LFAMILIA STORE</h1>'
            .'<p>Halo '.$this->escape((string) $order->buyer_name).',</p>'
            .'<p><strong>Pesanan selesai.</strong></p>'
            .'<p>'.$detail.'</p>'
            .'<p>Total: <strong>'.$this->rupiah((int) $order->total).'</strong></p>'
            .'<p>Referensi: <strong>'.$this->escape((string) $order->reference_id).'</strong></p>'
            .'<p>Terima kasih telah menggunakan LFAMILIA STORE.</p></div>';

        try {
            $this->resend->send(
                (string) $order->buyer_email,
                'Pesanan selesai — '.$order->reference_id,
                $html,
                'lfamilia-success-order-'.$order->reference_id,
            );
        } catch (Throwable) {
            DB::table('order_events')
                ->where('source', 'admin')
                ->where('event_id', $eventId)
                ->delete();
        }
    }

    public function notifyOrderSuccessByProviderRef(string $providerCode, string $providerRefId): void
    {
        $orderId = DB::table('orders as o')
            ->leftJoin('order_fulfillment_units as u', 'u.order_id', '=', 'o.id')
            ->where('o.provider_code', $providerCode)
            ->where('o.fulfillment_status', 'success')
            ->where(function ($query) use ($providerRefId) {
                $query->where('o.provider_ref_id', $providerRefId)
                    ->orWhere('o.reference_id', $providerRefId)
                    ->orWhere('u.provider_ref_id', $providerRefId);
            })
            ->value('o.id');

        if ($orderId) {
            $this->notifyOrderSuccessById((string) $orderId);
        }
    }

    public function notifyWalletTopupSuccessById(string $topupId): void
    {
        if (!$this->emailConfigured()) {
            return;
        }

        $row = DB::table('wallet_topups as t')
            ->join('customer_users as u', 'u.id', '=', 't.customer_id')
            ->where('t.id', $topupId)
            ->first([
                't.id', 't.amount', 't.reference_id', 't.status',
                'u.name', 'u.email', 'u.balance',
            ]);

        if (!$row || $row->status !== 'approved' || trim((string) $row->email) === '') {
            return;
        }

        $reference = $row->reference_id ?: 'TOPUP-'.strtoupper(substr((string) $row->id, 0, 8));
        $html = '<div style="font-family:Arial,sans-serif;max-width:600px;margin:auto;color:#151515">'
            .'<h1>LFAMILIA STORE</h1>'
            .'<p>Halo '.$this->escape((string) $row->name).',</p>'
            .'<p><strong>Top up saldo berhasil.</strong></p>'
            .'<p>Saldo LFAMILIA sudah bertambah. Saldo sekarang '.$this->rupiah((int) $row->balance).'.</p>'
            .'<p>Nominal: <strong>'.$this->rupiah((int) $row->amount).'</strong></p>'
            .'<p>Referensi: <strong>'.$this->escape((string) $reference).'</strong></p></div>';

        try {
            $this->resend->send(
                (string) $row->email,
                'Top up saldo berhasil — '.$reference,
                $html,
                'lfamilia-success-wallet_topup-'.$reference,
            );
        } catch (Throwable) {
            // Transaction settlement must never fail because email delivery is unavailable.
        }
    }

    private function emailConfigured(): bool
    {
        return trim((string) config('lfamilia.integrations.resend.api_key')) !== ''
            && trim((string) config('lfamilia.integrations.resend.from')) !== ''
            && trim((string) config('lfamilia.integrations.resend.api_url')) !== '';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function rupiah(int $value): string
    {
        return 'Rp'.number_format(max(0, $value), 0, ',', '.');
    }
}
