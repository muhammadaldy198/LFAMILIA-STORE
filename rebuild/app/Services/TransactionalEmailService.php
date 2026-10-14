<?php

namespace App\Services;

use App\Jobs\SendTransactionalEmailJob;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class TransactionalEmailService
{
    public function queue(string $email, string $subject, string $text): void
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            SendTransactionalEmailJob::dispatch(strtolower($email), $subject, $text)->afterCommit();
        }
    }

    public function queueForOrder(int $orderId, string $subject, string $text): void
    {
        $order = DB::table('orders')->where('id', $orderId)->first(['user_id', 'guest_email']);
        if (! $order) {
            return;
        }

        $email = $order->user_id
            ? DB::table('users')->where('id', $order->user_id)->value('email')
            : $order->guest_email;

        if (is_string($email)) {
            $this->queue($email, $subject, $text);
        }
    }

    /**
     * Momen 1 (D3): order dibuat. Dipanggil dari CheckoutService::create,
     * setelah order terbentuk. Isi ikut mockup "Mockup Email Notifikasi"
     * yang sudah disetujui user.
     */
    public function queueOrderCreated(int $orderId): void
    {
        $details = $this->orderDetails($orderId);
        if (! $details) {
            return;
        }

        $deadline = $this->deadlineWib($details['expires_at']);
        $subject = 'Pesanan '.$details['invoice'].' dibuat';
        if ($deadline) {
            $subject .= ' — selesaikan pembayaran sebelum '.Carbon::parse($details['expires_at'])->timezone('Asia/Jakarta')->format('H.i').' WIB';
        }

        $lines = [
            'Halo,',
            '',
            'Terima kasih sudah order di LFAMILIA-STORE. Pesananmu sudah kami catat dengan rincian berikut:',
            '',
            'Nomor Invoice: '.$details['invoice'],
            'Produk: '.$details['product'].' — '.$details['package'],
        ];
        if ($details['destination'] !== null) {
            $lines[] = 'ID Tujuan: '.$details['destination'];
        }
        $lines = [...$lines,
            'Metode Pembayaran: '.$details['method'],
            'Subtotal: '.$this->rupiah($details['subtotal']),
            'Biaya Pembayaran: '.$this->rupiah($details['fee']),
            'Total Bayar: '.$this->rupiah($details['total']),
            '',
        ];
        if ($deadline) {
            $lines = [...$lines,
                'Selesaikan pembayaran sebelum '.$deadline.'. Lewat dari batas itu, pesanan otomatis dibatalkan dan kamu perlu membuat order baru.',
                '',
            ];
        }
        $lines = [...$lines,
            'Bayar sekarang: '.$this->payUrl($details['invoice']),
            'Lacak pesanan: '.$this->trackingUrl(),
            '',
            'Sudah bayar? Abaikan email ini — kamu akan menerima email konfirmasi begitu pembayaranmu terverifikasi.',
            '',
            $this->csLine(),
        ];

        $this->queueForOrder($orderId, $subject, implode("\n", $lines));
    }

    /**
     * Momen 2 (D3): pembayaran berhasil. Dipanggil dari
     * PaymentStateService::markOrderPaid. Melengkapi email lama yang
     * isinya hanya nomor order.
     */
    public function queuePaymentConfirmed(int $orderId): void
    {
        $details = $this->orderDetails($orderId);
        if (! $details) {
            return;
        }

        $lines = [
            'Halo,',
            '',
            'Pembayaran untuk pesananmu sudah kami terima. Berikut buktinya:',
            '',
            'Nomor Invoice: '.$details['invoice'],
            'Produk: '.$details['product'].' — '.$details['package'],
        ];
        if ($details['destination'] !== null) {
            $lines[] = 'ID Tujuan: '.$details['destination'];
        }
        $lines = [...$lines,
            'Metode Pembayaran: '.$details['method'],
            'Total Terbayar: '.$this->rupiah($details['total']),
            '',
            'Pesananmu sekarang sedang kami teruskan ke penyedia untuk dikirim ke akunmu. Kamu tidak perlu melakukan apa-apa lagi — begitu pengiriman selesai, kamu menerima email penutup dari kami.',
            '',
            'Lacak pesanan: '.$this->trackingUrl(),
            '',
            $this->csLine(),
        ];

        $this->queueForOrder(
            $orderId,
            'Pembayaran diterima — pesanan '.$details['invoice'].' sedang diproses',
            implode("\n", $lines)
        );
    }

    /**
     * Momen 3a (D3): pesanan selesai. Dipanggil dari ketiga jalur
     * FulfillmentService yang menutup order sebagai SUCCESS
     * (Digiflazz, stok kode, manual).
     */
    public function queueOrderCompleted(int $orderId): void
    {
        $details = $this->orderDetails($orderId);
        if (! $details) {
            return;
        }

        $lines = [
            'Halo,',
            '',
            'Pesananmu sudah selesai diproses dan item sudah terkirim ke akun tujuan:',
            '',
            'Nomor Invoice: '.$details['invoice'],
            'Produk: '.$details['product'].' — '.$details['package'],
        ];
        if ($details['destination'] !== null) {
            $lines[] = 'ID Tujuan: '.$details['destination'];
        }
        if ($details['delivery_reference'] !== null) {
            $lines[] = 'No. Referensi Pengiriman: '.$details['delivery_reference'];
        }
        $lines = [...$lines,
            'Total Transaksi: '.$this->rupiah($details['total']),
            '',
            'Silakan periksa saldo di dalam game. Kalau belum masuk setelah beberapa menit, sampaikan nomor invoice dan nomor referensi di atas ke CS kami.',
            '',
            'Lihat detail pesanan: '.$this->trackingUrl(),
            '',
            $this->csLine(),
        ];

        $this->queueForOrder(
            $orderId,
            'Pesanan selesai — '.$details['package'].' sudah masuk ke akunmu',
            implode("\n", $lines)
        );
    }

    /**
     * Momen 3b (D3): pesanan gagal. Dipanggil dari FulfillmentService
     * saat order ditutup sebagai gagal (fulfillment habis / manual gagal).
     * Mengarahkan ke CS dengan nomor order + bukti bayar. Tanpa janji
     * refund — topik refund ditutup, tindak lanjut lewat CS.
     */
    public function queueOrderFailed(int $orderId): void
    {
        $details = $this->orderDetails($orderId);
        if (! $details) {
            return;
        }

        $lines = [
            'Halo,',
            '',
            'Maaf, pengiriman untuk pesanan berikut tidak dapat diselesaikan oleh penyedia:',
            '',
            'Nomor Invoice: '.$details['invoice'],
            'Produk: '.$details['product'].' — '.$details['package'],
        ];
        if ($details['destination'] !== null) {
            $lines[] = 'ID Tujuan: '.$details['destination'];
        }
        $lines = [...$lines,
            'Total Transaksi: '.$this->rupiah($details['total']),
            '',
            'Pembayaranmu sudah kami terima sebelumnya, jadi jangan membuat order baru dulu. Untuk tindak lanjut soal pembayaranmu, hubungi CS kami dan sebutkan nomor invoice '.$details['invoice'].' — tim kami akan membantu menyelesaikannya. Sertakan bukti bayar saat menghubungi CS.',
            '',
            'Hubungi CS: support@lfamiliastore.my.id / '.$this->contactUrl(),
            'CS tersedia setiap hari pukul 08.00–23.00 WIB.',
        ];

        $this->queueForOrder(
            $orderId,
            'Pesanan '.$details['invoice'].' gagal diproses — ini langkah berikutnya',
            implode("\n", $lines)
        );
    }

    /**
     * @return array{invoice:string,product:string,package:string,destination:?string,method:string,subtotal:int,fee:int,total:int,expires_at:?string,delivery_reference:?string}|null
     */
    private function orderDetails(int $orderId): ?array
    {
        $order = DB::table('orders')
            ->join('products', 'products.id', '=', 'orders.product_id')
            ->join('product_packages', 'product_packages.id', '=', 'orders.product_package_id')
            ->leftJoin('payment_channels', 'payment_channels.id', '=', 'orders.payment_channel_id')
            ->where('orders.id', $orderId)
            ->select(
                'orders.order_number',
                'orders.customer_input',
                'orders.snapshot',
                'orders.delivery_payload',
                'orders.cost_idr',
                'orders.margin_idr',
                'orders.discount_idr',
                'orders.fee_idr',
                'orders.total_idr',
                'orders.expires_at',
                'products.name as product_name',
                'product_packages.name as package_name',
                'payment_channels.name as payment_channel_name',
            )->first();

        if (! $order) {
            return null;
        }

        $snapshot = $this->json($order->snapshot);
        $customerInput = $this->json($order->customer_input);
        $delivery = $this->json($order->delivery_payload);

        $method = data_get($snapshot, 'payment.channel_name')
            ?: ($order->payment_channel_name ?: 'Pembayaran');

        return [
            'invoice' => (string) $order->order_number,
            'product' => (string) ($snapshot['product']['name'] ?? $order->product_name),
            'package' => (string) ($snapshot['package']['name'] ?? $order->package_name),
            'destination' => $this->destination($customerInput),
            'method' => (string) $method,
            'subtotal' => max(0, (int) $order->cost_idr + (int) $order->margin_idr - (int) $order->discount_idr),
            'fee' => (int) $order->fee_idr,
            'total' => (int) $order->total_idr,
            'expires_at' => is_string($order->expires_at) ? $order->expires_at : null,
            'delivery_reference' => $this->deliveryReference($delivery),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function destination(array $input): ?string
    {
        $parts = [];
        foreach (array_values($input) as $value) {
            $text = trim((string) $value);
            if ($text !== '') {
                $parts[] = $text;
            }
        }

        return $parts === [] ? null : implode(' / ', $parts);
    }

    /**
     * @param  array<string, mixed>  $delivery
     */
    private function deliveryReference(array $delivery): ?string
    {
        $reference = $delivery['serial_number'] ?? $delivery['code'] ?? null;
        if (! is_string($reference) || trim($reference) === '') {
            return null;
        }

        return trim($reference);
    }

    private function deadlineWib(?string $expiresAt): ?string
    {
        if ($expiresAt === null || trim($expiresAt) === '') {
            return null;
        }

        $days = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
        $months = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
        $date = Carbon::parse($expiresAt)->timezone('Asia/Jakarta');

        return $days[$date->dayOfWeek].', '.$date->day.' '.$months[$date->month - 1].' '.$date->year
            .' pukul '.$date->format('H.i').' WIB';
    }

    private function payUrl(string $invoice): string
    {
        return route('payment.show', ['invoice' => $invoice]);
    }

    private function trackingUrl(): string
    {
        return route('guest.orders.lookup');
    }

    private function contactUrl(): string
    {
        return route('content.contact');
    }

    private function csLine(): string
    {
        return 'Butuh bantuan? Hubungi CS: support@lfamiliastore.my.id / '.$this->contactUrl();
    }

    private function rupiah(int $amount): string
    {
        return 'Rp '.number_format($amount, 0, ',', '.');
    }

    /**
     * @return array<string, mixed>
     */
    private function json(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
