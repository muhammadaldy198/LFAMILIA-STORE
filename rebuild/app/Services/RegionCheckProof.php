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
        $eligibilityEvaluated = ($result['status'] ?? null) === 'VALID'
            && (array_key_exists('first_purchase', $result) || array_key_exists('wdp_eligible', $result));

        if (trim((string) $product->nickname_required_region) === '' && ! $eligibilityEvaluated) {
            return null;
        }

        return Crypt::encryptString(json_encode([
            'product_id' => $product->id,
            'input_hash' => $this->inputHash($input),
            'status' => $result['status'] ?? null,
            'region' => $result['region'] ?? null,
            'nickname' => $result['nickname'] ?? null,
            'disabled_package_ids' => array_values(array_map('intval', $result['disabled_package_ids'] ?? [])),
            'eligibility_evaluated' => $eligibilityEvaluated,
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
     * package family: the order must carry the proof issued for the same
     * account input, and the ordered package must not be in its disabled
     * list. Packages outside the family are not affected.
     *
     * @param  array<int, int>  $familyPackageIds
     */
    public function assertPackageEligible(Product $product, int $packageId, array $input, ?string $proof, array $familyPackageIds): void
    {
        if (! in_array($packageId, $familyPackageIds, true)) {
            return;
        }

        $decoded = $this->decode($product, $input, $proof);
        if ($decoded === null || ($decoded['eligibility_evaluated'] ?? false) !== true) {
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
