<?php

namespace App\Http\Controllers;

use App\Services\CustomerAuthService;
use App\Services\GatewayPaymentService;
use App\Services\PaymentChannelService;
use App\Services\SecurityGuard;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class WalletTopupController extends Controller
{
    public function settings(
        PaymentChannelService $channels,
        GatewayPaymentService $gateway,
    ): JsonResponse {
        try {
            $wallet = DB::table('wallet_settings')->where('id', 1)->first();
            $minimum = max(1000, (int) ($wallet?->min_topup ?? 10000));
            $automatic = (bool) ($wallet?->doku_topup_enabled ?? false);
            $selectedGateway = DB::table('integration_settings')
                ->where('setting_key', 'wallet_topup_gateway')
                ->value('value');
            $selectedGateway = in_array($selectedGateway, ['doku', 'midtrans'], true)
                ? $selectedGateway
                : null;

            $gatewayActive = $selectedGateway
                ? DB::table('payment_gateway_settings')
                    ->where('gateway', $selectedGateway)
                    ->where('is_active', 1)
                    ->exists()
                : false;

            $publicChannels = [];
            if ($automatic && $selectedGateway && $gatewayActive) {
                $rows = DB::table('payment_channels')
                    ->where('is_active', 1)
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->get();

                foreach ($rows as $row) {
                    $config = $this->topupGatewayConfig($row, $selectedGateway);
                    $readiness = $gateway->readiness(
                        $selectedGateway,
                        (string) $row->method,
                        (string) $row->channel,
                        $config,
                    );
                    if (!$readiness['ready']
                        || !$channels->paymentType(
                            $selectedGateway,
                            (string) $row->method,
                            (string) $row->channel,
                            $config,
                        )) {
                        continue;
                    }

                    $publicChannels[] = [
                        'method' => (string) $row->method,
                        'channel' => (string) $row->channel,
                        'name' => (string) $row->name,
                        'description' => (string) $row->description,
                        'imageUrl' => $row->image_url ?: null,
                        'customerFeeEnabled' => !in_array(
                            strtolower(trim((string) ($config['customerFeeEnabled'] ?? 'true'))),
                            ['false', '0'],
                            true,
                        ),
                        'customerFeeBps' => max(0, (int) ($config['customerFeeBps'] ?? 0)),
                        'customerFeeFixed' => max(0, (int) ($config['customerFeeFixed'] ?? 0)),
                    ];
                }
            }

            return response()->json([
                'settings' => [
                    'enabled' => $automatic && $gatewayActive && $publicChannels !== [],
                    'minimumAmount' => $minimum,
                ],
                'channels' => $publicChannels,
            ], 200, [
                'Cache-Control' => 'public, max-age=10, s-maxage=10, stale-while-revalidate=20',
            ]);
        } catch (Throwable) {
            return response()->json([
                'settings' => ['enabled' => false, 'minimumAmount' => 10000],
                'channels' => [],
            ], 200, ['Cache-Control' => 'no-store']);
        }
    }

    public function create(
        Request $request,
        CustomerAuthService $auth,
        SecurityGuard $security,
        PaymentChannelService $channels,
        GatewayPaymentService $gateway,
    ): JsonResponse {
        $security->assertSameOrigin($request);
        $customer = $auth->current($request);
        if (!$customer) {
            return response()->json(['error' => 'Silakan masuk ke akun terlebih dahulu.'], 401);
        }

        $rate = $security->rateLimit($request, 'wallet-topup', 8, 900);
        if (!$rate['allowed']) {
            return response()->json(
                ['error' => 'Terlalu banyak permintaan top up. Coba lagi beberapa menit.'],
                429,
                ['Retry-After' => (string) $rate['retry_after']],
            );
        }

        $referenceId = null;
        $idempotencyKey = '';
        $requestedAmount = null;
        $requestedPaymentMethodKey = '';
        $paymentDispatchStarted = false;

        try {
            $input = $request->validate([
                'amount' => ['required', 'integer', 'min:1000', 'max:100000000'],
                'paymentMethod' => ['required', 'in:va,ewallet,qris'],
                'paymentChannel' => ['required', 'string', 'min:2', 'max:30'],
                'idempotencyKey' => ['nullable', 'uuid'],
            ]);

            $settings = DB::table('wallet_settings')->where('id', 1)->first();
            $enabled = (bool) ($settings?->doku_topup_enabled ?? false);
            $minimum = max(1000, (int) ($settings?->min_topup ?? 10000));
            if (!$enabled) {
                throw new \RuntimeException('Top up saldo otomatis sedang dinonaktifkan.');
            }
            if ((int) $input['amount'] < $minimum) {
                throw new \RuntimeException('Minimum top up Rp'.number_format($minimum, 0, ',', '.').'.');
            }

            $paymentChannel = $input['paymentMethod'] === 'qris' && $input['paymentChannel'] === 'qris'
                ? 'mpm'
                : $input['paymentChannel'];

            $row = DB::table('payment_channels')
                ->where('method', $input['paymentMethod'])
                ->where('channel', $paymentChannel)
                ->where('is_active', 1)
                ->first();
            if (!$row) {
                throw new \RuntimeException('Metode pembayaran ini belum tersedia.');
            }

            $selectedGateway = DB::table('integration_settings')
                ->where('setting_key', 'wallet_topup_gateway')
                ->value('value');
            if (!in_array($selectedGateway, ['doku', 'midtrans'], true)) {
                throw new \RuntimeException('Gateway top up saldo belum dipilih oleh Admin.');
            }

            $gatewayActive = DB::table('payment_gateway_settings')
                ->where('gateway', $selectedGateway)
                ->where('is_active', 1)
                ->exists();
            if (!$gatewayActive) {
                throw new \RuntimeException('Gateway top up saldo sedang dinonaktifkan.');
            }

            $gatewayConfig = $this->topupGatewayConfig($row, $selectedGateway);
            if (!$channels->paymentType(
                $selectedGateway,
                $input['paymentMethod'],
                $paymentChannel,
                $gatewayConfig,
            )) {
                throw new \RuntimeException('Metode pembayaran ini belum didukung gateway top up.');
            }

            $readiness = $gateway->readiness(
                $selectedGateway,
                $input['paymentMethod'],
                $paymentChannel,
                $gatewayConfig,
            );
            if (!$readiness['ready']) {
                throw new \RuntimeException('Gateway top up saldo yang dipilih Admin belum siap.');
            }

            $amount = (int) $input['amount'];
            $fee = $channels->customerFee($amount, $gatewayConfig);
            $total = $amount + $fee;
            $paymentMethodKey = $input['paymentMethod'].':'.$paymentChannel;
            $requestedAmount = $amount;
            $requestedPaymentMethodKey = $paymentMethodKey;

            $headerKey = trim((string) $request->header('idempotency-key', ''));
            $idempotencyKey = (string) ($input['idempotencyKey'] ?? '');
            if ($idempotencyKey === '' && preg_match('/^[0-9a-f-]{36}$/i', $headerKey)) {
                $idempotencyKey = $headerKey;
            }

            if ($idempotencyKey !== '') {
                $existing = $this->findByKey((string) $customer['id'], $idempotencyKey);
                if ($existing) {
                    if ((int) $existing->amount !== $amount || $existing->payment_method !== $paymentMethodKey) {
                        return response()->json([
                            'error' => 'Idempotency key sudah dipakai untuk permintaan top up berbeda.',
                        ], 409);
                    }
                    return $this->existingResponse($existing);
                }
            }

            $pending = $this->findMatching(
                (string) $customer['id'],
                $amount,
                $paymentMethodKey,
                $selectedGateway,
            );
            if ($pending) {
                return $this->existingResponse($pending);
            }

            $referenceId = 'WLT-'.strtoupper(substr(str_replace('-', '', (string) Str::uuid()), 0, 20));
            $topupId = (string) Str::uuid();

            DB::table('wallet_topups')->insert([
                'id' => $topupId,
                'customer_id' => $customer['id'],
                'amount' => $amount,
                'payment_fee' => $fee,
                'payment_total' => $total,
                'sender_name' => $customer['name'],
                'payment_method' => $paymentMethodKey,
                'proof_url' => '',
                'source' => $selectedGateway,
                'reference_id' => $referenceId,
                'external_checkout_key' => $idempotencyKey !== '' ? $idempotencyKey : null,
                'payment_gateway' => $selectedGateway,
                'payment_gateway_mode' => $readiness['mode'],
                'gateway_environment' => $readiness['environment'],
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $baseUrl = rtrim(trim((string) config('lfamilia.public_base_url')), '/');
            if (!preg_match('#^https://[^/]+#i', $baseUrl)) {
                throw new \RuntimeException('PUBLIC_BASE_URL belum dikonfigurasi dengan benar.');
            }

            $paymentDispatchStarted = true;
            $payment = $gateway->create([
                'gateway' => $selectedGateway,
                'referenceId' => $referenceId,
                'amount' => $total,
                'paymentMethod' => $input['paymentMethod'],
                'paymentChannel' => $paymentChannel,
                'gatewayConfig' => $gatewayConfig,
                'buyerName' => $customer['name'],
                'buyerEmail' => $customer['email'],
                'buyerPhone' => $customer['phone'],
                'productName' => 'Top up Saldo LFAMILIA',
                'packageLabel' => 'Saldo akun',
                'finishUrl' => $baseUrl.'/account',
                'publicBaseUrl' => $baseUrl,
            ]);

            DB::table('wallet_topups')->where('id', $topupId)->update([
                'payment_gateway' => $payment['gateway'],
                'payment_gateway_mode' => $payment['mode'],
                'gateway_environment' => $payment['environment'],
                'gateway_request_id' => $payment['requestId'],
                'gateway_reference_no' => $payment['referenceNo'],
                'gateway_payment_no' => $payment['paymentNo'],
                'gateway_qr_content' => $payment['qrContent'],
                'gateway_payment_name' => $payment['paymentName'],
                'gateway_payment_url' => $payment['paymentUrl'],
                'gateway_expired_at' => $payment['expiredAt'],
                'payment_total' => $total,
                'updated_at' => now(),
            ]);

            return response()->json([
                'ok' => true,
                'referenceId' => $referenceId,
                'paymentMethod' => $input['paymentMethod'],
                'paymentChannel' => $paymentChannel,
                'paymentNo' => $payment['paymentNo'],
                'qrContent' => $payment['qrContent'],
                'paymentName' => $this->paymentName($input['paymentMethod'], $paymentChannel),
                'paymentUrl' => $payment['paymentUrl'],
                'total' => $total,
                'fee' => $fee,
                'expiredAt' => $payment['expiredAt'],
            ], 201);
        } catch (QueryException $error) {
            if ($idempotencyKey !== '') {
                $winner = $this->findByKey((string) $customer['id'], $idempotencyKey);
                if ($winner) {
                    if ((int) $winner->amount !== $requestedAmount
                        || $winner->payment_method !== $requestedPaymentMethodKey) {
                        return response()->json([
                            'error' => 'Idempotency key sudah dipakai untuk permintaan top up berbeda.',
                        ], 409);
                    }
                    return $this->existingResponse($winner);
                }
            }

            return response()->json(['error' => 'Permintaan top up sedang diproses. Coba lagi beberapa detik.'], 409);
        } catch (ValidationException $error) {
            return response()->json(['error' => $error->validator->errors()->first()], 400);
        } catch (Throwable $error) {
            if ($referenceId) {
                if ($paymentDispatchStarted) {
                    DB::table('wallet_topups')
                        ->where('reference_id', $referenceId)
                        ->where('status', 'pending')
                        ->update([
                            'admin_notes' => 'Status pembuatan pembayaran belum dapat dipastikan: '.mb_substr($error->getMessage(), 0, 420),
                            'gateway_expired_at' => now()->addMinutes(70),
                            'updated_at' => now(),
                        ]);
                } else {
                    DB::table('wallet_topups')
                        ->where('reference_id', $referenceId)
                        ->where('status', 'pending')
                        ->update([
                            'status' => 'rejected',
                            'admin_notes' => 'Pembuatan pembayaran gagal sebelum dikirim ke gateway: '.mb_substr($error->getMessage(), 0, 420),
                            'updated_at' => now(),
                        ]);
                }
            }

            return response()->json(['error' => $error->getMessage() ?: 'Permintaan top up gagal.'], 503);
        }
    }

    private function topupGatewayConfig(object $row, string $selectedGateway): array
    {
        $decoded = json_decode((string) ($row->gateway_config_json ?? '{}'), true);
        $config = is_array($decoded) ? array_filter(
            $decoded,
            fn ($value, $key) => is_string($key) && is_string($value),
            ARRAY_FILTER_USE_BOTH,
        ) : [];

        $defaults = [
            'customerFeeEnabled' => 'true',
            'customerFeeBps' => '0',
            'customerFeeFixed' => '0',
        ];

        if ((string) $row->gateway === $selectedGateway) {
            return [...$defaults, ...$config];
        }

        return [
            'customerFeeEnabled' => (string) ($config['customerFeeEnabled'] ?? 'true'),
            'customerFeeBps' => (string) ($config['customerFeeBps'] ?? '0'),
            'customerFeeFixed' => (string) ($config['customerFeeFixed'] ?? '0'),
        ];
    }

    private function findByKey(string $customerId, string $key): ?object
    {
        return DB::table('wallet_topups')
            ->where('customer_id', $customerId)
            ->where('external_checkout_key', $key)
            ->whereIn('source', ['doku', 'midtrans'])
            ->first();
    }

    private function findMatching(string $customerId, int $amount, string $method, string $gateway): ?object
    {
        return DB::table('wallet_topups')
            ->where('customer_id', $customerId)
            ->where('amount', $amount)
            ->where('payment_method', $method)
            ->where('payment_gateway', $gateway)
            ->whereIn('source', ['doku', 'midtrans'])
            ->where('status', 'pending')
            ->orderByDesc('created_at')
            ->first();
    }

    private function existingResponse(object $topup): JsonResponse
    {
        if ($topup->status === 'rejected') {
            return response()->json([
                'error' => 'Permintaan top up sebelumnya sudah gagal. Buat permintaan baru dengan idempotency key baru.',
            ], 409);
        }

        $hasInstructions = $topup->gateway_payment_no
            || $topup->gateway_qr_content
            || $topup->gateway_payment_url;
        if ($topup->status === 'pending' && !$hasInstructions) {
            return response()->json([
                'error' => 'Permintaan top up sedang dibuat. Coba lagi beberapa detik.',
            ], 409);
        }

        [$method, $channel] = array_pad(explode(':', (string) $topup->payment_method, 2), 2, '');

        return response()->json([
            'ok' => true,
            'referenceId' => $topup->reference_id,
            'paymentMethod' => $method,
            'paymentChannel' => $channel,
            'paymentNo' => $topup->gateway_payment_no,
            'qrContent' => $topup->gateway_qr_content,
            'paymentName' => $topup->gateway_payment_name ?: $this->paymentName($method, $channel),
            'paymentUrl' => $topup->gateway_payment_url,
            'total' => (int) ($topup->payment_total ?: $topup->amount),
            'fee' => (int) ($topup->payment_fee ?: 0),
            'expiredAt' => $topup->gateway_expired_at,
            'status' => $topup->status,
            'reused' => true,
        ]);
    }

    private function paymentName(string $method, string $channel): string
    {
        return $method === 'qris'
            ? 'QRIS'
            : ($method === 'va' ? 'Virtual Account '.strtoupper($channel) : strtoupper($channel));
    }
}
