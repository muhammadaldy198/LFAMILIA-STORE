<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CustomerEmailTemplateRenderer
{
    /**
     * One master view for all customer-facing messages. Copy stays in code in PR 1;
     * a later change may replace the registry's text without changing the layout.
     */
    private const REGISTRY = [
        'verification' => ['label' => 'Verifikasi email', 'value' => 'VERIFIKASI', 'cta' => 'Verifikasi Email'],
        'password_reset' => ['label' => 'Keamanan akun', 'value' => 'ATUR ULANG', 'cta' => 'Atur Ulang Kata Sandi'],
        'password_changed' => ['label' => 'Keamanan akun', 'value' => 'DIPERBARUI', 'cta' => 'Masuk ke Akun'],
        'password_reset_completed' => ['label' => 'Keamanan akun', 'value' => 'BERHASIL', 'cta' => 'Masuk ke Akun'],
        'order_created' => ['label' => 'Total yang harus dibayar', 'value' => '', 'cta' => 'Bayar Sekarang'],
        'order_paid' => ['label' => 'Total yang harus dibayar', 'value' => 'LUNAS', 'cta' => 'Cek Rincian Pesanan'],
        'order_completed' => ['label' => 'Status pesanan', 'value' => 'TERKIRIM', 'cta' => 'Cek Rincian Pesanan'],
        'order_failed' => ['label' => 'Status pesanan', 'value' => 'GAGAL', 'cta' => 'Hubungi Kami'],
        'ticket_created' => ['label' => 'Tiket bantuan', 'value' => 'DITERIMA', 'cta' => 'Lihat Tiket Bantuan'],
        'ticket_reply' => ['label' => 'Tiket bantuan', 'value' => 'ADA BALASAN', 'cta' => 'Lihat Balasan Tiket'],
    ];

    public static function templateKeys(): array
    {
        return array_keys(self::REGISTRY);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{html:string,text:string}
     */
    public function render(
        string $event,
        string $subject,
        string $body,
        string $recipient,
        array $context = [],
        ?string $originalBody = null,
        ?array $previewDraft = null
    ): array {
        $definition = self::REGISTRY[$event] ?? self::REGISTRY['ticket_reply'];
        $sourceBody = $originalBody ?? $body;
        $identity = app(StorefrontContentService::class)->settings();
        $store = trim((string) ($identity['store.name'] ?? 'LFAMILIA STORE')) ?: 'LFAMILIA STORE';
        $support = trim((string) ($identity['store.email'] ?? ''));
        $hours = trim((string) ($identity['store.business_hours'] ?? ''));
        $name = trim((string) ($context['name'] ?? ''));

        if ($name === '' && filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            $name = trim((string) (DB::table('users')->where('email', $recipient)->value('name') ?? ''));
        }

        $isOrder = in_array($event, ['order_created', 'order_paid', 'order_completed', 'order_failed'], true);
        $invoice = (string) ($context['invoice'] ?? $this->field($sourceBody, 'Nomor Invoice'));
        $reference = $isOrder ? $invoice : $this->ticketReference($event, $sourceBody, $recipient);
        $transactionDate = $this->wib($context['created_at'] ?? null);
        $metaDateLabel = $isOrder ? 'Tanggal transaksi' : 'Tanggal aktivitas';
        $productName = trim((string) ($context['package'] ?? $this->packageFromBody($body)));
        $gameName = trim((string) ($context['product'] ?? ''));
        $fullProductName = $gameName !== '' && $productName !== ''
            ? $productName.' ('.$gameName.')' : ($productName ?: $gameName);
        $destination = (string) ($context['destination'] ?? $this->field($sourceBody, 'ID Tujuan'));
        $method = (string) ($context['method'] ?? $this->field($sourceBody, 'Metode Pembayaran'));
        $subtotal = (int) ($context['subtotal'] ?? 0);
        $fee = (int) ($context['fee'] ?? 0);
        $total = (int) ($context['total'] ?? 0);
        $deadline = ! empty($context['expires_at']) ? $this->wib($context['expires_at']) : '';
        $highlight = $event === 'order_created' ? $this->rupiah($total) : $definition['value'];

        $intro = '';
        $after = '';
        $ctaUrl = rtrim((string) config('app.url', 'https://lfamilia.com'), '/').'/';
        $extra = [];
        $summary = [];

        if ($isOrder) {
            $summary[] = ['label' => 'Nama Produk', 'value' => $fullProductName];
            $summary[] = ['label' => 'Jumlah dan harga satuan', 'value' => '1 x '.$this->rupiah($subtotal)];
            if ($destination !== '') {
                $summary[] = ['label' => 'ID Tujuan', 'value' => $destination];
            }
            $summary[] = ['label' => 'Total harga pesanan', 'value' => $this->rupiah($subtotal)];
            $summary[] = ['label' => 'Biaya Layanan'.($method !== '' ? ' '.$method : ''), 'value' => $this->rupiah($fee)];
            $summary[] = ['label' => 'Total Pembayaran', 'value' => $this->rupiah($total), 'strong' => true];
            $ctaUrl = $this->field($sourceBody, 'Lacak pesanan')
                ?: $this->field($sourceBody, 'Lihat detail pesanan')
                ?: route('guest.orders.lookup');
        }

        switch ($event) {
            case 'verification':
                $intro = 'Silakan verifikasi alamat email agar akun kamu dapat digunakan.';
                $ctaUrl = $this->firstHttpUrl($sourceBody) ?: $ctaUrl;
                $after = 'Tautan verifikasi memiliki batas waktu. Jika kamu tidak membuat akun ini, abaikan email ini.';
                $summary = [['label' => 'Email akun', 'value' => $recipient]];
                break;
            case 'password_reset':
                $intro = 'Kami menerima permintaan untuk mengatur ulang kata sandi akun kamu.';
                $ctaUrl = $this->firstHttpUrl($sourceBody) ?: $ctaUrl;
                $after = 'Jika kamu tidak meminta ini, abaikan email ini dan jangan bagikan tautan kepada siapa pun.';
                $summary = [['label' => 'Email akun', 'value' => $recipient]];
                break;
            case 'password_changed':
                $intro = 'Kata sandi akun kamu berhasil diperbarui.';
                $ctaUrl = route('login');
                $after = 'Jika bukan kamu yang melakukan perubahan ini, segera hubungi Customer Service kami.';
                $summary = [['label' => 'Email akun', 'value' => $recipient]];
                break;
            case 'password_reset_completed':
                $intro = 'Kata sandi akun kamu berhasil diatur ulang.';
                $ctaUrl = route('login');
                $after = 'Jika bukan kamu yang melakukan ini, segera hubungi Customer Service kami.';
                $summary = [['label' => 'Email akun', 'value' => $recipient]];
                break;
            case 'order_created':
                $intro = 'Terima kasih atas kepercayaan kamu bertransaksi di '.$store.'. Berikut rincian informasi tagihan pembayaran.';
                $ctaUrl = (string) ($context['payment_url'] ?? '');
                $after = $deadline !== ''
                    ? 'Selesaikan pembayaran sebelum '.$deadline.'. Transaksi dianggap batal jika melewati batas pembayaran. Setelah membayar, kamu dapat memeriksa status di halaman lacak pesanan.'
                    : 'Setelah membayar, kamu dapat memeriksa status pembayaran di halaman lacak pesanan.';
                $accessCode = trim((string) ($context['access_code'] ?? $this->field($sourceBody, 'Kode akses pesanan')));
                if ($accessCode !== '') {
                    $extra[] = ['label' => 'Kode akses pesanan', 'value' => $accessCode];
                    $after .= ' Simpan kode akses pesanan dan jangan bagikan kepada siapa pun.';
                }
                break;
            case 'order_paid':
                $intro = 'Pembayaran pesanan kamu sudah kami terima.';
                $after = 'Pesanan kamu sedang diproses. Kamu tidak perlu membayar lagi; perkembangan pengiriman dapat diperiksa di halaman lacak pesanan.';
                break;
            case 'order_completed':
                $intro = 'Pesanan kamu sudah terkirim. Berikut rincian hasil pengirimannya.';
                $kind = (string) ($context['delivery_kind'] ?? 'digiflazz');
                if ($kind === 'stock') {
                    $code = (string) ($context['delivery_code'] ?? $this->field($sourceBody, 'Kode voucher'));
                    if ($code !== '') {
                        $extra[] = ['label' => 'Kode voucher', 'value' => $code];
                    }
                    $after = 'Tukarkan sendiri kode voucher di layanan yang sesuai. Kode tidak otomatis masuk ke akun game; simpan dan jangan bagikan kode kepada orang lain.';
                } elseif ($kind === 'manual') {
                    $proof = (string) ($context['delivery_code'] ?? $this->field($sourceBody, 'Bukti pengiriman'));
                    $note = (string) ($context['delivery_note'] ?? $this->field($sourceBody, 'Catatan'));
                    if ($proof !== '') {
                        $extra[] = ['label' => 'Bukti pengiriman', 'value' => $proof];
                    }
                    if ($note !== '') {
                        $extra[] = ['label' => 'Catatan admin', 'value' => $note];
                    }
                    $after = 'Pengiriman telah dilakukan secara manual. Jika ada yang belum sesuai, hubungi Customer Service kami.';
                } else {
                    $referenceNumber = (string) ($context['delivery_reference'] ?? $this->field($sourceBody, 'No. Referensi Pengiriman'));
                    if ($referenceNumber !== '') {
                        $extra[] = ['label' => 'No. Referensi Pengiriman', 'value' => $referenceNumber];
                    }
                    $after = 'Silakan periksa saldo di dalam game. Jika produk belum masuk atau ada pertanyaan, hubungi Customer Service kami dengan menyebutkan nomor transaksi.';
                }
                break;
            case 'order_failed':
                $intro = 'Mohon maaf, pesanan kamu belum dapat kami proses.';
                $ctaUrl = $support !== '' ? 'mailto:'.$support : route('content.contact');
                $after = 'Hubungi Customer Service kami dengan menyebutkan nomor transaksi '.$invoice.' dan melampirkan bukti pembayaran. Jangan membuat pesanan baru terlebih dahulu.';
                break;
            case 'ticket_created':
                $intro = 'Tiket bantuan kamu telah diterima oleh tim '.$store.'.';
                $number = $this->ticketNumber($sourceBody);
                $summary = [['label' => 'Nomor tiket', 'value' => $number !== '' ? '#'.$number : $reference]];
                $ctaUrl = $number !== '' ? url('/account/tickets/'.$number) : route('content.contact');
                $after = 'Tim kami akan meninjau tiket kamu. Periksa halaman tiket untuk melihat perkembangannya.';
                break;
            case 'ticket_reply':
                $intro = 'Tim '.$store.' telah mengirim balasan untuk tiket bantuan kamu.';
                $number = $this->ticketNumber($sourceBody);
                $summary = [['label' => 'Nomor tiket', 'value' => $number !== '' ? '#'.$number : $reference]];
                $reply = preg_replace('/^Tim LFAMILIA membalas tiket #[0-9]+:\s*/u', '', trim($body));
                if (is_string($reply) && $reply !== '') {
                    $extra[] = ['label' => 'Balasan', 'value' => $reply];
                }
                $ctaUrl = ($context['ticket_guest'] ?? false)
                    ? route('guest.support')
                    : ($number !== '' ? url('/account/tickets/'.$number) : route('content.contact'));
                $after = 'Jika masih membutuhkan bantuan, silakan balas melalui tiket atau hubungi Customer Service kami.';
                break;
        }

        // Existing admin-editable wrappers around {{default_body}} must remain visible.
        if ($originalBody !== null && $body !== $originalBody) {
            $additional = trim(str_replace($originalBody, '', $body));
            if ($additional !== '') {
                $extra[] = ['label' => 'Pesan tambahan', 'value' => $additional];
            }
        }

        $override = app(TemplateOverrideService::class)->apply('email.'.$event, $subject, $intro, [
            'nama' => $name,
            'nama_toko' => $store,
            'email' => $recipient,
            'invoice' => $invoice,
            'produk' => $fullProductName,
            'total' => $this->rupiah($total),
            'batas_waktu' => $deadline,
            'link_bayar' => (string) ($context['payment_url'] ?? ''),
            'link_lacak' => route('guest.orders.lookup'),
        ], $previewDraft);
        $subject = $override['subject'];
        $intro = $override['body'];

        $linkToSupport = $support !== '' ? 'mailto:'.$support : route('content.contact');
        $copy = [
            'store' => $store,
            'subject' => $subject,
            'logoUrl' => 'https://lfamilia.com/brand/lfamilia-logo-email.png',
            'name' => $name,
            'metaDateLabel' => $metaDateLabel,
            'metaDate' => $transactionDate,
            'reference' => $reference,
            'highlightLabel' => $definition['label'],
            'highlightValue' => $highlight,
            'summaryTitle' => $isOrder ? 'Ringkasan Produk' : ($event === 'ticket_created' || $event === 'ticket_reply' ? 'Ringkasan Tiket' : 'Informasi Akun'),
            'summary' => $summary,
            'extra' => $extra,
            'intro' => $intro,
            'after' => $after,
            'ctaLabel' => $definition['cta'],
            'ctaUrl' => $ctaUrl,
            'support' => $support,
            'supportLink' => $linkToSupport,
            'hours' => $hours,
            'year' => now()->timezone('Asia/Jakarta')->year,
        ];

        return [
            'subject' => $subject,
            'html' => view('emails.customer', $copy)->render(),
            'text' => $this->plainText($copy),
        ];
    }

    private function field(string $text, string $label): string
    {
        return preg_match('/^'.preg_quote($label, '/').':\s*(.+)$/mu', $text, $match) === 1
            ? trim($match[1]) : '';
    }

    private function firstHttpUrl(string $text): string
    {
        return preg_match('~https?://[^\s<>]+~u', $text, $match) === 1 ? $match[0] : '';
    }

    private function packageFromBody(string $text): string
    {
        $product = $this->field($text, 'Produk');

        return trim((string) (explode(' — ', $product, 2)[1] ?? $product));
    }

    private function ticketNumber(string $body): string
    {
        return preg_match('/tiket\s*#([0-9]+)/iu', $body, $match) === 1 ? $match[1] : '';
    }

    private function ticketReference(string $event, string $body, string $recipient): string
    {
        $ticket = $this->ticketNumber($body);

        return str_starts_with($event, 'ticket_') && $ticket !== '' ? '#'.$ticket : $recipient;
    }

    private function wib(mixed $date): string
    {
        $parsed = $date === null || $date === '' ? now('Asia/Jakarta') : Carbon::parse($date, 'UTC')->timezone('Asia/Jakarta');

        return $parsed->locale('id')->translatedFormat('j F Y').' pukul '.$parsed->format('H.i').' WIB';
    }

    private function rupiah(int $amount): string
    {
        return 'Rp. '.number_format($amount, 0, ',', '.');
    }

    /** @param  array<string, mixed>  $data */
    private function plainText(array $data): string
    {
        $lines = [
            $data['subject'],
            '',
            $data['name'] !== '' ? 'Hai '.$data['name'].',' : 'Hai,',
            '',
            $data['intro'],
            $data['metaDateLabel'].': '.$data['metaDate'],
            'No. Transaksi: '.$data['reference'],
            '',
            $data['highlightLabel'].': '.$data['highlightValue'],
            $data['summaryTitle'],
        ];
        foreach (array_merge($data['summary'], $data['extra']) as $row) {
            $lines[] = $row['label'].': '.$row['value'];
        }
        $lines = [
            ...$lines,
            '',
            $data['ctaLabel'].': '.(str_starts_with($data['ctaUrl'], 'mailto:')
                ? 'lihat alamat Customer Service di footer' : $data['ctaUrl']),
            '',
            $data['after'],
            '',
            'Terima kasih atas perhatian dan kepercayaan kamu di '.$data['store'].'.',
            '',
            'Ini adalah email otomatis, mohon untuk tidak membalas email ini.',
        ];
        if ($data['support'] !== '') {
            $lines[] = 'Customer Service: '.$data['support'];
        }
        if ($data['hours'] !== '') {
            $lines[] = 'Jam layanan: '.$data['hours'];
        }
        $lines[] = 'LFAMILIA STORE tidak pernah meminta password atau kode OTP kamu.';
        $lines[] = '© '.$data['year'].' '.$data['store'];

        return implode("\n", $lines);
    }
}
