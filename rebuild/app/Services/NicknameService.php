<?php

namespace App\Services;

use App\Exceptions\NicknameServiceUnavailable;
use App\Models\NicknameGameCode;
use App\Models\Product;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Throwable;

class NicknameService
{
    public function __construct(
        private readonly AccountValidationConfig $config,
    ) {}

    /**
     * @param  array<string, string>  $input
     * @return array{supported:bool,verified:bool,nickname:?string,country:?string,warning:?string}
     */
    public function check(Product $product, array $input): array
    {
        if ($product->relationLoaded('category') && strtolower((string) $product->category?->slug) === 'voucher') {
            return $this->unsupported();
        }

        if (! $product->nickname_check_enabled) {
            return $this->unsupported();
        }

        $gameCode = strtolower(trim((string) $product->nickname_game_code));
        $userField = trim((string) $product->nickname_user_field_key);
        $serverField = trim((string) $product->nickname_server_field_key);
        $userId = trim((string) ($input[$userField] ?? ''));
        $server = $serverField !== '' ? trim((string) ($input[$serverField] ?? '')) : '';

        if ($gameCode === '' || $userField === '') {
            return $this->unsupported();
        }

        $game = NicknameGameCode::active()->where('code', $gameCode)->first();
        if (! $game || ! $game->supports_nickname_check) {
            return $this->unsupported();
        }

        if (mb_strlen($userId) < 2) {
            throw ValidationException::withMessages([
                'customer_input.'.$userField => 'ID akun belum valid.',
            ]);
        }
        if ($game->requires_server && $server === '') {
            throw ValidationException::withMessages([
                'customer_input.'.($serverField ?: 'server') => 'Server / Zone ID wajib diisi.',
            ]);
        }

        $settings = $this->config->active();
        if (! $settings) {
            return $this->warning('Nickname tidak berhasil diverifikasi karena layanan pengecekan sedang tidak tersedia. Pastikan ID sudah benar.');
        }

        try {
            $nicknameData = $this->post(
                $this->config->endpoint($settings, 'nickname_path'),
                [
                    'api_key' => $settings['api_key'],
                    'id' => $userId,
                    'game_code' => $game->code,
                    ...($server !== '' ? ['server' => $server] : []),
                ],
                $userField,
            );

            $nickname = $this->firstString([
                data_get($nicknameData, 'data.nickname'),
                data_get($nicknameData, 'data.username'),
            ]);
            if (! $nickname) {
                throw ValidationException::withMessages([
                    'customer_input.'.$userField => 'Nickname tidak ditemukan. Periksa kembali data akun.',
                ]);
            }

            $country = $this->firstString([
                data_get($nicknameData, 'data.region'),
                data_get($nicknameData, 'data.country'),
            ]);

            if ($game->requires_region_check) {
                if ($server === '') {
                    throw ValidationException::withMessages([
                        'customer_input.'.($serverField ?: 'server') => 'Server / Zone ID wajib diisi untuk pemeriksaan region.',
                    ]);
                }

                $regionData = $this->post(
                    $this->config->endpoint($settings, 'region_path'),
                    [
                        'api_key' => $settings['api_key'],
                        'id' => $userId,
                        'server' => $server,
                    ],
                    $serverField ?: $userField,
                );
                $country = $this->firstString([
                    data_get($regionData, 'data.region'),
                    data_get($regionData, 'data.country'),
                ]);
                if (! $country) {
                    throw ValidationException::withMessages([
                        'customer_input.'.($serverField ?: $userField) => 'Region akun tidak ditemukan. Periksa kembali Server / Zone.',
                    ]);
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
            throw new NicknameServiceUnavailable;
        }

        if (in_array($response->status(), [400, 404, 422], true)) {
            throw ValidationException::withMessages([
                'customer_input.'.$field => 'ID, Server, atau kode game tidak valid.',
            ]);
        }
        if (! $response->successful()) {
            throw new NicknameServiceUnavailable;
        }

        $data = $response->json();
        if (! is_array($data) || ($data['status'] ?? null) !== true) {
            throw new NicknameServiceUnavailable;
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
