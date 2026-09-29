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
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('RESEND_SEND_FAILED_'.$response->status());
        }
    }

    public function backoff(): array
    {
        return [30, 120, 300];
    }
}
