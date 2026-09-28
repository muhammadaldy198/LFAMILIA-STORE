<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class GatewayPaymentService
{
    public function __construct(
        private readonly IntegrationConfigService $integrations,
        private readonly PaymentChannelService $channels,
    ) {
    }

    /** @return array{ready:bool,environment:?string,mode:?string,reason:?string} */
    public function readiness(string $gateway, string $method, string $channel, array $gatewayConfig): array
    {
        $paymentType = $this->channels->paymentType($gateway, $method, $channel, $gatewayConfig);
        if (!$paymentType) {
            return ['ready' => false, 'environment' => null, 'mode' => null, 'reason' => 'Channel belum didukung gateway.'];
        }

        try {
            [$environment, $credentials] = $this->credentials($gateway);
            if ($gateway === 'midtrans') {
                $ready = trim((string) ($credentials['serverKey'] ?? '')) !== ''
                    && trim((string) ($credentials['clientKey'] ?? '')) !== ''
                    && $this->httpsOrigin((string) config('lfamilia.integrations.midtrans.snap_base_url')) !== null;
                return [
                    'ready' => $ready,
                    'environment' => $environment,
                    'mode' => 'snap',
                    'reason' => $ready ? null : 'Kredensial/URL Midtrans Snap belum lengkap.',
                ];
            }

            $apiUrl = trim((string) ($credentials['apiUrl'] ?? config('lfamilia.integrations.doku.api_base_url')));
            $ready = trim((string) ($credentials['clientId'] ?? '')) !== ''
                && trim((string) ($credentials['secretKey'] ?? '')) !== ''
                && $this->httpsOrigin($apiUrl) !== null;

            return [
                'ready' => $ready,
                'environment' => $environment,
                'mode' => 'checkout',
                'reason' => $ready ? null : 'Kredensial/URL DOKU Checkout belum lengkap.',
            ];
        } catch (RuntimeException $error) {
            return ['ready' => false, 'environment' => null, 'mode' => null, 'reason' => $error->getMessage()];
        }
    }

    /** @return array<string,mixed> */
    public function create(array $input): array
    {
        $readiness = $this->readiness(
            $input['gateway'],
            $input['paymentMethod'],
            $input['paymentChannel'],
            $input['gatewayConfig'],
        );

        if (!$readiness['ready'] || !$readiness['environment'] || !$readiness['mode']) {
            throw new RuntimeException($readiness['reason'] ?: 'Gateway belum siap.');
        }

        $paymentType = $this->channels->paymentType(
            $input['gateway'],
            $input['paymentMethod'],
            $input['paymentChannel'],
            $input['gatewayConfig'],
        );
        if (!$paymentType) {
            throw new RuntimeException('Channel pembayaran belum didukung.');
        }

        return $input['gateway'] === 'midtrans'
            ? $this->createMidtrans($input, $readiness['environment'], $paymentType)
            : $this->createDoku($input, $readiness['environment'], $paymentType);
    }

    /** @return array{0:string,1:array<string,string>} */
    private function credentials(string $gateway): array
    {
        $setting = $this->integrations->setting($gateway.'_environment');
        $configured = trim((string) config('lfamilia.integrations.'.$gateway.'.environment'));
        $environment = in_array($setting, ['sandbox', 'production'], true)
            ? $setting
            : (in_array($configured, ['sandbox', 'production'], true) ? $configured : 'sandbox');

        try {
            $profile = $this->integrations->paymentProfile($gateway, $environment);
        } catch (\Throwable) {
            $profile = [];
        }

        if ($gateway === 'midtrans') {
            $profile['serverKey'] = trim((string) ($profile['serverKey'] ?? config('lfamilia.integrations.midtrans.server_key')));
            $profile['clientKey'] = trim((string) ($profile['clientKey'] ?? config('lfamilia.integrations.midtrans.client_key')));
        } else {
            $profile['clientId'] = trim((string) ($profile['clientId'] ?? config('lfamilia.integrations.doku.client_id')));
            $profile['secretKey'] = trim((string) ($profile['secretKey'] ?? config('lfamilia.integrations.doku.secret_key')));
            $profile['apiUrl'] = trim((string) ($profile['apiUrl'] ?? config('lfamilia.integrations.doku.api_base_url')));
        }

        return [$environment, $profile];
    }

    /** @return array<string,mixed> */
    private function createMidtrans(array $input, string $environment, string $paymentType): array
    {
        [, $credentials] = $this->credentials('midtrans');
        $serverKey = (string) $credentials['serverKey'];
        $origin = $this->httpsOrigin((string) config('lfamilia.integrations.midtrans.snap_base_url'));
        if (!$origin) {
            throw new RuntimeException('URL Midtrans Snap belum dikonfigurasi.');
        }

        $payload = [
            'transaction_details' => [
                'order_id' => $input['referenceId'],
                'gross_amount' => $input['amount'],
            ],
            'item_details' => [[
                'id' => substr($input['referenceId'], -40),
                'price' => $input['amount'],
                'quantity' => 1,
                'name' => mb_substr($input['productName'], 0, 50),
            ]],
            'customer_details' => [
                'first_name' => mb_substr($input['buyerName'], 0, 50),
                'email' => $input['buyerEmail'],
                'phone' => $input['buyerPhone'],
            ],
            'enabled_payments' => [$paymentType],
            'callbacks' => ['finish' => $input['finishUrl']],
            'expiry' => [
                'start_time' => CarbonImmutable::now('Asia/Jakarta')->format('Y-m-d H:i:s O'),
                'unit' => 'minutes',
                'duration' => 60,
            ],
            'page_expiry' => ['unit' => 'minutes', 'duration' => 60],
        ];

        $response = Http::acceptJson()
            ->withBasicAuth($serverKey, '')
            ->timeout(15)
            ->post($origin.'/snap/v1/transactions', $payload);

        $body = $response->json();
        if (!is_array($body)) {
            $body = [];
        }

        $token = trim((string) ($body['token'] ?? ''));
        $paymentUrl = trim((string) ($body['redirect_url'] ?? ''));
        if (!$response->successful() || $token === '' || $paymentUrl === '') {
            $messages = $body['error_messages'] ?? [];
            $message = is_array($messages) ? implode('; ', array_filter($messages, 'is_string')) : '';
            throw new RuntimeException($message ?: 'Midtrans Snap gagal membuat pembayaran.');
        }

        return [
            'gateway' => 'midtrans',
            'mode' => 'snap',
            'environment' => $environment,
            'requestId' => $token,
            'referenceNo' => $token,
            'paymentNo' => null,
            'qrContent' => null,
            'paymentUrl' => $paymentUrl,
            'paymentName' => strtoupper($input['paymentChannel']),
            'expiredAt' => now()->addMinutes(60)->toIso8601String(),
            'raw' => $body,
        ];
    }

    /** @return array<string,mixed> */
    private function createDoku(array $input, string $environment, string $paymentType): array
    {
        [, $credentials] = $this->credentials('doku');
        $origin = $this->httpsOrigin((string) $credentials['apiUrl']);
        if (!$origin) {
            throw new RuntimeException('URL DOKU Checkout belum dikonfigurasi.');
        }

        $endpoint = '/checkout/v1/payment';
        $requestId = (string) Str::uuid();
        $timestamp = now('UTC')->toIso8601String();
        $notificationUrl = rtrim($input['publicBaseUrl'], '/').'/api/payments/doku/callback';

        $payload = [
            'order' => [
                'amount' => $input['amount'],
                'invoice_number' => $input['referenceId'],
                'currency' => 'IDR',
                'callback_url' => $input['finishUrl'],
                'callback_url_result' => $input['finishUrl'],
                'language' => 'ID',
                'auto_redirect' => true,
            ],
            'payment' => [
                'payment_due_date' => 60,
                'payment_method_types' => [$paymentType],
            ],
            'additional_info' => [
                'override_notification_url' => $notificationUrl,
            ],
        ];

        $rawBody = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $digest = base64_encode(hash('sha256', $rawBody, true));
        $signatureRaw = implode("\n", [
            'Client-Id:'.$credentials['clientId'],
            'Request-Id:'.$requestId,
            'Request-Timestamp:'.$timestamp,
            'Request-Target:'.$endpoint,
            'Digest:'.$digest,
        ]);
        $signature = 'HMACSHA256='.base64_encode(hash_hmac(
            'sha256',
            $signatureRaw,
            $credentials['secretKey'],
            true,
        ));

        $response = Http::withHeaders([
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Client-Id' => $credentials['clientId'],
            'Request-Id' => $requestId,
            'Request-Timestamp' => $timestamp,
            'Signature' => $signature,
        ])->withBody($rawBody, 'application/json')
          ->timeout(15)
          ->post($origin.$endpoint);

        $body = $response->json();
        if (!is_array($body)) {
            $body = [];
        }

        $paymentUrl = trim((string) data_get($body, 'response.payment.url', ''));
        if (!$response->successful() || $paymentUrl === '') {
            $messages = $body['error_messages'] ?? ($body['message'] ?? []);
            $message = is_array($messages) ? implode('; ', array_filter($messages, 'is_string')) : '';
            throw new RuntimeException($message ?: 'DOKU gagal membuat Checkout.');
        }

        $expiresRaw = trim((string) data_get($body, 'response.payment.expired_date', ''));
        $expiredAt = null;
        if (preg_match('/^\d{14}$/', $expiresRaw)) {
            $expiredAt = CarbonImmutable::createFromFormat('YmdHis', $expiresRaw, 'Asia/Jakarta')
                ?->utc()
                ->toIso8601String();
        }

        return [
            'gateway' => 'doku',
            'mode' => 'checkout',
            'environment' => $environment,
            'requestId' => $requestId,
            'referenceNo' => trim((string) data_get($body, 'response.order.session_id', '')) ?: null,
            'paymentNo' => null,
            'qrContent' => null,
            'paymentUrl' => $paymentUrl,
            'paymentName' => $input['paymentMethod'] === 'qris'
                ? 'QRIS'
                : ($input['paymentMethod'] === 'va'
                    ? 'Virtual Account '.strtoupper($input['paymentChannel'])
                    : strtoupper($input['paymentChannel'])),
            'expiredAt' => $expiredAt ?: now()->addMinutes(60)->toIso8601String(),
            'raw' => $body,
        ];
    }

    private function httpsOrigin(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $parts = parse_url($value);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])) {
            return null;
        }

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return 'https://'.$parts['host'].$port;
    }
}
