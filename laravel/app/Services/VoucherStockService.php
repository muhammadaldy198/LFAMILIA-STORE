<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class VoucherStockService
{
    public function __construct(
        private readonly IntegrationConfigService $integrations,
        private readonly ResendService $resend,
        private readonly TransactionNotificationService $notifications,
    ) {
    }

    /** @param list<string> $values @return array{stockKey:string,received:int,imported:int,duplicates:int} */
    public function importCodes(string $stockKeyInput, array $values): array
    {
        $stockKey = $this->normalizeStockKey($stockKeyInput);
        $codes = $this->cleanCodes($values);
        $secret = $this->secret();
        $imported = 0;

        foreach ($codes as $code) {
            $encrypted = $this->encryptCode($code, $secret);
            $imported += DB::table('voucher_codes')->insertOrIgnore([
                'stock_key' => $stockKey,
                'code_ciphertext' => $encrypted['ciphertext'],
                'code_iv' => $encrypted['iv'],
                'code_tag' => $encrypted['tag'],
                'code_hash' => $encrypted['hash'],
                'status' => 'available',
                'created_at' => now(),
            ]);
        }

        return [
            'stockKey' => $stockKey,
            'received' => count($codes),
            'imported' => $imported,
            'duplicates' => count($codes) - $imported,
        ];
    }

    /** @return array<string,mixed> */
    public function dashboard(): array
    {
        $counts = DB::table('voucher_codes')
            ->selectRaw("stock_key,
                SUM(CASE WHEN status='available' THEN 1 ELSE 0 END) AS available,
                SUM(CASE WHEN status='reserved' THEN 1 ELSE 0 END) AS reserved,
                SUM(CASE WHEN status='delivered' THEN 1 ELSE 0 END) AS delivered,
                SUM(CASE WHEN status='void' THEN 1 ELSE 0 END) AS voided,
                COUNT(*) AS total")
            ->groupBy('stock_key')->orderBy('stock_key')->get();

        $packages = DB::table('product_packages as pp')
            ->join('products as p', 'p.id', '=', 'pp.product_id')
            ->where('pp.provider_code', 'voucher-stock')
            ->whereNotNull('pp.provider_sku')
            ->orderBy('p.name')->orderBy('pp.sort_order')
            ->get(['pp.provider_sku as stock_key','p.name as product_name','pp.label as package_label']);

        $stockMap = [];
        foreach ($counts as $row) {
            $stockMap[(string) $row->stock_key] = [
                'stock_key'=>(string) $row->stock_key,
                'available'=>(int) $row->available,
                'reserved'=>(int) $row->reserved,
                'delivered'=>(int) $row->delivered,
                'voided'=>(int) $row->voided,
                'total'=>(int) $row->total,
                'labels'=>[],
            ];
        }
        foreach ($packages as $row) {
            $key = (string) $row->stock_key;
            $stockMap[$key] ??= [
                'stock_key'=>$key,'available'=>0,'reserved'=>0,'delivered'=>0,'voided'=>0,'total'=>0,'labels'=>[],
            ];
            $stockMap[$key]['labels'][] = $row->product_name.' — '.$row->package_label;
        }
        ksort($stockMap);

        $deliveries = DB::table('voucher_codes as vc')
            ->join('orders as o', 'o.id', '=', 'vc.order_id')
            ->leftJoin('voucher_deliveries as vd', 'vd.voucher_code_id', '=', 'vc.id')
            ->whereNotNull('vc.order_id')
            ->groupBy(
                'vc.id','vc.stock_key','vc.status','vc.order_id','o.reference_id','o.product_name',
                'o.package_label','o.buyer_name','o.buyer_email','o.buyer_phone','vc.reserved_at','vc.delivered_at',
            )
            ->orderByRaw('COALESCE(vc.delivered_at, vc.reserved_at) DESC')
            ->limit(100)
            ->get([
                'vc.id as code_id','vc.stock_key','vc.status as code_status','vc.order_id',
                'o.reference_id','o.product_name','o.package_label','o.buyer_name','o.buyer_email','o.buyer_phone',
                DB::raw("MAX(CASE WHEN vd.channel='email' THEN vd.status END) AS email_status"),
                DB::raw("MAX(CASE WHEN vd.channel='whatsapp' THEN vd.status END) AS whatsapp_status"),
                'vc.reserved_at','vc.delivered_at',
            ]);

        $resend = $this->integrations->resendRuntime();
        return [
            'stocks'=>array_values($stockMap),
            'deliveries'=>$deliveries,
            'config'=>[
                'encryptionReady'=>$this->integrations->voucherEncryptionKey() !== null,
                'emailReady'=>$this->resend->configured(),
                'deliveryChannel'=>$this->integrations->voucherDeliveryChannel(),
            ],
        ];
    }

    /** @return array{code:string,stockKey:string,codeId:int} */
    public function revealByOrder(string $orderId): array
    {
        $row = DB::table('voucher_codes')->where('order_id', $orderId)->first();
        if (!$row) throw new RuntimeException('Kode untuk pesanan ini belum direservasi.');

        return [
            'code'=>$this->decryptCode($row, $this->secret()),
            'stockKey'=>(string) $row->stock_key,
            'codeId'=>(int) $row->id,
        ];
    }

    /** @return list<array<string,mixed>> */
    public function customerCodes(string $customerId): array
    {
        $rows = DB::table('voucher_codes as vc')
            ->join('orders as o', 'o.id', '=', 'vc.order_id')
            ->where('o.customer_id', $customerId)
            ->where('vc.status', 'delivered')
            ->where('o.payment_status', 'paid')
            ->orderByDesc('vc.delivered_at')->limit(50)
            ->get([
                'vc.id','vc.stock_key','vc.code_ciphertext','vc.code_iv','vc.code_tag','vc.code_hash',
                'vc.status','vc.order_id','vc.reserved_at','vc.delivered_at','vc.created_at',
                'o.reference_id','o.product_name','o.package_label',
            ]);
        if ($rows->isEmpty()) return [];

        $secret = $this->secret();
        return $rows->map(fn ($row) => [
            'id'=>(int) $row->id,
            'referenceId'=>(string) $row->reference_id,
            'productName'=>(string) $row->product_name,
            'packageLabel'=>(string) $row->package_label,
            'code'=>$this->decryptCode($row, $secret),
            'deliveredAt'=>$row->delivered_at,
        ])->all();
    }

    public function fulfillOrder(string $orderId): void
    {
        $result = null;
        try {
            $result = DB::transaction(function () use ($orderId): ?array {
                $order = DB::table('orders')->where('id', $orderId)->lockForUpdate()->first();
                if (!$order
                    || $order->payment_status !== 'paid'
                    || $order->fulfillment_type !== 'automatic'
                    || strtolower(trim((string) $order->provider_code)) !== 'voucher-stock') {
                    return null;
                }
                if ($order->fulfillment_status === 'success') {
                    $existing = DB::table('voucher_codes')->where('order_id', $orderId)->first();
                    return $existing ? ['order'=>$order,'voucher'=>$existing] : null;
                }

                $stockKey = $this->normalizeStockKey((string) $order->provider_sku);
                $voucher = DB::table('voucher_codes')->where('order_id', $orderId)->lockForUpdate()->first();
                if (!$voucher) {
                    $voucher = DB::table('voucher_codes')
                        ->where('stock_key', $stockKey)
                        ->where('status', 'available')
                        ->orderBy('id')->lockForUpdate()->first();
                    if (!$voucher) throw new RuntimeException('Stok kode '.$stockKey.' habis.');

                    $won = DB::table('voucher_codes')
                        ->where('id', $voucher->id)->where('status', 'available')
                        ->update([
                            'status'=>'reserved','order_id'=>$orderId,'reserved_at'=>now(),
                        ]);
                    if ($won !== 1) throw new RuntimeException('Reservasi stok kode gagal; coba ulang.');
                    $voucher = DB::table('voucher_codes')->where('id', $voucher->id)->first();
                }

                $code = $this->decryptCode($voucher, $this->secret());
                DB::table('voucher_codes')->where('id', $voucher->id)->where('order_id', $orderId)->update([
                    'status'=>'delivered',
                    'delivered_at'=>DB::raw('COALESCE(delivered_at, CURRENT_TIMESTAMP)'),
                ]);
                DB::table('orders')->where('id', $orderId)->where('payment_status', 'paid')->update([
                    'provider_ref_id'=>'stock-'.$voucher->id,
                    'provider_status'=>'success',
                    'provider_message'=>'Kode tersedia di akun pelanggan.',
                    'provider_serial_number'=>'STOCK-'.$voucher->id,
                    'fulfillment_status'=>'success',
                    'updated_at'=>now(),
                ]);
                DB::table('order_events')->insertOrIgnore([
                    'order_id'=>$orderId,
                    'source'=>'admin',
                    'event_id'=>'voucher-stock-delivered-'.$voucher->id,
                    'status'=>'success',
                    'payload_json'=>json_encode(['voucherCodeId'=>(int)$voucher->id,'stockKey'=>$stockKey]),
                    'created_at'=>now(),
                ]);

                return ['order'=>$order,'voucher'=>$voucher,'code'=>$code];
            }, 3);
        } catch (Throwable $error) {
            DB::table('orders')
                ->where('id', $orderId)
                ->where('payment_status', 'paid')
                ->whereNotIn('fulfillment_status', ['success','failed','cancelled'])
                ->update([
                    'fulfillment_status'=>'needs_review',
                    'provider_status'=>'retryable_error',
                    'provider_message'=>mb_substr($error->getMessage() ?: 'Stok voucher gagal diproses.', 0, 500),
                    'updated_at'=>now(),
                ]);
            return;
        }

        if (!$result) return;
        $this->notifications->notifyOrderSuccessById($orderId);
        if (($result['code'] ?? null) && $this->integrations->voucherDeliveryChannel() === 'email') {
            $this->emailCode($result['order'], $result['voucher'], (string) $result['code']);
        }
    }

    private function emailCode(object $order, object $voucher, string $code): void
    {
        $existing = DB::table('voucher_deliveries')
            ->where('order_id', $order->id)->where('channel', 'email')->first();
        if ($existing?->status === 'sent') return;

        DB::table('voucher_deliveries')->updateOrInsert(
            ['order_id'=>$order->id,'channel'=>'email'],
            [
                'voucher_code_id'=>$voucher->id,'status'=>'sending',
                'attempts'=>DB::raw('COALESCE(attempts, 0) + 1'),'last_attempt_at'=>now(),'updated_at'=>now(),
                'created_at'=>$existing?->created_at ?? now(),
            ],
        );

        try {
            $this->resend->send(
                (string) $order->buyer_email,
                'Kode '.$order->product_name.' — '.$order->reference_id,
                '<div style="font-family:Arial,sans-serif;max-width:600px;margin:auto;color:#151515">'
                    .'<h1>LFAMILIA STORE</h1><p>Halo '.$this->escape((string)$order->buyer_name).',</p>'
                    .'<p>Pembayaran pesanan <strong>'.$this->escape((string)$order->reference_id).'</strong> sudah berhasil.</p>'
                    .'<p>'.$this->escape((string)$order->product_name).' — '.$this->escape((string)$order->package_label).'</p>'
                    .'<div style="padding:18px;border-radius:12px;background:#f1f7df;font-size:20px;font-weight:700;word-break:break-all">'
                    .$this->escape($code).'</div><p>Simpan kode ini dan jangan bagikan kepada orang lain.</p></div>',
                'lfamilia-voucher-'.$order->id.'-email',
            );
            DB::table('voucher_deliveries')->where('order_id',$order->id)->where('channel','email')->update([
                'status'=>'sent','provider_message'=>'Kode berhasil dikirim lewat email.','updated_at'=>now(),
            ]);
        } catch (Throwable $error) {
            DB::table('voucher_deliveries')->where('order_id',$order->id)->where('channel','email')->update([
                'status'=>'failed','provider_message'=>mb_substr($error->getMessage() ?: 'Pengiriman email gagal.',0,500),'updated_at'=>now(),
            ]);
        }
    }

    private function secret(): string
    {
        $secret = $this->integrations->voucherEncryptionKey();
        if (!$secret) throw new RuntimeException('Kunci enkripsi stok voucher belum dikonfigurasi.');
        return $secret;
    }

    /** @return array{ciphertext:string,iv:string,tag:string,hash:string} */
    private function encryptCode(string $code, string $secret): array
    {
        $iv = random_bytes(12);
        $key = hash('sha256', $secret, true);
        $tag = '';
        $ciphertext = openssl_encrypt($code, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false || strlen($tag) !== 16) throw new RuntimeException('Kode voucher gagal dienkripsi.');

        return [
            'ciphertext'=>base64_encode($ciphertext),
            'iv'=>base64_encode($iv),
            'tag'=>base64_encode($tag),
            'hash'=>hash_hmac('sha256', $code, $secret),
        ];
    }

    private function decryptCode(object $row, string $secret): string
    {
        $iv = base64_decode((string) $row->code_iv, true);
        $tag = base64_decode((string) $row->code_tag, true);
        $ciphertext = base64_decode((string) $row->code_ciphertext, true);
        if ($iv === false || strlen($iv) !== 12 || $tag === false || strlen($tag) !== 16 || $ciphertext === false) {
            throw new RuntimeException('Format kode voucher terenkripsi tidak valid.');
        }
        $plain = openssl_decrypt($ciphertext, 'aes-256-gcm', hash('sha256',$secret,true), OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) throw new RuntimeException('Kode voucher tidak dapat dibuka dengan kunci yang aktif.');
        return $plain;
    }

    private function normalizeStockKey(string $value): string
    {
        $key = strtolower(trim($value));
        if (!preg_match('/^[a-z0-9][a-z0-9._:-]{1,99}$/', $key)) {
            throw new RuntimeException('Kunci stok voucher tidak valid.');
        }
        return $key;
    }

    /** @param list<string> $values @return list<string> */
    private function cleanCodes(array $values): array
    {
        $unique = [];
        foreach ($values as $raw) {
            $code = trim((string) $raw);
            if ($code === '') continue;
            if (strlen($code) < 3 || strlen($code) > 500 || str_contains($code, "\0")) {
                throw new RuntimeException('Setiap kode harus berisi 3–500 karakter yang valid.');
            }
            $unique[$code] = true;
        }
        $codes = array_keys($unique);
        if ($codes === []) throw new RuntimeException('Masukkan setidaknya satu kode voucher.');
        if (count($codes) > 1000) throw new RuntimeException('Maksimal 1.000 kode untuk sekali impor.');
        return $codes;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
