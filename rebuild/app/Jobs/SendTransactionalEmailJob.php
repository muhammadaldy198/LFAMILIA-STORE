<?php

namespace App\Jobs;

use App\Models\IntegrationCredential;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SendTransactionalEmailJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly string $recipient,
        public readonly string $subject,
        public readonly string $text,
    ) {
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        $record = IntegrationCredential::where('code', 'resend')->where('is_active', true)->first();
        $config = $record?->config_ciphertext;
        if (! is_array($config) || empty($config['api_key']) || empty($config['from_email'])) {
            throw new RuntimeException('RESEND_NOT_CONFIGURED');
        }

        $response = Http::withToken((string) $config['api_key'])
            ->acceptJson()->timeout(10)->post('https://api.resend.com/emails', [
                'from' => (string) $config['from_email'],
                'to' => [$this->recipient],
                'subject' => $this->subject,
                'text' => $this->text,
                'html' => $this->htmlBody(),
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('RESEND_SEND_FAILED_'.$response->status());
        }
    }

    private function htmlBody(): string
    {
        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $subject = $escape($this->subject);
        $message = nl2br($escape($this->text), false);
        $url = 'https://lfamiliastore.my.id';
        $contact = $url.'/contact';
        $logo = $url.'/brand/lfamilia-footer-desktop-wordmark.jpg';

        return '<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<title>'.$subject.'</title></head>'
            .'<body style="margin:0;padding:24px 12px;background:#f3f5f9;color:#172033;font-family:Arial,Helvetica,sans-serif">'
            .'<div style="display:none;font-size:1px;color:#f3f5f9;max-height:0;overflow:hidden">'.$subject.'</div>'
            .'<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;margin:auto;background:#fff;border-radius:16px;border:1px solid #e5e9f0">'
            .'<tr><td style="padding:28px 30px 20px;border-bottom:1px solid #e5e9f0">'
            .'<a href="'.$url.'" style="text-decoration:none;color:#172033"><img src="'.$logo.'" alt="LFAMILIA STORE" width="220" style="display:block;max-width:100%;height:auto;border:0"></a></td></tr>'
            .'<tr><td style="padding:30px"><h1 style="font-size:23px;line-height:1.35;margin:0 0 20px;color:#172033">'.$subject.'</h1>'
            .'<div style="font-size:15px;line-height:1.8;overflow-wrap:anywhere">'.$message.'</div>'
            .'<p style="margin:28px 0 0"><a href="'.$url.'" style="display:inline-block;background:#172033;color:#fff;text-decoration:none;padding:13px 22px;border-radius:8px;font-size:14px;font-weight:bold">Kunjungi LFAMILIA STORE</a></p>'
            .'</td></tr><tr><td style="padding:24px 30px;background:#f8fafc;border-top:1px solid #e5e9f0;border-radius:0 0 16px 16px">'
            .'<p style="margin:0 0 10px;font-size:13px;color:#475569">Butuh bantuan? <a href="mailto:support@lfamiliastore.my.id" style="color:#172033">support@lfamiliastore.my.id</a> · <a href="'.$contact.'" style="color:#172033">Hubungi Kami</a></p>'
            .'<p style="margin:0;font-size:12px;line-height:1.6;color:#64748b">Pesan otomatis dari LFAMILIA STORE. Jangan pernah membagikan kata sandi, PIN, atau OTP. &copy; '.date('Y').' LFAMILIA STORE.</p>'
            .'</td></tr></table></body></html>';
    }

    public function backoff(): array
    {
        return [30, 120, 300];
    }
}
