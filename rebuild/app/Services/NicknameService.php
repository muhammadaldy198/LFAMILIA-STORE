<?php

namespace App\Services;

use App\Exceptions\NicknameServiceUnavailable;
use App\Models\NicknameGameCode;
use App\Models\Product;
use App\Models\ProductPackage;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Throwable;

class NicknameService
{
    public function __construct(
        private readonly AccountValidationConfig $config,
        private readonly H2hNicknameClient $h2h,
    ) {}

    /**
     * @param  array<string, string>  $input
     * @return array{supported:bool,verified:bool,nickname:?string,country:?string,warning:?string,eligibility?:?array,hidden_package_ids?:array<int,int>}
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

        $game = NicknameGameCode::active()
            ->nicknameSupported()
            ->where('code', $gameCode)
            ->first();
        if (! $game) {
            return $this->unsupported();
        }

        if (mb_strlen($userId) < 2 || ($game->requires_server && $server === '')) {
            return $this->warning('Nickname belum bisa diperiksa. Pastikan User ID dan Zone ID benar.');
        }

        // The store's own H2H service wins whenever it is provisioned; the
        // legacy provider configuration below stays as the fallback.
        if ($this->h2h->configured()) {
            return $this->checkViaH2h($product, $game, $gameCode, $userId, $server);
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
                $settings['resolved_ip'],
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
                    $settings['resolved_ip'],
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
        } catch (NicknameServiceUnavailable|ValidationException) {
            // Kokinpay only helps display a nickname. Its rejection or outage
            // cannot override the fulfillment target supplied by the buyer.
            return $this->warning('Nickname tidak berhasil diverifikasi. Pastikan ID tujuan sudah benar.');
        }
    }

    /**
     * Check through the store's own H2H service. Same advisory contract as
     * the legacy path: every non-VALID outcome degrades to a warning and
     * never overrides the fulfillment target supplied by the buyer.
     *
     * @return array{supported:bool,verified:bool,nickname:?string,country:?string,warning:?string,eligibility:?array,hidden_package_ids?:array<int,int>}
     */
    private function checkViaH2h(Product $product, NicknameGameCode $game, string $gameCode, string $userId, string $server): array
    {
        $data = $this->h2h->check(
            $gameCode,
            $userId,
            $server,
            $game->requires_region_check ? 'ID' : null,
        );

        if ($data === null) {
            return $this->warning('Nickname tidak berhasil diverifikasi karena layanan pengecekan sedang tidak tersedia. Pastikan ID sudah benar.');
        }

        return match ((string) $data['status']) {
            'VALID' => [
                'supported' => true,
                'verified' => true,
                'nickname' => $this->firstString([data_get($data, 'account.nickname')]),
                'country' => ($region = $this->firstString([data_get($data, 'account.region')])) !== null
                    ? strtoupper($region)
                    : null,
                'warning' => null,
                'eligibility' => is_array($data['eligibility'] ?? null) ? $data['eligibility'] : null,
                'hidden_package_ids' => $this->hiddenPackageIds(
                    $product,
                    $gameCode,
                    is_array($data['eligibility'] ?? null) ? $data['eligibility'] : null,
                ),
            ],
            'NOT_FOUND' => $this->warning('Nickname tidak ditemukan. Periksa kembali data akun.'),
            'REGION_MISMATCH' => $this->warning('Region akun tidak cocok. Periksa kembali Server / Zone ID.'),
            default => $this->warning('Nickname tidak berhasil diverifikasi. Pastikan ID tujuan sudah benar.'),
        };
    }

    /**
     * Packages the checked account may not buy: first-recharge tiers the
     * account already used, and Weekly Diamond Pass packages while the
     * account is over its WDP limit. Unknown eligibility hides nothing.
     *
     * @param  array<string, mixed>|null  $eligibility
     * @return array<int, int>
     */
    private function hiddenPackageIds(Product $product, string $gameCode, ?array $eligibility): array
    {
        if ($eligibility === null) {
            return [];
        }

        $tierSkus = match ($gameCode) {
            'mobile-legends' => ['50' => 'MLFS100', '150' => 'MLFS300', '250' => 'MLFS500', '500' => 'MLFS1000'],
            'magic-chess-go-go' => ['50' => 'MC50_A', '150' => 'MC150_A', '250' => 'MC250_A', '500' => 'MC500_A'],
            default => [],
        };

        $hiddenSkus = [];
        foreach (($eligibility['first_purchase'] ?? []) as $tier => $info) {
            if (is_array($info) && ($info['eligible'] ?? null) === false && isset($tierSkus[(string) $tier])) {
                $hiddenSkus[] = $tierSkus[(string) $tier];
            }
        }

        $ids = [];
        if ($hiddenSkus !== []) {
            $ids = ProductPackage::query()
                ->where('product_id', $product->id)
                ->where('is_active', true)
                ->whereHas('mappings', fn ($query) => $query->whereIn('external_sku', $hiddenSkus))
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        if ($gameCode === 'mobile-legends' && ($eligibility['wdp_eligible'] ?? null) === false) {
            $wdpIds = ProductPackage::query()
                ->where('product_id', $product->id)
                ->where('is_active', true)
                ->whereRaw('LOWER(name) LIKE ?', ['%weekly diamond pass%'])
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
            $ids = [...$ids, ...$wdpIds];
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  array<string, string>  $body
     * @return array<string, mixed>
     */
    private function post(string $url, array $body, string $field, string $resolvedIp): array
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $port = (int) (parse_url($url, PHP_URL_PORT) ?: 443);

        try {
            $response = Http::acceptJson()->withOptions([
                'allow_redirects' => false,
                'curl' => [CURLOPT_RESOLVE => [sprintf('%s:%d:%s', $host, $port, $resolvedIp)]],
            ])->timeout(8)->post($url, $body);
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
