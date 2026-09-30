<?php

namespace App\Services;

use App\Models\IntegrationCredential;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class AdminNicknameToolsService
{
    private const DEFAULT_BASE_URL = 'https://api.kokinpay.com';

    /** @var array<int, array{0:string,1:string,2:bool}> */
    private const GAME_CODES = [
        ['Mobile Legends', 'mobile-legends', true],
        ['Free Fire', 'free-fire', false],
        ['PUBG Mobile', 'pubg-mobile', false],
        ['Call of Duty Mobile', 'call-of-duty-mobile', false],
        ['Valorant', 'valorant', false],
        ['Genshin Impact', 'genshin-impact', true],
        ['Honor of Kings', 'honor-of-kings', false],
        ['League of Legends: Wild Rift', 'league-of-legends-wild-rift', false],
        ['Arena of Valor', 'arena-of-valor', false],
        ['Point Blank', 'point-blank', false],
        ['Free Fire Max', 'free-fire-max', false],
        ['Whiteout Survival', 'whiteout-survival', false],
        ['Honkai Impact 3', 'honkai-impact-3', false],
        ['Honkai: Star Rail', 'honkai-star-rail', true],
        ['Eggy Party', 'eggy-party', true],
        ['Undawn', 'undawn', false],
        ['Growtopia', 'growtopia', false],
        ['League of Legends PC', 'league-of-legends-pc', false],
        ['FC Mobile', 'fc-mobile', false],
        ['Super Sus', 'super-sus', false],
        ['Harry Potter: Magic Awakened', 'harry-potter-magic-awakened', true],
        ['Revelation: Infinite Journey', 'revelation-infinite-journey', false],
        ['MU Origin 3', 'mu-origin-3', false],
        ['Sausage Man', 'sausage-man', false],
        ['Speed Drifters', 'speed-drifters', false],
        ['Tom and Jerry: Chase', 'tom-and-jerry-chase', true],
        ['Teamfight Tactics Mobile', 'teamfight-tactics-mobile', false],
        ['LifeAfter', 'lifeafter', true],
        ['Laplace M', 'laplace-m', false],
        ['Arena Breakout', 'arena-breakout', false],
        ['Zenless Zone Zero', 'zenless-zone-zero', true],
        ['AFK Journey', 'afk-journey', false],
        ['Magic Chess Go Go', 'magic-chess-go-go', true],
        ['Love and Deepspace', 'love-and-deepspace', false],
        ['Pokemon Unite', 'pokemon-unite', false],
        ['Dragon Raja', 'dragon-raja', false],
        ['Football Master 2', 'football-master-2', false],
        ['Garena Shell', 'garena-shell', false],
        ['Goddess of Victory: Nikke', 'goddess-of-victory-nikke', true],
        ['Metal Slug: Awakening', 'metal-slug-awakening', false],
        ['Ragnarok M: Eternal Love', 'ragnarok-m-eternal-love', true],
    ];

    /** @return array<int, array{name:string,code:string,requires_server:bool}> */
    public function gameCodes(): array
    {
        return array_map(fn (array $item): array => [
            'name' => $item[0],
            'code' => $item[1],
            'requires_server' => $item[2],
        ], self::GAME_CODES);
    }

    /** @return array{nickname:string,region:?string} */
    public function checkGame(string $gameCode, string $userId, ?string $server = null): array
    {
        $settings = $this->settings();
        $nickname = $this->nickname($settings, $gameCode, $userId, $server);

        if ($gameCode === 'mobile-legends') {
            $region = $this->region($settings, $userId, (string) $server);
            if ($region === null) {
                throw new RuntimeException('Validasi Mobile Legends tidak mengembalikan region.');
            }
            $nickname['region'] = $region;
        }

        return $nickname;
    }

    /** @return array{nickname:string,region:string} */
    public function checkRegion(string $userId, string $server): array
    {
        $settings = $this->settings();
        $nickname = $this->nickname($settings, 'mobile-legends', $userId, $server);
        $region = $this->region($settings, $userId, $server);
        if ($region === null) {
            throw new RuntimeException('Validasi Mobile Legends tidak mengembalikan region.');
        }

        return ['nickname' => $nickname['nickname'], 'region' => $region];
    }

    public function checkPln(string $customerNumber): string
    {
        $settings = $this->settings();
        $payload = $this->post($settings, '/v1/check-pln', [
            'customer_number' => $customerNumber,
        ]);
        $name = $this->firstString([
            data_get($payload, 'data.customer_name'),
            data_get($payload, 'data.name'),
        ]);
        if (! $name) {
            throw new RuntimeException('Layanan PLN tidak mengembalikan nama pelanggan.');
        }

        return $name;
    }

    /** @return array<string,mixed> */
    private function settings(): array
    {
        $profile = IntegrationCredential::where('code', 'kokinpay')
            ->where('is_active', true)->first();
        $settings = $profile?->config_ciphertext;
        if (! is_array($settings) || empty($settings['api_key'])) {
            throw new RuntimeException('API Key layanan Validasi Akun belum disimpan atau integrasi sedang nonaktif.');
        }
        $base = rtrim((string) ($settings['base_url'] ?? self::DEFAULT_BASE_URL), '/');
        if (! str_starts_with(strtolower($base), 'https://')) {
            throw new RuntimeException('Base URL integrasi Validasi Akun harus menggunakan HTTPS.');
        }
        $settings['base_url'] = $base;

        return $settings;
    }

    /** @return array{nickname:string,region:?string} */
    private function nickname(array $settings, string $gameCode, string $userId, ?string $server): array
    {
        $payload = $this->post($settings, '/v1/check-nickname', [
            'id' => $userId,
            'game_code' => $gameCode,
            ...($server ? ['server' => $server] : []),
        ]);
        $nickname = $this->firstString([
            data_get($payload, 'data.nickname'),
            data_get($payload, 'data.username'),
        ]);
        if (! $nickname) {
            throw new RuntimeException('Layanan validasi tidak mengembalikan nickname.');
        }

        return [
            'nickname' => $nickname,
            'region' => $this->firstString([
                data_get($payload, 'data.region'),
                data_get($payload, 'data.country'),
            ]),
        ];
    }

    private function region(array $settings, string $userId, string $server): ?string
    {
        $payload = $this->post($settings, '/v1/check-region', [
            'id' => $userId,
            'server' => $server,
        ]);

        return $this->firstString([
            data_get($payload, 'data.region'),
            data_get($payload, 'data.country'),
        ]);
    }

    /** @return array<string,mixed> */
    private function post(array $settings, string $path, array $body): array
    {
        try {
            $response = Http::acceptJson()->timeout(8)->post($settings['base_url'].$path, [
                'api_key' => trim((string) $settings['api_key']),
                ...$body,
            ]);
        } catch (Throwable) {
            throw new RuntimeException('Layanan validasi tidak dapat dihubungi.');
        }

        $payload = $response->json();
        $message = is_array($payload) && is_string($payload['message'] ?? null)
            ? trim((string) $payload['message']) : null;

        if (in_array($response->status(), [400, 404, 422], true)) {
            throw ValidationException::withMessages([
                'lookup' => $message ?: 'Data tidak ditemukan atau tidak valid.',
            ]);
        }
        if (in_array($response->status(), [401, 403], true)) {
            throw new RuntimeException($message ?: 'API Key layanan Validasi Akun ditolak.');
        }
        if (! $response->successful() || ! is_array($payload) || ($payload['status'] ?? null) !== true) {
            throw new RuntimeException($message ?: 'Layanan validasi sedang tidak tersedia.');
        }

        return $payload;
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
