<?php

namespace App\Jobs;

use App\Models\IntegrationCredential;
use App\Services\StorefrontContentService;
use App\Services\EditableMessageTemplateService;
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
        public readonly string $templateKey = 'generic',
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

        $rendered = app(EditableMessageTemplateService::class)
            ->renderEmail($this->templateKey, $this->subject, $this->text);

        $response = Http::withToken((string) $config['api_key'])
            ->acceptJson()->timeout(10)->post('https://api.resend.com/emails', [
                'from' => (string) $config['from_email'],
                'to' => [$this->recipient],
                'subject' => $rendered['subject'],
                'text' => $rendered['body'],
                'html' => $this->htmlBody(app(StorefrontContentService::class)->assets(), $rendered['subject'], $rendered['body']),
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('RESEND_SEND_FAILED_'.$response->status());
        }
    }

    private function htmlBody(array $assets = [], ?string $mailSubject = null, ?string $mailText = null): string
    {
        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $subject = $escape($mailSubject ?? $this->subject);
        $message = nl2br($escape($mailText ?? $this->text), false);
        try {
            $identity = app(StorefrontContentService::class)->settings();
            $siteUrl = (string) config('app.url', 'https://lfamiliastore.my.id');
        } catch (\Throwable) {
            // Plain PHPUnit tests and maintenance pages may have no Laravel DB.
            $identity = [];
            $siteUrl = 'https://lfamiliastore.my.id';
        }
        $storeName = $escape((string) ($identity['store.name'] ?? 'LFAMILIA STORE'));
        $supportEmail = (string) (($identity['store.email'] ?? '') ?: 'support@lfamiliastore.my.id');
        $safeEmail = $escape($supportEmail);
        $url = $escape(rtrim($siteUrl, '/'));
        $contact = $escape((string) (($identity['store.support_url'] ?? '') ?: $url.'/contact'));
        $footer = $escape((string) ($assets['footer_banner_desktop']['url'] ?? $url.'/brand/lfamilia-footer-desktop-wordmark.jpg'));

        return '<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<title>'.$subject.'</title></head>'
            .'<body style="margin:0;padding:0;background:#111923;color:#eaf2ff;font-family:Arial,Helvetica,sans-serif">'
            .'<div style="display:none;font-size:1px;color:#f3f5f9;max-height:0;overflow:hidden">'.$subject.'</div>'
            .'<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;margin:auto;background:#111923;border-radius:0;border:0">'
            .'<tr><td style="padding:32px 24px 24px"><h1 style="font-size:23px;line-height:1.35;margin:0 0 20px;color:#f8fafc">'.$subject.'</h1>'
            .'<div style="font-size:15px;line-height:1.8;color:#e2e8f0;overflow-wrap:anywhere">'.$message.'</div>'
            .'<p style="margin:28px 0 0"><a href="'.$url.'" style="display:inline-block;background:#16a6c9;color:#06141d;text-decoration:none;padding:13px 22px;border-radius:8px;font-size:14px;font-weight:bold">Kunjungi '.$storeName.'</a></p>'
            .'</td></tr><tr><td style="padding:0 24px 16px;background:#111923"><div style="border-top:1px solid #263647;padding-top:20px;text-align:center"><a href="'.$url.'" style="display:inline-block;max-width:100%"><img src="'.$footer.'" alt="'.$storeName.'" width="440" style="display:block;width:100%;max-width:440px;height:auto;border:0"></a></div></td></tr><tr><td style="padding:12px 24px 24px;background:#111923">'
            .'<p style="margin:0 0 10px;font-size:13px;color:#cbd5e1">Butuh bantuan? <a href="mailto:'.$safeEmail.'" style="color:#67d9f5">'.$safeEmail.'</a> · <a href="'.$contact.'" style="color:#67d9f5">Hubungi Kami</a></p>'
            .'<p style="margin:0;font-size:12px;line-height:1.6;color:#94a3b8">Pesan otomatis dari '.$storeName.'. Jangan pernah membagikan kata sandi, PIN, atau OTP. &copy; '.date('Y').' '.$storeName.'.</p>'
            .'</td></tr></table></body></html>';
    }

    public function backoff(): array
    {
        return [30, 120, 300];
    }
}
