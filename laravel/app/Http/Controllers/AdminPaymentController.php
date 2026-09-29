<?php

namespace App\Http\Controllers;

use App\Services\AdminAuthService;
use App\Services\GatewayPaymentService;
use App\Services\IntegrationConfigService;
use App\Services\PaymentChannelService;
use App\Services\SecurityGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class AdminPaymentController extends Controller
{
    public function routing(
        Request $request,
        AdminAuthService $auth,
        IntegrationConfigService $integrations,
    ): JsonResponse {
        try {
            $auth->require($request, 'admin');

            return response()->json($integrations->paymentOverview(), 200, [
                'Cache-Control' => 'no-store',
            ]);
        } catch (RuntimeException $error) {
            return $this->accessError($error);
        }
    }

    public function updateRouting(
        Request $request,
        AdminAuthService $auth,
        SecurityGuard $security,
        IntegrationConfigService $integrations,
    ): JsonResponse {
        $security->assertSameOrigin($request);

        try {
            $access = $auth->require($request, 'admin');
            $input = $request->validate([
                'action' => ['required', 'in:save_modes,save_wallet_topup_gateway,save_profile'],
                'dokuEnvironment' => ['nullable', 'in:sandbox,production'],
                'midtransEnvironment' => ['nullable', 'in:sandbox,production'],
                'walletTopupGateway' => ['nullable', 'in:doku,midtrans'],
                'provider' => ['nullable', 'in:doku,midtrans'],
                'mode' => ['nullable', 'in:checkout,snap'],
                'environment' => ['nullable', 'in:sandbox,production'],
                'values' => ['nullable', 'array', 'max:12'],
                'values.*' => ['nullable', 'string', 'max:20000'],
            ]);

            if ($input['action'] === 'save_wallet_topup_gateway') {
                if (empty($input['walletTopupGateway'])) {
                    throw new RuntimeException('Gateway top up saldo wajib dipilih.');
                }
                $integrations->saveSetting('wallet_topup_gateway', $input['walletTopupGateway']);

                return response()->json([
                    'ok' => true,
                    'overview' => $integrations->paymentOverview(),
                ]);
            }

            if ($access['role'] !== 'super_admin') {
                throw new RuntimeException('Akses panel tidak diizinkan.');
            }

            if ($input['action'] === 'save_modes') {
                if (empty($input['dokuEnvironment']) || empty($input['midtransEnvironment'])) {
                    throw new RuntimeException('Environment payment gateway wajib lengkap.');
                }

                $integrations->saveSetting('doku_environment', $input['dokuEnvironment']);
                $integrations->saveSetting('midtrans_environment', $input['midtransEnvironment']);
            } else {
                $provider = (string) ($input['provider'] ?? '');
                $mode = (string) ($input['mode'] ?? '');
                $environment = (string) ($input['environment'] ?? '');

                if (($provider === 'doku' && $mode !== 'checkout')
                    || ($provider === 'midtrans' && $mode !== 'snap')) {
                    throw new RuntimeException('Kombinasi gateway dan mode tidak valid.');
                }

                $values = [];
                foreach (($input['values'] ?? []) as $key => $value) {
                    if (is_string($key) && is_string($value)) {
                        $values[$key] = $value;
                    }
                }

                $integrations->savePaymentProfile($provider, $environment, $values);
            }

            return response()->json([
                'ok' => true,
                'overview' => $integrations->paymentOverview(),
            ]);
        } catch (ValidationException $error) {
            return response()->json(['error' => $error->validator->errors()->first()], 400);
        } catch (RuntimeException $error) {
            if ($this->isAccessError($error)) {
                return $this->accessError($error);
            }
            return response()->json(['error' => $error->getMessage()], 400);
        } catch (Throwable $error) {
            return response()->json([
                'error' => $error->getMessage() ?: 'Routing pembayaran gagal disimpan.',
            ], 400);
        }
    }

    public function methods(
        Request $request,
        AdminAuthService $auth,
        IntegrationConfigService $integrations,
        GatewayPaymentService $gateway,
        PaymentChannelService $channelService,
    ): JsonResponse {
        try {
            $auth->require($request, 'admin');

            $gatewaySettings = $this->gatewaySettings();
            $channels = DB::table('payment_channels')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->map(function ($row) use ($gateway, $channelService): array {
                    $config = $this->decodeConfig((string) $row->gateway_config_json);
                    $config = [
                        'customerFeeEnabled' => 'true',
                        'customerFeeBps' => '0',
                        'customerFeeFixed' => '0',
                        ...$config,
                    ];
                    $mode = strtolower(trim((string) ($config['customerFeeMode'] ?? '')));
                    if (!in_array($mode, ['percent', 'fixed'], true)) {
                        $mode = (int) ($config['customerFeeBps'] ?? 0) > 0 ? 'percent' : 'fixed';
                    }
                    $config['customerFeeMode'] = $mode;
                    if ($mode === 'percent') {
                        $config['customerFeeFixed'] = '0';
                    } else {
                        $config['customerFeeBps'] = '0';
                    }

                    $readiness = $gateway->readiness(
                        (string) $row->gateway,
                        (string) $row->method,
                        (string) $row->channel,
                        $config,
                    );
                    $supported = $channelService->paymentType(
                        (string) $row->gateway,
                        (string) $row->method,
                        (string) $row->channel,
                        $config,
                    ) !== null;

                    return [
                        'id' => (int) $row->id,
                        'method' => (string) $row->method,
                        'channel' => (string) $row->channel,
                        'name' => (string) $row->name,
                        'description' => (string) $row->description,
                        'imageUrl' => $row->image_url ?: null,
                        'isActive' => (bool) $row->is_active,
                        'sortOrder' => (int) $row->sort_order,
                        'gateway' => (string) $row->gateway,
                        'gatewayConfig' => $config,
                        'readiness' => [
                            ...$readiness,
                            'ready' => $readiness['ready'] && $supported,
                            'reason' => $supported
                                ? $readiness['reason']
                                : 'Channel belum memiliki kode gateway yang didukung.',
                        ],
                    ];
                })
                ->all();

            $overview = $integrations->paymentOverview();

            return response()->json([
                'channels' => $channels,
                'gatewaySettings' => $gatewaySettings,
                'gateways' => array_values(array_map(
                    fn ($row) => $row['gateway'],
                    array_filter($gatewaySettings, fn ($row) => $row['isActive']),
                )),
                'gatewayReadiness' => [
                    'doku' => [
                        'ready' => (bool) $overview['dokuCheckoutConfigured'],
                        'environment' => $overview['dokuEnvironment'],
                        'mode' => 'checkout',
                        'reason' => $overview['dokuCheckoutConfigured']
                            ? null : 'Kredensial DOKU Checkout belum lengkap.',
                    ],
                    'midtrans' => [
                        'ready' => (bool) $overview['midtransSnapConfigured'],
                        'environment' => $overview['midtransEnvironment'],
                        'mode' => 'snap',
                        'reason' => $overview['midtransSnapConfigured']
                            ? null : 'Kredensial Midtrans Snap belum lengkap.',
                    ],
                ],
            ], 200, ['Cache-Control' => 'no-store']);
        } catch (RuntimeException $error) {
            return $this->accessError($error);
        }
    }

    public function saveMethod(
        Request $request,
        AdminAuthService $auth,
        SecurityGuard $security,
        PaymentChannelService $channels,
    ): JsonResponse {
        $security->assertSameOrigin($request);

        try {
            $auth->require($request, 'admin');
            $raw = $request->all();

            if (($raw['action'] ?? null) === 'gateway_status') {
                $input = $request->validate([
                    'gateway' => ['required', 'in:doku,midtrans'],
                    'enabled' => ['required', 'boolean'],
                ]);

                DB::table('payment_gateway_settings')->updateOrInsert(
                    ['gateway' => $input['gateway']],
                    [
                        'is_active' => $input['enabled'] ? 1 : 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );

                return response()->json([
                    'ok' => true,
                    'gatewaySettings' => $this->gatewaySettings(),
                ]);
            }

            if (($raw['action'] ?? null) === 'sync') {
                $input = $request->validate([
                    'gateways' => ['nullable', 'array', 'max:2'],
                    'gateways.*' => ['in:doku,midtrans'],
                ]);

                $selected = array_values(array_unique($input['gateways'] ?? ['midtrans', 'doku']));
                if ($selected === []) {
                    throw new RuntimeException('Pilih minimal satu gateway untuk sinkronisasi.');
                }

                $synced = $this->syncBuiltInChannels($selected);

                return response()->json([
                    'ok' => true,
                    'gateways' => $selected,
                    'mode' => 'admin-routed',
                    'synced' => $synced,
                    'activationPolicy' => 'manual',
                ]);
            }

            $input = $request->validate([
                'id' => ['nullable', 'integer', 'min:1'],
                'method' => ['required', 'in:va,ewallet,qris'],
                'channel' => ['required', 'regex:/^[a-z0-9_]+$/', 'max:30'],
                'name' => ['required', 'string', 'min:2', 'max:80'],
                'description' => ['nullable', 'string', 'max:160'],
                'imageUrl' => ['nullable', 'string', 'max:500'],
                'isActive' => ['required', 'boolean'],
                'sortOrder' => ['required', 'integer', 'min:0', 'max:10000'],
                'gateway' => ['required', 'in:doku,midtrans'],
                'gatewayConfig' => ['nullable', 'array', 'max:20'],
                'gatewayConfig.*' => ['nullable', 'string', 'max:500'],
            ]);

            $config = [];
            foreach (($input['gatewayConfig'] ?? []) as $key => $value) {
                if (is_string($key) && is_string($value)) {
                    $config[$key] = $value;
                }
            }
            $mode = strtolower(trim((string) ($config['customerFeeMode'] ?? 'fixed')));
            if (!in_array($mode, ['percent', 'fixed'], true)) {
                throw new RuntimeException('Tipe biaya customer harus Persentase atau Nominal Tetap.');
            }
            $config['customerFeeMode'] = $mode;
            if ($mode === 'percent') {
                $config['customerFeeFixed'] = '0';
            } else {
                $config['customerFeeBps'] = '0';
            }
            $this->validateFeeConfig($config);

            if ($input['isActive']
                && $channels->paymentType(
                    $input['gateway'],
                    $input['method'],
                    $input['channel'],
                    $config,
                ) === null) {
                throw new RuntimeException('Metode tersebut belum memiliki kode pembayaran yang valid untuk gateway yang dipilih.');
            }

            $imageUrl = trim((string) ($input['imageUrl'] ?? ''));
            if ($imageUrl !== '' && !$this->validMediaUrl($imageUrl)) {
                throw new RuntimeException('URL gambar tidak valid.');
            }

            $values = [
                'method' => $input['method'],
                'channel' => $input['channel'],
                'name' => trim($input['name']),
                'description' => trim((string) ($input['description'] ?? '')),
                'image_url' => $imageUrl !== '' ? $imageUrl : null,
                'is_active' => $input['isActive'] ? 1 : 0,
                'sort_order' => (int) $input['sortOrder'],
                'gateway' => $input['gateway'],
                'gateway_config_json' => json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ];

            if (!empty($input['id'])) {
                DB::table('payment_channels')->where('id', $input['id'])->update($values);
                $id = (int) $input['id'];
            } else {
                $existing = DB::table('payment_channels')
                    ->where('method', $input['method'])
                    ->where('channel', $input['channel'])
                    ->first(['id']);

                if ($existing) {
                    DB::table('payment_channels')->where('id', $existing->id)->update($values);
                    $id = (int) $existing->id;
                } else {
                    $id = (int) DB::table('payment_channels')->insertGetId([
                        ...$values,
                        'created_at' => now(),
                    ]);
                }
            }

            return response()->json(['ok' => true, 'id' => $id]);
        } catch (ValidationException $error) {
            return response()->json(['error' => $error->validator->errors()->first()], 400);
        } catch (RuntimeException $error) {
            if ($this->isAccessError($error)) {
                return $this->accessError($error);
            }
            return response()->json(['error' => $error->getMessage()], 400);
        } catch (Throwable $error) {
            return response()->json([
                'error' => $error->getMessage() ?: 'Metode pembayaran gagal disimpan.',
            ], 400);
        }
    }

    public function deleteMethod(
        Request $request,
        AdminAuthService $auth,
        SecurityGuard $security,
    ): JsonResponse {
        $security->assertSameOrigin($request);

        try {
            $auth->require($request, 'admin');
            $id = (int) $request->query('id', 0);
            if ($id < 1) {
                throw new RuntimeException('Metode pembayaran tidak valid.');
            }

            DB::table('payment_channels')->where('id', $id)->delete();

            return response()->json(['ok' => true]);
        } catch (RuntimeException $error) {
            if ($this->isAccessError($error)) {
                return $this->accessError($error);
            }
            return response()->json(['error' => $error->getMessage()], 400);
        }
    }

    /** @return array<int,array{gateway:string,isActive:bool}> */
    private function gatewaySettings(): array
    {
        $rows = DB::table('payment_gateway_settings')
            ->whereIn('gateway', ['doku', 'midtrans'])
            ->get(['gateway', 'is_active'])
            ->keyBy('gateway');

        return array_map(fn ($gateway) => [
            'gateway' => $gateway,
            'isActive' => (bool) ($rows->get($gateway)?->is_active ?? false),
        ], ['doku', 'midtrans']);
    }

    /** @param array<string,string> $config */
    private function validateFeeConfig(array $config): void
    {
        if (isset($config['customerFeeMode'])
            && !in_array(strtolower(trim($config['customerFeeMode'])), ['percent', 'fixed'], true)) {
            throw new RuntimeException('Tipe biaya customer tidak valid.');
        }

        if (isset($config['customerFeeEnabled'])
            && !preg_match('/^(?:true|false|1|0)$/i', $config['customerFeeEnabled'])) {
            throw new RuntimeException('Status biaya customer tidak valid.');
        }

        if (isset($config['customerFeeBps'])
            && (!preg_match('/^(?:0|[1-9]\d{0,3})$/', $config['customerFeeBps'])
                || (int) $config['customerFeeBps'] >= 10000)) {
            throw new RuntimeException('Biaya persentase customer harus antara 0 sampai 99,99%.');
        }

        if (isset($config['customerFeeFixed'])
            && (!preg_match('/^(?:0|[1-9]\d{0,8})$/', $config['customerFeeFixed'])
                || (int) $config['customerFeeFixed'] > 100000000)) {
            throw new RuntimeException('Biaya tetap customer harus antara Rp0 sampai Rp100.000.000.');
        }
    }

    private function validMediaUrl(string $value): bool
    {
        if (str_starts_with($value, '/')) {
            return true;
        }

        $parts = parse_url($value);

        return is_array($parts)
            && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['https', 'http'], true)
            && !empty($parts['host']);
    }

    /** @param list<string> $gateways */
    private function syncBuiltInChannels(array $gateways): int
    {
        $builtIn = [
            ['va','bca','BCA','Virtual Account BCA','midtrans'],
            ['va','mandiri','Mandiri','Virtual Account Mandiri','midtrans'],
            ['va','bni','BNI','Virtual Account BNI','midtrans'],
            ['va','bri','BRI','Virtual Account BRI','midtrans'],
            ['va','cimb','CIMB Niaga','Virtual Account CIMB Niaga','midtrans'],
            ['va','permata','Permata','Virtual Account Permata','midtrans'],
            ['va','danamon','Danamon','Virtual Account Danamon','midtrans'],
            ['ewallet','gopay','GoPay','Bayar melalui GoPay','midtrans'],
            ['ewallet','ovo','OVO','Bayar melalui OVO','midtrans'],
            ['ewallet','dana','DANA','Bayar melalui aplikasi DANA','midtrans'],
            ['ewallet','shopeepay','ShopeePay','Bayar melalui aplikasi ShopeePay','midtrans'],
            ['qris','mpm','QRIS','Scan dari aplikasi bank atau e-wallet','doku'],
        ];

        $synced = 0;
        foreach ($builtIn as $index => [$method, $channel, $name, $description, $gateway]) {
            if (!in_array($gateway, $gateways, true)) {
                continue;
            }

            $existing = DB::table('payment_channels')
                ->where('method', $method)
                ->where('channel', $channel)
                ->first(['id']);

            if ($existing) {
                DB::table('payment_channels')->where('id', $existing->id)->update([
                    'name' => $name,
                    'description' => $description,
                    'gateway' => $gateway,
                    'sort_order' => $index,
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('payment_channels')->insert([
                    'method' => $method,
                    'channel' => $channel,
                    'name' => $name,
                    'description' => $description,
                    'image_url' => null,
                    'is_active' => 0,
                    'sort_order' => $index,
                    'gateway' => $gateway,
                    'gateway_config_json' => '{}',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $synced++;
        }

        return $synced;
    }

    /** @return array<string,string> */
    private function decodeConfig(string $json): array
    {
        $decoded = json_decode($json ?: '{}', true);
        if (!is_array($decoded)) {
            return [];
        }

        $result = [];
        foreach ($decoded as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    private function isAccessError(RuntimeException $error): bool
    {
        return str_contains($error->getMessage(), 'Sesi panel')
            || str_contains($error->getMessage(), 'Akses panel');
    }

    private function accessError(RuntimeException $error): JsonResponse
    {
        return response()->json(
            ['error' => $error->getMessage()],
            str_contains($error->getMessage(), 'Sesi panel') ? 401 : 403,
            ['Cache-Control' => 'no-store'],
        );
    }
}
