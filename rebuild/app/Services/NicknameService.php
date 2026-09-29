<?php

namespace App\Services;

use App\Exceptions\NicknameServiceUnavailable;
use App\Models\IntegrationCredential;
use App\Models\Product;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Throwable;

class NicknameService
{
    private const DEFAULT_BASE_URL = 'https://api.kokinpay.com';

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

    /**
     * @param  array<string, string>  $input
     * @return array{supported:bool,verified:bool,nickname:?string,country:?string,warning:?string}
     */
    public function check(Product $product, array $input): array
    {
        if (! $product->nickname_check_enabled) {
            return $this->unsupported();
        }

        $gameCode = trim((string) $product->nickname_game_code);
        $userField = trim((string) $product->nickname_user_field_key);
        $serverField = trim((string) $product->nickname_server_field_key);
        $userId = trim((string) ($input[$userField] ?? ''));
        $server = $serverField !== '' ? trim((string) ($input[$serverField] ?? '')) : '';

        if ($gameCode === '' || $userField === '') {
            return $this->warning('Nickname tidak berhasil diverifikasi. Silakan periksa kembali data akun sebelum melanjutkan.');
        }
        if (mb_strlen($userId) < 2) {
            throw ValidationException::withMessages([
                'customer_input.'.$userField => 'ID akun belum valid.',
            ]);
        }
        if (in_array(strtolower($gameCode), self::SERVER_REQUIRED, true) && $server === '') {
            throw ValidationException::withMessages([
                'customer_input.'.($serverField ?: 'server') => 'Server / Zone ID wajib diisi.',
            ]);
        }

        $profile = IntegrationCredential::where('code', 'kokinpay')
            ->where('is_active', true)->first();
        $settings = $profile?->config_ciphertext;
        if (! is_array($settings) || empty($settings['api_key'])) {
            return $this->warning('Nickname tidak berhasil diverifikasi karena layanan pengecekan sedang tidak tersedia. Pastikan ID sudah benar.');
        }

        $baseUrl = rtrim((string) ($settings['base_url'] ?? self::DEFAULT_BASE_URL), '/');
        if (! str_starts_with(strtolower($baseUrl), 'https://')) {
            return $this->warning('Nickname tidak berhasil diverifikasi karena layanan pengecekan sedang tidak tersedia. Pastikan ID sudah benar.');
        }

        try {
            $nicknameData = $this->post($baseUrl.'/v1/check-nickname', [
                'api_key' => trim((string) $settings['api_key']),
                'id' => $userId,
                'game_code' => $gameCode,
                ...($server !== '' ? ['server' => $server] : []),
            ], $userField);

            $nickname = $this->firstString([
                data_get($nicknameData, 'data.nickname'),
                data_get($nicknameData, 'data.username'),
            ]);
            if (! $nickname) {
                throw new NicknameServiceUnavailable();
            }

            $country = $this->firstString([
                data_get($nicknameData, 'data.region'),
                data_get($nicknameData, 'data.country'),
            ]);

            if (strtolower($gameCode) === 'mobile-legends') {
                $regionData = $this->post($baseUrl.'/v1/check-region', [
                    'api_key' => trim((string) $settings['api_key']),
                    'id' => $userId,
                    'server' => $server,
                ], $serverField ?: $userField);
                $country = $this->firstString([
                    data_get($regionData, 'data.region'),
                    data_get($regionData, 'data.country'),
                ]);
                if (! $country) {
                    throw new NicknameServiceUnavailable();
                }
            }

            return [
                'supported' => true,
                'verified' => true,
                'nickname' => $nickname,
                'country' => $country,
                'warning' => null,
            ];
        } catch (NicknameServiceUnavailable) {
            return $this->warning('Nickname tidak berhasil diverifikasi karena layanan pengecekan sedang tidak tersedia. Pastikan ID sudah benar.');
        }
    }

    /**
     * @param  array<string, string>  $body
     * @return array<string, mixed>
     */
    private function post(string $url, array $body, string $field): array
    {
        try {
            $response = Http::acceptJson()->timeout(8)->post($url, $body);
        } catch (Throwable) {
            throw new NicknameServiceUnavailable();
        }

        if (in_array($response->status(), [400, 404], true)) {
            throw ValidationException::withMessages([
                'customer_input.'.$field => 'ID, Server, atau kode game tidak valid.',
            ]);
        }
        if (! $response->successful()) {
            throw new NicknameServiceUnavailable();
        }

        $data = $response->json();
        if (! is_array($data) || ($data['status'] ?? null) !== true) {
            throw new NicknameServiceUnavailable();
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

    private function unsupported(): array
    {
        return [
            'supported' => false,
            'verified' => false,
            'nickname' => null,
            'country' => null,
            'warning' => null,
        ];
    }

    private function warning(string $message): array
    {
        return [
            'supported' => true,
            'verified' => false,
            'nickname' => null,
            'country' => null,
            'warning' => $message,
        ];
    }
}
