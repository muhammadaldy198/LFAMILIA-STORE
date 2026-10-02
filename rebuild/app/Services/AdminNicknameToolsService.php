<?php

namespace App\Services;

use App\Models\NicknameGameCode;
use App\Models\Product;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class AdminNicknameToolsService
{
    public function __construct(
        private readonly AccountValidationConfig $config,
    ) {}

    /**
     * @return array<int, array{id:int,name:string,code:string,requires_server:bool,requires_region_check:bool,is_active:bool,sort_order:int,product_count:int}>
     */
    public function gameCodes(): array
    {
        $usage = Product::query()
            ->whereNotNull('nickname_game_code')
            ->selectRaw('nickname_game_code, COUNT(*) as aggregate')
            ->groupBy('nickname_game_code')
            ->pluck('aggregate', 'nickname_game_code');

        return NicknameGameCode::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (NicknameGameCode $item): array => [
                'id' => (int) $item->id,
                'name' => $item->name,
                'code' => $item->code,
                'requires_server' => (bool) $item->requires_server,
                'requires_region_check' => (bool) $item->requires_region_check,
                'is_active' => (bool) $item->is_active,
                'sort_order' => (int) $item->sort_order,
                'product_count' => (int) ($usage[$item->code] ?? 0),
            ])->all();
    }

    /** @return array{nickname:string,region:?string} */
    public function checkGame(string $gameCode, string $userId, ?string $server = null): array
    {
        $game = $this->activeGame($gameCode);
        if ($game->requires_server && blank($server)) {
            throw ValidationException::withMessages([
                'server' => 'Server / Zone wajib diisi untuk game ini.',
            ]);
        }

        $settings = $this->settings();
        $nickname = $this->nickname($settings, $game->code, $userId, $server);

        if ($game->requires_region_check) {
            if (blank($server)) {
                throw ValidationException::withMessages([
                    'server' => 'Server / Zone wajib diisi untuk pemeriksaan region.',
                ]);
            }

            $region = $this->region($settings, $userId, (string) $server);
            if ($region === null) {
                throw new RuntimeException('Layanan validasi tidak mengembalikan region.');
            }
            $nickname['region'] = $region;
        }

        return $nickname;
    }

    /** @return array{nickname:string,region:string} */
    public function checkRegion(string $gameCode, string $userId, string $server): array
    {
        $game = $this->activeGame($gameCode);
        if (! $game->requires_region_check) {
            throw ValidationException::withMessages([
                'game_code' => 'Game ini tidak dikonfigurasi untuk pemeriksaan region.',
            ]);
        }

        $settings = $this->settings();
        $nickname = $this->nickname($settings, $game->code, $userId, $server);
        $region = $this->region($settings, $userId, $server);
        if ($region === null) {
            throw new RuntimeException('Layanan validasi tidak mengembalikan region.');
        }

        return ['nickname' => $nickname['nickname'], 'region' => $region];
    }

    public function checkPln(string $customerNumber): string
    {
        $settings = $this->settings();
        $payload = $this->post(
            $this->config->endpoint($settings, 'pln_path'),
            $settings['api_key'],
            ['customer_number' => $customerNumber],
        );
        $name = $this->firstString([
            data_get($payload, 'data.customer_name'),
            data_get($payload, 'data.name'),
        ]);
        if (! $name) {
            throw new RuntimeException('Layanan PLN tidak mengembalikan nama pelanggan.');
        }

        return $name;
    }

    /** @return array{api_key:string,base_url:string,nickname_path:string,region_path:string,pln_path:string} */
    private function settings(): array
    {
        $settings = $this->config->active();
        if (! $settings) {
            throw new RuntimeException('Konfigurasi Validasi Akun belum lengkap atau sedang nonaktif.');
        }

        return $settings;
    }

    private function activeGame(string $gameCode): NicknameGameCode
    {
        $game = NicknameGameCode::active()
            ->where('code', strtolower(trim($gameCode)))
            ->first();

        if (! $game) {
            throw ValidationException::withMessages([
                'game_code' => 'Kode game tidak tersedia atau sedang dinonaktifkan.',
            ]);
        }

        return $game;
    }

    /** @return array{nickname:string,region:?string} */
    private function nickname(array $settings, string $gameCode, string $userId, ?string $server): array
    {
        $payload = $this->post(
            $this->config->endpoint($settings, 'nickname_path'),
            $settings['api_key'],
            [
                'id' => $userId,
                'game_code' => $gameCode,
                ...($server ? ['server' => $server] : []),
            ],
        );
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
        $payload = $this->post(
            $this->config->endpoint($settings, 'region_path'),
            $settings['api_key'],
            ['id' => $userId, 'server' => $server],
        );

        return $this->firstString([
            data_get($payload, 'data.region'),
            data_get($payload, 'data.country'),
        ]);
    }

    /** @return array<string,mixed> */
    private function post(string $url, string $apiKey, array $body): array
    {
        try {
            $response = Http::acceptJson()->timeout(8)->post($url, [
                'api_key' => $apiKey,
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
            throw new RuntimeException($message ?: 'Credential layanan Validasi Akun ditolak.');
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
