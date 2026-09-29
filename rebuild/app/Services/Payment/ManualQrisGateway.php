<?php

namespace App\Services\Payment;

use App\Models\StoreAsset;
use Illuminate\Validation\ValidationException;

class ManualQrisGateway
{
    /**
     * @return array<string, mixed>
     */
    public function create(): array
    {
        $asset = StoreAsset::where('key', 'manual_qris')->where('is_active', true)->first();
        $qrUrl = $asset?->getFirstMediaUrl('image');
        if (! $qrUrl) {
            throw ValidationException::withMessages([
                'payment' => 'QRIS manual belum dikonfigurasi.',
            ]);
        }

        return [
            'status' => 'PENDING',
            'external_reference' => null,
            'public_payload' => ['kind' => 'manual_qris', 'qr_url' => $qrUrl],
            'gateway_payload' => null,
        ];
    }
}
