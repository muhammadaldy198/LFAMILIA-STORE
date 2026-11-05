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
        if (trim((string) $product->nickname_required_region) === '') {
            return null;
        }

        return Crypt::encryptString(json_encode([
            'product_id' => $product->id,
            'input_hash' => $this->inputHash($input),
            'status' => $result['status'] ?? null,
            'region' => $result['region'] ?? null,
            'nickname' => $result['nickname'] ?? null,
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

        try {
            $decoded = json_decode(Crypt::decryptString((string) $proof), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $decoded = null;
        }

        if (! is_array($decoded)
            || (int) ($decoded['product_id'] ?? 0) !== (int) $product->id
            || ! hash_equals($this->inputHash($input), (string) ($decoded['input_hash'] ?? ''))
            || (int) ($decoded['expires_at'] ?? 0) < now()->timestamp) {
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

    private function inputHash(array $input): string
    {
        ksort($input);

        return hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
    }
}
