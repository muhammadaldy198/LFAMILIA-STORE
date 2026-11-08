<?php

namespace App\Services;

use App\Services\Payment\DokuCheckoutGateway;
use App\Services\Payment\ManualQrisGateway;
use App\Services\Payment\MidtransGateway;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Membuat transaksi pembayaran di gateway (Midtrans, DOKU, QRIS manual)
 * dan menyusun hasil publiknya untuk pemanggil. Memulai pembayaran
 * diurus PaymentStartService; memulihkan yang macet diurus
 * PaymentRecoveryService.
 */
class PaymentCreationService
{
    public function __construct(
        private readonly MidtransGateway $midtrans,
        private readonly DokuCheckoutGateway $doku,
        private readonly ManualQrisGateway $manualQris,
        private readonly AdminNotificationService $notifications,
    ) {}

    public function createExternal(object $payment, object $target, array $route): array
    {
        // Alasan fungsi ini belum dipecah (standar keterbacaan di AGENTS.md):
        // satu urutan pembuatan di gateway — payload disusun per
        // penyedia dari konteks pembayaran yang sama, dipanggil, lalu
        // hasilnya dicatat dan disusun; memecahnya per penyedia akan
        // menggandakan penanganan hasil yang sekarang seragam.
        if ($payment->status !== 'CREATING') {
            return $this->publicResult($payment);
        }

        $claimed = DB::table('payment_transactions')
            ->where('id', $payment->id)
            ->where('status', 'CREATING')
            ->update([
                'status' => 'SENDING',
                'updated_at' => now(),
            ]);

        if ($claimed !== 1) {
            return $this->publicResult(
                DB::table('payment_transactions')->where('id', $payment->id)->firstOrFail()
            );
        }

        $payment = DB::table('payment_transactions')->where('id', $payment->id)->firstOrFail();
        $user = $target->user_id ? DB::table('users')->where('id', $target->user_id)->first() : null;
        // Gateway mengembalikan pengguna ke return/finish URL ini
        // setelah bayar (Midtrans callbacks.finish, DOKU
        // callback_url_result). URL-nya DITANDATANGANI sementara
        // (lihat signedReturnUrl): tanpa signature, member yang
        // membuka halaman lewat link signed (logout) akan kena 403
        // di PaymentPageController karena sesi/login-nya tidak ada,
        // padahal order sudah dibayar.
        $orderNumber = $payment->order_id
            ? DB::table('orders')->where('id', $payment->order_id)->value('order_number')
            : null;
        // Midtrans Snap requires at least five minutes for its page and
        // payment expiry. DOKU retains the existing minimum of one minute.
        // Use this exact duration for both gateway creation and return URL.
        $providerExpiresMinutes = $this->expiresMinutes($target->expires_at ?? null);
        if ($route['gateway_code'] === 'MIDTRANS') {
            $providerExpiresMinutes = max(5, $providerExpiresMinutes);
        }
        $returnUrl = $orderNumber !== null
            ? $this->signedReturnUrl((string) $orderNumber, $providerExpiresMinutes)
            : null;
        $context = [
            'merchant_reference' => $payment->merchant_reference,
            'return_url' => $returnUrl,
            'finish_url' => $returnUrl,
            'amount_idr' => (int) $payment->amount_idr,
            'customer_name' => $target->customer_name ?? $user?->name ?? 'Customer LFAMILIA',
            'customer_email' => $target->guest_email ?? $user?->email,
            // Gateway (Midtrans/Doku) hanya menerima digit pada field phone,
            // jadi kode negara "+62..." dibersihkan di sini.
            'customer_phone' => $this->phoneDigits($target->guest_phone ?? $user?->phone),
            'provider_channel' => $route['provider_channel'],
            'route_configuration' => $route['configuration'],
            'expires_minutes' => $providerExpiresMinutes,
        ];

        try {
            $result = match ($route['gateway_code']) {
                'MIDTRANS' => $this->midtrans->create($context),
                'DOKU' => $this->doku->create($context),
                'MANUAL_QRIS' => $this->manualQris->create(),
                default => throw ValidationException::withMessages(['payment' => 'Gateway pembayaran tidak didukung.']),
            };
        } catch (ValidationException $exception) {
            $rejected = DB::table('payment_transactions')
                ->where('id', $payment->id)
                ->where('status', 'SENDING')
                ->update([
                    'status' => 'REJECTED',
                    'updated_at' => now(),
                ]);
            if ($rejected !== 1) {
                return $this->publicResult(
                    DB::table('payment_transactions')->where('id', $payment->id)->firstOrFail()
                );
            }

            throw $exception;
        } catch (RuntimeException|ConnectionException $exception) {
            $unknown = DB::table('payment_transactions')
                ->where('id', $payment->id)
                ->where('status', 'SENDING')
                ->update([
                    'status' => 'UNKNOWN',
                    'updated_at' => now(),
                ]);
            if ($unknown !== 1) {
                return $this->publicResult(
                    DB::table('payment_transactions')->where('id', $payment->id)->firstOrFail()
                );
            }

            Log::warning('Payment transaction marked UNKNOWN, needs manual reconciliation', [
                'payment_transaction_id' => $payment->id,
                'order_id' => $payment->order_id,
                'gateway_code' => $payment->gateway_code,
            ]);

            // Gateway timeout tidak bisa dibedakan dari penolakan: transaksi bisa
            // saja sudah terbuat di sisi gateway. Admin harus memeriksa manual.
            $this->notifications->record(
                'payment.unknown',
                'Pembayaran perlu diperiksa',
                'Pembuatan pembayaran #'.$payment->id.' ('.$payment->gateway_code.') tidak dapat dipastikan: '.$exception::class.'. Periksa status di gateway sebelum menyuruh pembeli membayar ulang.',
                'WARNING',
                'payment_transaction',
                (int) $payment->id,
                ['order_id' => $payment->order_id, 'gateway_code' => $payment->gateway_code]
            );

            throw ValidationException::withMessages([
                'payment' => 'Status pembuatan pembayaran belum dapat dipastikan. Jangan ulangi pembayaran; periksa status transaksi.',
            ]);
        }

        DB::table('payment_transactions')->where('id', $payment->id)->update([
            'external_reference' => $result['external_reference'],
            'public_payload' => $result['public_payload'] === null
                ? null : json_encode($result['public_payload'], JSON_THROW_ON_ERROR),
            'gateway_payload' => $result['gateway_payload'] === null
                ? null : json_encode($result['gateway_payload'], JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);

        $statusUpdated = DB::table('payment_transactions')
            ->where('id', $payment->id)
            ->where('status', 'SENDING')
            ->update([
                'status' => $result['status'],
                'updated_at' => now(),
            ]);

        if ($statusUpdated === 1 && $route['gateway_code'] === 'MANUAL_QRIS' && $result['status'] === 'PENDING' && $payment->order_id !== null) {
            $orderNumber = DB::table('orders')->where('id', $payment->order_id)->value('order_number');
            $this->notifications->record(
                'payment.manual_qris.pending',
                'QRIS manual menunggu konfirmasi',
                'Order '.($orderNumber ?: '#'.$payment->order_id).' sudah menampilkan QRIS manual dan menunggu verifikasi pembayaran.',
                'WARNING',
                'order',
                $payment->order_id,
                ['payment_id' => (int) $payment->id, 'order_number' => $orderNumber]
            );
        }

        return $this->publicResult(DB::table('payment_transactions')->where('id', $payment->id)->first());
    }

    /**
     * @return array<string, mixed>
     */
    public function publicResult(object $payment): array
    {
        $payload = is_string($payment->public_payload)
            ? (json_decode($payment->public_payload, true) ?: [])
            : ((array) ($payment->public_payload ?? []));

        $status = (string) $payment->status;
        if ($status === 'SENDING') {
            $status = 'CREATING';
        }

        return [
            'payment_id' => (int) $payment->id,
            'status' => $status,
            'channel_code' => (string) $payment->channel_code,
            'amount_idr' => (int) $payment->amount_idr,
            'instructions' => $payload,
        ];
    }

    /**
     * Use the same provider checkout duration passed to the gateway,
     * plus a five-minute redirect grace period. Never issue permanent
     * signatures; the maximum TTL is 125 minutes (120 + 5).
     */
    private function signedReturnUrl(string $orderNumber, int $providerExpiresMinutes): string
    {
        return URL::temporarySignedRoute(
            'payment.show',
            now()->addMinutes($providerExpiresMinutes + 5),
            ['invoice' => $orderNumber],
        );
    }

    private function expiresMinutes(mixed $expiresAt): int
    {
        if (! $expiresAt) {
            return 30;
        }

        return max(1, min(120, (int) now()->diffInMinutes($expiresAt, false)));
    }

    /** Nomor untuk gateway pembayaran: hanya digit, tanpa "+" atau kode negara bersimbol. */
    private function phoneDigits(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        return $digits === '' ? null : $digits;
    }
}
