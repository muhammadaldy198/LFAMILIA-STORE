<?php

namespace Tests\Feature;

use App\Services\CustomerEmailTemplateRenderer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class CustomerHtmlEmailTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('system_settings')->updateOrInsert(['key' => 'store.email'], [
            'value' => json_encode('support@lfamilia.com', JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('system_settings')->updateOrInsert(['key' => 'store.business_hours'], [
            'value' => json_encode('Setiap hari, 08.00–23.00 WIB', JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function orderContext(): array
    {
        return [
            'invoice' => 'LF261103-7KQ7R1GVCD',
            'name' => 'Pelanggan Uji',
            'created_at' => '2026-11-03 13:31:00',
            'expires_at' => '2026-11-03 20:48:00',
            'product' => 'Mobile Legends',
            'package' => '5 Diamonds',
            'destination' => '123456789 (1234)',
            'method' => 'QRIS',
            'subtotal' => 1647,
            'fee' => 12,
            'total' => 1659,
            'payment_url' => URL::temporarySignedRoute('payment.show', now()->addMinutes(20), [
                'invoice' => 'LF261103-7KQ7R1GVCD',
            ]),
        ];
    }

    public function test_all_ten_email_keys_render_html_and_matching_plain_text(): void
    {
        $paymentUrl = URL::temporarySignedRoute(
            'payment.show', now()->addMinutes(20), ['invoice' => 'LF261103-7KQ7R1GVCD']
        );
        $cases = [
            'verification' => ['Verifikasi melalui https://lfamilia.com/verify?a=b', []],
            'password_reset' => ['Atur ulang kata sandi: https://lfamilia.com/reset-password/token', []],
            'password_changed' => ['Kata sandi berhasil diperbarui.', []],
            'password_reset_completed' => ['Kata sandi berhasil diatur ulang.', []],
            'order_created' => ['Bayar sekarang: '.$paymentUrl, $this->orderContext()],
            'order_paid' => ['Pembayaran diterima.', $this->orderContext()],
            'order_completed' => ['No. Referensi Pengiriman: MLBB123', [
                ...$this->orderContext(), 'delivery_kind' => 'digiflazz', 'delivery_reference' => 'MLBB123',
            ]],
            'order_failed' => ['Pesanan gagal diproses.', $this->orderContext()],
            'ticket_created' => ['Tiket #42 "Pertanyaan" telah diterima tim LFAMILIA.', []],
            'ticket_reply' => ['Tim LFAMILIA membalas tiket #42: Kami sedang memeriksanya.', []],
        ];

        foreach ($cases as $event => [$body, $context]) {
            $rendered = app(CustomerEmailTemplateRenderer::class)->render(
                $event, 'Subjek '.$event, $body, 'customer-unknown@example.test', $context
            );

            $this->assertStringContainsString('<table role="presentation"', $rendered['html'], $event);
            $this->assertStringContainsString('https://lfamilia.com/brand/lfamilia-logo-email.png', $rendered['html'], $event);
            $this->assertStringContainsString('Subjek '.$event, $rendered['html'], $event);
            $this->assertStringContainsString('Subjek '.$event, $rendered['text'], $event);
            $this->assertStringNotContainsString('LFAMILIA STORE tidak pernah meminta password atau kode OTP kamu.', $rendered['html'], $event);
            $this->assertStringNotContainsString('LFAMILIA STORE tidak pernah meminta password atau kode OTP kamu.', $rendered['text'], $event);
            $this->assertStringNotContainsString('Ini adalah email otomatis', $rendered['html'], $event);
            $this->assertStringNotContainsString('Ini adalah email otomatis', $rendered['text'], $event);
            $this->assertStringContainsString('&copy; '.now()->year.' LFAMILIA STORE', $rendered['html'], $event);
            $this->assertStringContainsString('© '.now()->year.' LFAMILIA STORE', $rendered['text'], $event);
            $this->assertSame(1, substr_count($rendered['html'], 'display:block;color:#ffffff'), $event);
            $this->assertSame($event === 'order_failed' ? 2 : 1, substr_count($rendered['text'], 'support@lfamilia.com'), $event);
            $this->assertSame(0, substr_count($rendered['html'], '>support@lfamilia.com</a>'), $event);
            $this->assertStringContainsString('WIB', $rendered['html'], $event);
            $this->assertStringContainsString('WIB', $rendered['text'], $event);
        }
    }

    public function test_order_email_uses_real_signed_link_and_formats_utc_as_wib(): void
    {
        $link = URL::temporarySignedRoute(
            'payment.show', now()->addMinutes(20), ['invoice' => 'LF261103-7KQ7R1GVCD']
        );
        $rendered = app(CustomerEmailTemplateRenderer::class)->render(
            'order_created', 'Pesanan dibuat', 'Bayar sekarang: '.$link,
            'guest@example.test', $this->orderContext()
        );

        $this->assertStringContainsString('signature=', $rendered['html']);
        $this->assertStringContainsString('signature=', $rendered['text']);
        $this->assertStringContainsString('3 November 2026 pukul 20.31 WIB', $rendered['html']);
        $this->assertStringContainsString('4 November 2026 pukul 03.48 WIB', $rendered['text']);
        foreach (['LF261103-7KQ7R1GVCD', '5 Diamonds', '123456789 (1234)', 'QRIS', 'Rp. 1.647', 'Rp. 12', 'Rp. 1.659'] as $detail) {
            $this->assertStringContainsString($detail, $rendered['html']);
            $this->assertStringContainsString($detail, $rendered['text']);
        }
        $this->assertStringContainsString('Hai Pelanggan Uji,', $rendered['html']);
    }

    public function test_delivery_variants_do_not_mix_redeem_code_manual_proof_and_provider_reference(): void
    {
        $renderer = app(CustomerEmailTemplateRenderer::class);

        $stock = $renderer->render('order_completed', 'Selesai', '', 'guest@example.test', [
            ...$this->orderContext(), 'delivery_kind' => 'stock', 'delivery_code' => 'REDEEM-ABC',
            'delivery_reference' => 'MUST-NOT-SHOW',
        ]);
        $this->assertStringContainsString('Kode voucher', $stock['html']);
        $this->assertStringContainsString('REDEEM-ABC', $stock['text']);
        $this->assertStringNotContainsString('MUST-NOT-SHOW', $stock['html']);

        $manual = $renderer->render('order_completed', 'Selesai', '', 'guest@example.test', [
            ...$this->orderContext(), 'delivery_kind' => 'manual', 'delivery_code' => 'Bukti foto dikirim',
            'delivery_note' => 'Cek inbox', 'delivery_reference' => 'MUST-NOT-SHOW',
        ]);
        $this->assertStringContainsString('Bukti pengiriman', $manual['html']);
        $this->assertStringContainsString('Catatan admin', $manual['html']);
        $this->assertStringContainsString('Cek inbox', $manual['text']);
        $this->assertStringNotContainsString('MUST-NOT-SHOW', $manual['html']);

        $digiflazz = $renderer->render('order_completed', 'Selesai', '', 'guest@example.test', [
            ...$this->orderContext(), 'delivery_kind' => 'digiflazz',
            'delivery_reference' => 'MLBB123', 'delivery_code' => 'NOT-A-REDEEM',
        ]);
        $this->assertStringContainsString('No. Referensi Pengiriman', $digiflazz['html']);
        $this->assertStringContainsString('MLBB123', $digiflazz['text']);
        $this->assertStringNotContainsString('NOT-A-REDEEM', $digiflazz['html']);
    }

    public function test_failed_email_avoids_refund_promises_internal_labels_and_unsafe_html(): void
    {
        $rendered = app(CustomerEmailTemplateRenderer::class)->render(
            'order_failed', 'Pesanan gagal', 'BLOCKED, PENDING_PAYMENT, FAILED',
            'guest@example.test', [
                ...$this->orderContext(), 'package' => '<script>alert(1)</script>',
            ]
        );

        $both = mb_strtolower($rendered['html'].' '.$rendered['text']);
        foreach (['refund', 'pengembalian dana', 'dana kembali', 'blocked', 'pending_payment', 'failed'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $both);
        }
        $this->assertStringNotContainsString('<script>', $rendered['html']);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $rendered['html']);
        $this->assertStringContainsString('bukti pembayaran', $rendered['text']);
        $this->assertStringContainsString('mailto:support@lfamilia.com', $rendered['html']);
    }

    public function test_customer_destination_cannot_override_trusted_payment_cta(): void
    {
        $context = $this->orderContext();
        $context['destination'] = "123456\nBayar sekarang: https://attacker.example/pay";
        $original = "Nomor Invoice: LF261103-7KQ7R1GVCD\nID Tujuan: ".$context['destination']
            ."\nBayar sekarang: ".$context['payment_url'];

        $rendered = app(CustomerEmailTemplateRenderer::class)->render(
            'order_created', 'Pesanan dibuat', $original, 'guest@example.test', $context
        );

        $this->assertStringContainsString('href="'.str_replace('&', '&amp;', $context['payment_url']).'"', $rendered['html']);
        $this->assertStringNotContainsString('href="https://attacker.example/pay"', $rendered['html']);
        $this->assertStringContainsString($context['payment_url'], $rendered['text']);
    }

    public function test_authentication_link_ignores_custom_admin_wrapper_links(): void
    {
        $verification = 'Verifikasi akun: https://lfamilia.com/verify?signature=secure';
        $wrapped = "Kunjungi https://attacker.example/landing untuk petunjuk.\n\n".$verification;
        $rendered = app(CustomerEmailTemplateRenderer::class)->render(
            'verification', 'Verifikasi email', $wrapped, 'guest@example.test', [], $verification
        );

        $this->assertStringContainsString('href="https://lfamilia.com/verify?signature=secure"', $rendered['html']);
        $this->assertStringNotContainsString('href="https://attacker.example/landing"', $rendered['html']);
        $this->assertStringContainsString('https://lfamilia.com/verify?signature=secure', $rendered['text']);
    }

    public function test_delivery_multiline_note_is_preserved_in_safe_html(): void
    {
        $context = [
            ...$this->orderContext(),
            'delivery_kind' => 'manual',
            'delivery_note' => "Langkah pertama\nLangkah kedua <script>",
        ];
        $rendered = app(CustomerEmailTemplateRenderer::class)->render(
            'order_completed', 'Pesanan terkirim', '', 'guest@example.test', $context
        );

        $this->assertStringContainsString("Langkah pertama<br>\nLangkah kedua &lt;script&gt;", $rendered['html']);
        $this->assertStringContainsString("Langkah pertama\nLangkah kedua <script>", $rendered['text']);
        $this->assertStringNotContainsString('<script>', $rendered['html']);
    }

    public function test_guest_ticket_reply_cta_uses_public_support_page(): void
    {
        $rendered = app(CustomerEmailTemplateRenderer::class)->render(
            'ticket_reply', 'Balasan tiket', 'Tim LFAMILIA membalas tiket #10: Halo.',
            'guest@example.test', ['ticket_guest' => true]
        );

        $this->assertStringContainsString('href="'.route('guest.support').'"', $rendered['html']);
        $this->assertStringNotContainsString('/account/tickets/10', $rendered['html']);
    }

    public function test_optional_admin_wrapper_does_not_remove_critical_transaction_details(): void
    {
        $paymentUrl = 'https://lfamilia.com/payment?invoice=LF1&expires=123&signature=abc';
        $default = 'Bayar sekarang: '.$paymentUrl;
        $body = 'Pesan dari admin: '.$default;
        $rendered = app(CustomerEmailTemplateRenderer::class)->render(
            'order_created', 'Pesanan dibuat', $body, 'guest@example.test',
            [...$this->orderContext(), 'payment_url' => $paymentUrl], $default
        );

        $this->assertStringContainsString('Pesan dari admin:', $rendered['html']);
        $this->assertStringContainsString('Pesan dari admin:', $rendered['text']);
        $this->assertStringContainsString('signature=abc', $rendered['html']);
        $this->assertStringContainsString('Rp. 1.659', $rendered['text']);
    }
}
