<?php

namespace App\Services;

use App\Exceptions\NicknameServiceException;
use App\Exceptions\NicknameValidationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

class NicknameService
{
    private const SERVER_REQUIRED = [
        'mobile-legends',
        'genshin-impact',
        'honkai-star-rail',
        'eggy-party',
        'harry-potter-magic-awakened',
        'tom-and-jerry-chase',
        'lifeafter',
        'zenless-zone-zero',
        'magic-chess-go-go',
        'goddess-of-victory-nikke',
        'ragnarok-m-eternal-love',
    ];

    public function __construct(private readonly IntegrationConfigService $integrations)
    {
    }

    /** @return array{supported:bool,nickname:?string,country:?string} */
    public function verifyForCheckout(string $productSlug, string $userId, ?string $server): array
    {
        $product = DB::table('products')
            ->where('slug', trim($productSlug))
            ->where('is_active', 1)
            ->first(['nickname_game_code', 'needs_server', 'category']);

        if ($product && strtolower(trim((string) $product->category)) === 'voucher') {
            return ['supported' => false, 'nickname' => null, 'country' => null];
        }

        $gameCode = trim((string) ($product?->nickname_game_code ?? ''));
        if ($gameCode === '') {
            return ['supported' => false, 'nickname' => null, 'country' => null];
        }

        $server = trim((string) $server);
        if (((bool) ($product?->needs_server ?? false) || in_array(strtolower($gameCode), self::SERVER_REQUIRED, true))
            && $server === '') {
            throw new NicknameValidationException('Server / Zone ID wajib diisi.');
        }

        $runtime = $this->integrations->kokinpayRuntime();
        $apiKey = $runtime['apiKey'];
        $baseUrl = $runtime['baseUrl'];
        if ($apiKey === '' || $baseUrl === '') {
            throw new NicknameServiceException(
                'Verifikasi akun belum terhubung dengan benar. Checkout sementara tidak dapat dilanjutkan.',
            );
        }

        return $this->lookup($apiKey, $baseUrl, $gameCode, $userId, $server !== '' ? $server : null);
    }

    /** @return array{supported:bool,nickname:?string,country:?string} */
    private function lookup(string $apiKey, string $baseUrl, string $gameCode, string $userId, ?string $server): array
    {
        $userId = trim($userId);
        if (strlen($userId) < 2) {
            throw new NicknameValidationException('ID akun belum valid.');
        }

        if (!str_starts_with(strtolower($baseUrl), 'https://')) {
            throw new NicknameServiceException('URL layanan verifikasi akun belum dikonfigurasi dengan benar.');
        }

        $body = [
            'api_key' => $apiKey,
            'id' => $userId,
            'game_code' => $gameCode,
        ];
        if ($server) {
            $body['server'] = $server;
        }

        $nicknameData = $this->post($baseUrl.'/v1/check-nickname', $body);

        if ($gameCode === 'mobile-legends') {
            if (!$server) {
                throw new NicknameValidationException('Server / Zone ID wajib diisi.');
            }

            $regionData = $this->post($baseUrl.'/v1/check-region', [
                'api_key' => $apiKey,
                'id' => $userId,
                'server' => $server,
            ]);

            $nickname = $this->firstString([
                data_get($nicknameData, 'data.nickname'),
                data_get($nicknameData, 'data.username'),
            ]);
            $region = $this->firstString([
                data_get($regionData, 'data.region'),
                data_get($regionData, 'data.country'),
            ]);

            if (!$nickname) {
                throw new NicknameServiceException('Layanan pengecekan tidak mengembalikan nickname Mobile Legends.');
            }
            if (!$region) {
                throw new NicknameServiceException('Layanan pengecekan tidak mengembalikan region Mobile Legends.');
            }

            return ['supported' => true, 'nickname' => $nickname, 'country' => $region];
        }

        $nickname = $this->firstString([
            data_get($nicknameData, 'data.nickname'),
            data_get($nicknameData, 'data.username'),
        ]);
        if (!$nickname) {
            throw new NicknameServiceException('Layanan pengecekan tidak mengembalikan nickname. Coba lagi beberapa saat.');
        }

        return [
            'supported' => true,
            'nickname' => $nickname,
            'country' => $this->firstString([
                data_get($nicknameData, 'data.region'),
                data_get($nicknameData, 'data.country'),
            ]),
        ];
    }

    /** @return array<string,mixed> */
    private function post(string $url, array $body): array
    {
        try {
            $response = Http::acceptJson()->timeout(8)->post($url, $body);
        } catch (Throwable) {
            throw new NicknameServiceException('Verifikasi akun sedang tidak tersedia. Coba lagi beberapa saat.');
        }

        $status = $response->status();
        $data = $response->json();
        if (!$response->successful() || !is_array($data) || ($data['status'] ?? null) !== true) {
            if (in_array($status, [400, 404], true)) {
                throw new NicknameValidationException('ID, Server, atau kode game tidak valid.');
            }
            if (in_array($status, [401, 403], true)) {
                throw new NicknameServiceException('Layanan verifikasi akun belum terautentikasi dengan benar.');
            }

            throw new NicknameServiceException('Verifikasi akun sedang tidak tersedia. Coba lagi beberapa saat.');
        }

        return $data;
    }

    private function firstString(array $values): ?string
    {
        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }
}
