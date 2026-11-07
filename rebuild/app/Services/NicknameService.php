<?php

namespace App\Services;

use App\Models\NicknameGameCode;
use App\Models\Product;
use App\Models\ProductPackage;

class NicknameService
{
    /**
     * Inject the H2H client used for advisory nickname lookups.
     */
    public function __construct(private readonly H2hNicknameClient $h2h) {}

    /**
     * Advisory verdict only. The account destination remains customer_input.
     *
     * @param  array<string, string>  $input
     * @return array<string, mixed>
     */
    public function check(Product $product, array $input): array
    {
        if (($product->relationLoaded('category') && strtolower((string) $product->category?->slug) === 'voucher')
            || ! $product->nickname_check_enabled) {
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

        $game = NicknameGameCode::active()->nicknameSupported()->where('code', $gameCode)->first();
        if (! $game) {
            return $this->unsupported();
        }
        if (mb_strlen($userId) < 2 || ($game->requires_server && $server === '')) {
            return $this->warning('Nickname belum bisa diperiksa. Pastikan User ID dan Zone ID benar.');
        }

        return $this->checkViaH2h($product, $gameCode, $userId, $server);
    }

    /**
     * Convert the provider verdict into nickname, region, and package availability information.
     */
    private function checkViaH2h(Product $product, string $gameCode, string $userId, string $server): array
    {
        $result = $this->h2h->check($gameCode, $userId, $server !== '' ? $server : null);
        if (! $result || ($result['status'] ?? null) === 'ROUTE_ERROR') {
            return $this->warning('Nickname belum dapat diverifikasi. Pastikan ID tujuan benar.');
        }

        $nickname = $this->stringValue($result['nickname'] ?? null);
        $region = strtoupper((string) ($this->stringValue($result['region'] ?? null) ?? ''));
        $region = $region !== '' ? $region : null;
        $expected = strtoupper(trim((string) $product->nickname_required_region));

        if ($result['status'] === 'NOT_FOUND') {
            return $this->warning('Nickname tidak ditemukan. Periksa kembali data akun.', 'NOT_FOUND');
        }

        if ($result['status'] === 'REGION_MISMATCH'
            || ($result['status'] === 'VALID' && $expected !== '' && $region !== null && $expected !== $region)) {
            return [
                ...$this->warning('Region akun tidak cocok. Periksa kembali Server / Zone ID.', 'REGION_MISMATCH'),
                'nickname' => $nickname,
                'country' => $region,
                'region' => $region,
            ];
        }

        if ($result['status'] !== 'VALID' || $nickname === null) {
            return $this->warning('Nickname belum dapat diverifikasi. Pastikan ID tujuan benar.');
        }

        $firstPurchase = $result['first_purchase'] ?? null;

        return [
            'supported' => true,
            'verified' => true,
            'status' => 'VALID',
            'nickname' => $nickname,
            'country' => $region,
            'region' => $region,
            'warning' => null,
            'first_purchase' => $firstPurchase,
            'wdp_eligible' => $result['wdp_eligible'] ?? null,
            'disabled_package_ids' => $this->disabledPackages($product, $gameCode, $firstPurchase, $result['wdp_eligible'] ?? null),
        ];
    }

    /**
     * Find ineligible first-purchase and weekly-pass packages for the selected game.
     */
    private function disabledPackages(Product $product, string $gameCode, mixed $firstPurchase, mixed $wdpEligible): array
    {
        $skuMap = $this->firstPurchaseSkuMap($gameCode);

        $skus = [];
        if (is_array($firstPurchase)) {
            foreach ($firstPurchase as $key => $item) {
                if (! is_array($item) || ($item['eligible'] ?? null) !== false) {
                    continue;
                }
                $tier = (int) ($item['tier'] ?? $key);
                if (isset($skuMap[$tier])) {
                    $skus[] = $skuMap[$tier];
                }
            }
        }

        $ids = [];
        if ($skus !== []) {
            $ids = ProductPackage::query()->where('product_id', $product->id)
                ->whereHas('mappings', fn ($query) => $query->where('is_active', true)->whereIn('external_sku', $skus))
                ->pluck('id')->all();
        }
        if ($gameCode === 'mobile-legends' && $wdpEligible === false) {
            $ids = array_merge($ids, ProductPackage::query()
                ->where('product_id', $product->id)
                ->whereRaw('LOWER(name) LIKE ?', ['%weekly diamond pass%'])
                ->pluck('id')->all());
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * The provider SKUs gated by H2H first-purchase verdicts, per game.
     *
     * @return array<int, string>
     */
    private function firstPurchaseSkuMap(string $gameCode): array
    {
        return match ($gameCode) {
            'mobile-legends' => [50 => 'MLFS100', 150 => 'MLFS300', 250 => 'MLFS500', 500 => 'MLFS1000'],
            'magic-chess-go-go' => [50 => 'MC50_A', 150 => 'MC150_A', 250 => 'MC250_A', 500 => 'MC500_A'],
            default => [],
        };
    }

    /**
     * Every package an H2H eligibility verdict could disable for this
     * product, regardless of any single account's verdict. Checkout uses
     * this family to decide which orders must carry an eligibility proof.
     *
     * @return array<int, int>
     */
    public function eligibilityPackageIds(Product $product): array
    {
        if (($product->relationLoaded('category') && strtolower((string) $product->category?->slug) === 'voucher')
            || ! $product->nickname_check_enabled) {
            return [];
        }

        $gameCode = strtolower(trim((string) $product->nickname_game_code));
        $userField = trim((string) $product->nickname_user_field_key);
        $skuMap = $this->firstPurchaseSkuMap($gameCode);
        if ($gameCode === '' || $userField === '' || $skuMap === []) {
            return [];
        }

        $game = NicknameGameCode::active()->nicknameSupported()->where('code', $gameCode)->first();
        if (! $game) {
            return [];
        }

        $ids = ProductPackage::query()->where('product_id', $product->id)
            ->whereHas('mappings', fn ($query) => $query->where('is_active', true)->whereIn('external_sku', array_values($skuMap)))
            ->pluck('id')->all();
        if ($gameCode === 'mobile-legends') {
            $ids = array_merge($ids, ProductPackage::query()
                ->where('product_id', $product->id)
                ->whereRaw('LOWER(name) LIKE ?', ['%weekly diamond pass%'])
                ->pluck('id')->all());
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * Accept only a nonempty string from a potentially untrusted provider field.
     */
    private function stringValue(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * Return the standard result when a product cannot perform nickname checking.
     */
    private function unsupported(): array
    {
        return [
            'supported' => false, 'verified' => false, 'status' => null,
            'nickname' => null, 'country' => null, 'region' => null,
            'warning' => null, 'disabled_package_ids' => [],
        ];
    }

    /**
     * Return an unverified, non-blocking result with an actionable warning.
     */
    private function warning(string $message, ?string $status = null): array
    {
        return [
            'supported' => true, 'verified' => false, 'status' => $status,
            'nickname' => null, 'country' => null, 'region' => null,
            'warning' => $message, 'disabled_package_ids' => [],
        ];
    }
}
