<?php

namespace App\Services;

use App\Jobs\SendTransactionalEmailJob;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

class TransactionalEmailService
{
    public function queue(string $email, string $subject, string $text, string $event = 'generic'): void
    {
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        // Enqueue is best-effort for customer events; direct job dispatch
        // (verification and admin test-email) must still propagate failures.
        try {
            SendTransactionalEmailJob::dispatch(strtolower($email), $subject, $text, $event)->afterCommit();
        } catch (\Throwable $exception) {
            Log::warning('Could not queue customer transactional email.', [
                'exception' => get_class($exception),
            ]);
        }
    }

    public function queueForOrder(int $orderId, string $subject, string $text, string $event = 'generic'): void
    {
        $order = DB::table('orders')->where('id', $orderId)->first(['user_id', 'guest_email']);
        if (! $order) {
            return;
        }

        $email = $order->user_id
            ? DB::table('users')->where('id', $order->user_id)->value('email')
            : $order->guest_email;

        if (is_string($email)) {
            $this->queue($email, $subject, $text, $event);
        }
    }

    /**
     * Momen 1 (D3): order dibuat. Dipanggil dari CheckoutService::create,
     * setelah order terbentuk. Isi ikut mockup "Mockup Email Notifikasi"
     * yang sudah disetujui user.
     */
    public function queueOrderCreated(int $orderId, ?string $accessCode = null): void
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
            'Bayar sekarang: '.$this->payUrl($details['invoice'], $details['expires_at']),
            'Lacak pesanan: '.$this->trackingUrl(),
            '',
        ];
        if ($accessCode !== null) {
            $lines = [...$lines,
                'Kode akses pesanan: '.$accessCode,
                'Simpan kode ini. Kode dipakai untuk membuka tiket bantuan dan memeriksa pesanan dari perangkat lain tanpa akun. Jangan bagikan ke siapa pun.',
                '',
            ];
        }
        $lines = [...$lines,
            'Sudah bayar? Abaikan email ini — kamu akan menerima email konfirmasi begitu pembayaranmu terverifikasi.',
            '',
            $this->csLine(),
        ];

        $this->queueForOrder($orderId, $subject, implode("\n", $lines), 'order_created');
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
            implode("\n", $lines),
            'order_paid'
        );
    }

    /**
     * Momen 3a (D3): pesanan selesai. Dipanggil dari ketiga jalur
     * FulfillmentService yang menutup order sebagai SUCCESS
     * (Digiflazz, stok kode, manual). Pemanggil WAJIB menyebut
     * jalurnya lewat $deliveryKind — wording email ditentukan
     * JALUR, bukan ada/tidaknya code di delivery_payload:
     *
     * - 'stock' (VOUCHER_STOCK): code adalah kode REDEEMABLE —
     *   customer sendiri yang menukarkannya. Ditampilkan sebagai
     *   "Kode voucher" + petunjuk tukar, BUKAN sebagai nomor
     *   referensi, dan tanpa klaim "sudah masuk akun".
     * - 'manual': code (bila ada) adalah teks bukti/hasil bebas
     *   yang diketik admin (maks 2000 karakter) — BUKAN kode
     *   redeemable. Ditampilkan sebagai "Bukti pengiriman",
     *   TANPA kata "tukarkan sendiri". Note admin SELALU
     *   ditampilkan bila ada, di kedua sub-cabang manual (dengan
     *   code maupun note-only). Menentukan manual dari
     *   ada/tidaknya code SALAH: manual note-only (code null)
     *   kehilangan note-nya, dan manual code-only tidak bisa
     *   dibedakan dari stok kode hanya dari payload.
     * - 'digiflazz': serial_number = nomor referensi diagnostik
     *   dari penyedia — "No. Referensi Pengiriman" + "periksa
     *   saldo di dalam game".
     */
    public function queueOrderCompleted(int $orderId, string $deliveryKind): void
    {
        $details = $this->orderDetails($orderId);
        if (! $details) {
            return;
        }

        $isStock = $deliveryKind === 'stock';
        $isManual = $deliveryKind === 'manual';

        $lines = [
            'Halo,',
            '',
            $isStock
                ? 'Pesananmu sudah selesai diproses. Kode produkmu ada di bawah — tukarkan sendiri:'
                : ($isManual
                    ? 'Pesananmu sudah selesai diproses dan sudah kami kirimkan secara manual:'
                    : 'Pesananmu sudah selesai diproses dan item sudah terkirim ke akun tujuan:'),
            '',
            'Nomor Invoice: '.$details['invoice'],
            'Produk: '.$details['product'].' — '.$details['package'],
        ];
        if ($details['destination'] !== null) {
            $lines[] = 'ID Tujuan: '.$details['destination'];
        }
        if ($isStock) {
            if ($details['delivery_code'] !== null) {
                $lines[] = 'Kode voucher: '.$details['delivery_code'];
            }
        } elseif ($isManual) {
            if ($details['delivery_code'] !== null) {
                $lines[] = 'Bukti pengiriman: '.$details['delivery_code'];
            }
            if ($details['delivery_note'] !== null) {
                $lines[] = 'Catatan: '.$details['delivery_note'];
            }
        } elseif ($details['delivery_reference'] !== null) {
            $lines[] = 'No. Referensi Pengiriman: '.$details['delivery_reference'];
        }
        $lines = [...$lines,
            'Total Transaksi: '.$this->rupiah($details['total']),
            '',
            $isStock
                ? 'Simpan kode ini baik-baik — jangan dibagikan ke orang lain. Kode ini yang ditukarkan untuk memakai produkmu; kode tidak terkirim otomatis ke akun game. Kalau kode tidak bisa ditukarkan, sampaikan nomor invoice di atas ke CS kami.'
                : ($isManual
                    ? 'Pengiriman sudah kami lakukan secara manual. Kalau ada yang belum sesuai, sampaikan nomor invoice di atas ke CS kami.'
                    : 'Silakan periksa saldo di dalam game. Kalau belum masuk setelah beberapa menit, sampaikan nomor invoice dan nomor referensi di atas ke CS kami.'),
            '',
            'Lihat detail pesanan: '.$this->trackingUrl(),
            '',
            $this->csLine(),
        ];

        $this->queueForOrder(
            $orderId,
            $isStock
                ? 'Pesanan selesai — kode '.$details['package'].' sudah siap ditukarkan'
                : ($isManual
                    ? 'Pesanan selesai — '.$details['package'].' sudah dikirim'
                    : 'Pesanan selesai — '.$details['package'].' sudah masuk ke akunmu'),
            implode("\n", $lines),
            'order_completed'
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
            $this->csLine(),
            (string) (app(StorefrontContentService::class)->settings()['store.business_hours'] ?? 'Setiap hari 08.00–23.00 WIB'),
        ];

        $this->queueForOrder(
            $orderId,
            'Pesanan '.$details['invoice'].' gagal diproses — ini langkah berikutnya',
            implode("\n", $lines),
            'order_failed'
        );
    }

    /**
     * @return array{invoice:string,product:string,package:string,destination:?string,method:string,subtotal:int,fee:int,total:int,expires_at:?string,delivery_code:?string,delivery_note:?string,delivery_reference:?string}|null
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
            'delivery_code' => $this->deliveryCode($delivery),
            'delivery_note' => $this->deliveryNote($delivery),
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
     * Kode dari delivery_payload. Artinya tergantung JALUR
     * fulfillment (lihat queueOrderCompleted): redeemable bila
     * stok kode, teks bukti bila penyelesaian manual. BUKAN nomor
     * referensi — customer tidak menukar nomor referensi.
     *
     * @param  array<string, mixed>  $delivery
     */
    private function deliveryCode(array $delivery): ?string
    {
        $code = $delivery['code'] ?? null;
        if (! is_string($code) || trim($code) === '') {
            return null;
        }

        return trim($code);
    }

    /**
     * Catatan admin saat penyelesaian manual (boleh kosong).
     *
     * @param  array<string, mixed>  $delivery
     */
    private function deliveryNote(array $delivery): ?string
    {
        $note = $delivery['note'] ?? null;
        if (! is_string($note) || trim($note) === '') {
            return null;
        }

        return trim($note);
    }

    /**
     * Nomor referensi diagnostik dari penyedia (Digiflazz `sn`).
     * Sengaja TIDAK fallback ke `code`: kode redeemable bukan
     * referensi dan tidak boleh dilabeli sebagai referensi.
     *
     * @param  array<string, mixed>  $delivery
     */
    private function deliveryReference(array $delivery): ?string
    {
        $reference = $delivery['serial_number'] ?? null;
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

    /**
     * Link bayar lintas sesi: signed URL (pola yang sama dengan link
     * verifikasi email di User::sendEmailVerificationNotification).
     * Tanpa signature, halaman pembayaran menolak pengunjung yang
     * sesinya beda dengan sesi checkout (guest: tanpa guest_order_id;
     * member: belum login) — persis keluhan Codex: link dari email
     * tidak jalan saat dibuka di browser/HP lain. Signature kedaluwarsa
     * mengikuti expires_at order; order yang sudah kedaluwarsa
     * mendapat jendela 30 menit (keputusan user: SEMUA link
     * bertanda tangan WAJIB berexpiry, tanpa fallback tanpa expiry).
     */
    private function payUrl(string $invoice, ?string $expiresAt): string
    {
        $expires = $expiresAt !== null ? Carbon::parse($expiresAt) : null;

        return URL::temporarySignedRoute(
            'payment.show',
            $expires !== null && $expires->isFuture() ? $expires : now()->addMinutes(30),
            ['invoice' => $invoice],
        );
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
        $supportEmail = (string) ((app(StorefrontContentService::class)->settings()['store.email'] ?? '') ?: 'support@lfamiliastore.my.id');

        return 'Butuh bantuan? Hubungi CS: '.$supportEmail.' / '.$this->contactUrl();
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
