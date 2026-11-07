<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use Throwable;

class RegionCheckProof
{
    /**
     * The encrypted proof binds a server-side H2H verdict to the product and
     * normalized account input; the browser cannot change the reported region.
     */
    public function issue(Product $product, array $input, array $result): ?string
    {
        // The H2H client normalizes a missing verdict to null, so a key's
        // presence proves nothing. First purchase is attested per tier:
        // only tiers carrying a concrete boolean verdict are listed. The
        // weekly-pass verdict is a single boolean.
        $firstPurchaseTiers = [];
        if (($result['status'] ?? null) === 'VALID' && is_array($result['first_purchase'] ?? null)) {
            foreach ($result['first_purchase'] as $key => $item) {
                if (is_array($item) && is_bool($item['eligible'] ?? null)) {
                    $firstPurchaseTiers[] = (int) ($item['tier'] ?? $key);
                }
            }
            $firstPurchaseTiers = array_values(array_unique($firstPurchaseTiers));
        }
        $weeklyPassEvaluated = ($result['status'] ?? null) === 'VALID'
            && is_bool($result['wdp_eligible'] ?? null);

        if (trim((string) $product->nickname_required_region) === ''
            && $firstPurchaseTiers === [] && ! $weeklyPassEvaluated) {
            return null;
        }

        return Crypt::encryptString(json_encode([
            'product_id' => $product->id,
            'input_hash' => $this->inputHash($input),
            'status' => $result['status'] ?? null,
            'region' => $result['region'] ?? null,
            'nickname' => $result['nickname'] ?? null,
            'disabled_package_ids' => array_values(array_map('intval', $result['disabled_package_ids'] ?? [])),
            'first_purchase_tiers' => $firstPurchaseTiers,
            'weekly_pass_evaluated' => $weeklyPassEvaluated,
            'expires_at' => now()->addMinutes(10)->timestamp,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function assertOrder(Product $product, array $input, ?string $proof): ?array
    {
        $expected = strtoupper(trim((string) $product->nickname_required_region));
        if ($expected === '') {
            return null;
        }

        $decoded = $this->decode($product, $input, $proof);
        if ($decoded === null) {
            throw ValidationException::withMessages([
                'region_proof' => 'Periksa akun terlebih dahulu untuk memastikan region produk.',
            ]);
        }

        $region = strtoupper(trim((string) ($decoded['region'] ?? '')));
        if (($decoded['status'] ?? null) === 'REGION_MISMATCH'
            || ($region !== '' && $region !== $expected)) {
            throw ValidationException::withMessages([
                'customer_input' => 'Region akun tidak cocok. Produk ini khusus akun '.$expected.'.',
            ]);
        }

        return [
            'supported' => true,
            'verified' => ($decoded['status'] ?? null) === 'VALID' && ! empty($decoded['nickname']),
            'nickname' => $decoded['nickname'] ?? null,
            'country' => $region !== '' ? $region : null,
            'warning' => null,
        ];
    }

    /**
     * Enforce the H2H first-purchase / weekly-pass verdict for the gated
     * package families: the order must carry the proof issued for the same
     * account input, the proof must attest a concrete verdict for the
     * ordered package's family, and the package must not be in its
     * disabled list. Packages outside the families are not affected.
     *
     * @param  array{first_purchase?: array<int, int>, weekly_pass?: array<int, int>}  $families
     *                                                                                            first_purchase maps package id => its first-purchase tier;
     *                                                                                            weekly_pass lists package ids.
     */
    public function assertPackageEligible(Product $product, int $packageId, array $input, ?string $proof, array $families): void
    {
        $firstPurchaseTier = $families['first_purchase'][$packageId] ?? null;
        $inWeeklyPass = in_array($packageId, array_map('intval', $families['weekly_pass'] ?? []), true);
        if ($firstPurchaseTier === null && ! $inWeeklyPass) {
            return;
        }

        $decoded = $this->decode($product, $input, $proof);
        if ($decoded === null
            || ($firstPurchaseTier !== null && ! in_array((int) $firstPurchaseTier, array_map('intval', (array) ($decoded['first_purchase_tiers'] ?? [])), true))
            || ($inWeeklyPass && ($decoded['weekly_pass_evaluated'] ?? false) !== true)) {
            throw ValidationException::withMessages([
                'package_id' => 'Periksa akun terlebih dahulu untuk memastikan kelayakan paket.',
            ]);
        }

        if (in_array($packageId, array_map('intval', (array) ($decoded['disabled_package_ids'] ?? [])), true)) {
            throw ValidationException::withMessages([
                'package_id' => 'Paket ini tidak tersedia untuk akun tujuan.',
            ]);
        }
    }

    /**
     * Decode a proof and verify its product, account-input, and expiry
     * binding; null when the proof is missing or does not bind.
     *
     * @return array<string, mixed>|null
     */
    private function decode(Product $product, array $input, ?string $proof): ?array
    {
        try {
            $decoded = json_decode(Crypt::decryptString((string) $proof), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $decoded = null;
        }

        if (! is_array($decoded)
            || (int) ($decoded['product_id'] ?? 0) !== (int) $product->id
            || ! hash_equals($this->inputHash($input), (string) ($decoded['input_hash'] ?? ''))
            || (int) ($decoded['expires_at'] ?? 0) < now()->timestamp) {
            return null;
        }

        return $decoded;
    }

    /**
     * Hash sorted checkout fields so a region proof is bound to the exact account.
     */
    private function inputHash(array $input): string
    {
        ksort($input);

        return hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
    }
}
