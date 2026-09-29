<?php

namespace App\Services;

use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CheckoutService
{
    public function __construct(
        private readonly CheckoutPricing $pricing,
        private readonly CheckoutInputValidator $inputValidator,
        private readonly NicknameService $nickname,
        private readonly GuestOrderAccess $guestAccess,
        private readonly PaymentRoutingService $paymentRouting,
        private readonly AdminNotificationService $notifications,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function quote(
        int $packageId,
        string $paymentChannelCode,
        ?string $voucherCode,
        ?User $user,
        ?string $guestEmail,
        ?string $guestPhone,
    ): array {
        $price = $this->pricing->forPackage($packageId);
        $voucher = $this->voucher($voucherCode, $price, $user, $guestEmail, $guestPhone, false);
        $route = $this->paymentRouting->resolve($paymentChannelCode, false, 'order');
        if ($route['gateway_code'] === 'WALLET' && ! $user) {
            throw ValidationException::withMessages([
                'payment_channel_code' => 'Saldo hanya tersedia untuk akun customer.',
            ]);
        }
        $chargeable = $price['subtotal_idr'] - $voucher['discount_idr'];
        $fee = $this->paymentRouting->fee($chargeable, $route);

        return [
            'subtotal_idr' => $price['subtotal_idr'],
            'discount_idr' => $voucher['discount_idr'],
            'fee_idr' => $fee,
            'total_idr' => $chargeable + $fee,
            'voucher_code' => $voucher['code'],
            'payment_channel_code' => $route['channel_code'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{order:object,access_code:?string,created:bool}
     */
    public function create(array $data, ?User $user): array
    {
        if ($user && trim((string) $user->phone) === '') {
            throw ValidationException::withMessages([
                'guest_phone' => 'Lengkapi nomor HP akun sebelum checkout.',
            ]);
        }

        $fingerprint = $this->fingerprint($data, $user);
        $existing = DB::table('orders')->where('idempotency_key', $data['idempotency_key'])->first();
        if ($existing) {
            return $this->existingResult($existing, $fingerprint, $data['idempotency_key']);
        }

        $prePrice = $this->pricing->forPackage((int) $data['package_id']);
        $product = Product::with('fields')->findOrFail($prePrice['product_id']);
        $input = $this->inputValidator->validate($product, $data['customer_input']);
        $nickname = $this->nickname->check($product, $input);

        $result = DB::transaction(function () use ($data, $user, $fingerprint, $input, $nickname): array {
            $existing = DB::table('orders')->where('idempotency_key', $data['idempotency_key'])
                ->lockForUpdate()->first();
            if ($existing) {
                return ['order' => $existing, 'created' => false];
            }

            $price = $this->pricing->forPackage((int) $data['package_id'], true);
            $lockedProduct = Product::with('fields')->findOrFail($price['product_id']);
            $lockedInput = $this->inputValidator->validate($lockedProduct, $input);
            $voucher = $this->voucher(
                $data['voucher_code'] ?? null,
                $price,
                $user,
                $data['guest_email'] ?? null,
                $data['guest_phone'] ?? null,
                true
            );
            $paymentRoute = $this->paymentRouting->resolve(
                $data['payment_channel_code'],
                true,
                'order'
            );
            if ($paymentRoute['gateway_code'] === 'WALLET' && ! $user) {
                throw ValidationException::withMessages([
                    'payment_channel_code' => 'Saldo hanya tersedia untuk akun customer.',
                ]);
            }

            $chargeable = $price['subtotal_idr'] - $voucher['discount_idr'];
            $fee = $this->paymentRouting->fee($chargeable, $paymentRoute);
            $total = $chargeable + $fee;
            if ($total <= 0) {
                throw ValidationException::withMessages([
                    'voucher_code' => 'Voucher menghasilkan total tidak valid.',
                ]);
            }

            $reservationMinutes = max(5, min(120, (int) config('lfamilia.checkout_reservation_minutes', 30)));
            $expiresAt = now()->addMinutes($reservationMinutes);
            $correlationId = preg_match('/^[A-Za-z0-9._:-]{8,100}$/', (string) ($data['_correlation_id'] ?? ''))
                ? (string) $data['_correlation_id']
                : (string) Str::uuid();
            $snapshot = [
                'checkout_fingerprint' => $fingerprint,
                'product' => [
                    'id' => $price['product_id'],
                    'name' => $price['product_name'],
                    'slug' => $price['product_slug'],
                    'category_id' => $price['category_id'],
                    'category_name' => $price['category_name'],
                    'fulfillment_mode' => $price['fulfillment_mode'],
                ],
                'package' => [
                    'id' => $price['package_id'],
                    'code' => $price['package_code'],
                    'name' => $price['package_name'],
                    'nominal_value' => $price['nominal_value'],
                ],
                'provider' => [
                    'mapping_id' => $price['provider_mapping_id'],
                    'code' => $price['provider_code'],
                    'sku' => $price['provider_sku'],
                    'cost_idr' => $price['cost_idr'],
                    'max_price_idr' => $price['max_price_idr'],
                ],
                'pricing' => [
                    'margin_percent' => $price['margin_percent'],
                    'cost_idr' => $price['cost_idr'],
                    'margin_idr' => $price['margin_idr'],
                    'discount_idr' => $voucher['discount_idr'],
                    'fee_idr' => $fee,
                    'total_idr' => $total,
                ],
                'payment' => [
                    'channel_id' => $paymentRoute['channel_id'],
                    'channel_code' => $paymentRoute['channel_code'],
                    'channel_name' => $paymentRoute['channel_name'],
                    'route_id' => $paymentRoute['route_id'],
                    'gateway_id' => $paymentRoute['gateway_id'],
                    'gateway_code' => $paymentRoute['gateway_code'],
                ],
                'voucher' => $voucher['snapshot'],
                'customer_input' => $lockedInput,
                'nickname' => $nickname,
            ];

            $orderId = DB::table('orders')->insertGetId([
                'order_number' => $this->orderNumber(),
                'user_id' => $user?->id,
                'guest_email' => $user ? null : Str::lower(trim((string) $data['guest_email'])),
                'guest_phone' => $user ? null : $this->normalizePhone((string) $data['guest_phone']),
                'product_id' => $price['product_id'],
                'product_package_id' => $price['package_id'],
                'provider_mapping_id' => $price['provider_mapping_id'],
                'voucher_id' => $voucher['voucher_id'],
                'payment_channel_id' => $paymentRoute['channel_id'],
                'payment_route_id' => $paymentRoute['route_id'],
                'status' => 'PENDING_PAYMENT',
                'currency' => 'IDR',
                'customer_input' => json_encode($lockedInput, JSON_THROW_ON_ERROR),
                'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                'cost_idr' => $price['cost_idr'],
                'margin_idr' => $price['margin_idr'],
                'discount_idr' => $voucher['discount_idr'],
                'fee_idr' => $fee,
                'total_idr' => $total,
                'idempotency_key' => $data['idempotency_key'],
                'expires_at' => $expiresAt,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($voucher['voucher_id']) {
                DB::table('voucher_redemptions')->insert([
                    'voucher_id' => $voucher['voucher_id'],
                    'order_id' => $orderId,
                    'user_id' => $user?->id,
                    'guest_identifier_hash' => $user ? null : $this->guestIdentifier(
                        (string) $data['guest_email'],
                        (string) $data['guest_phone']
                    ),
                    'status' => 'RESERVED',
                    'reserved_until' => $expiresAt,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('order_events')->insert([
                'order_id' => $orderId,
                'event_type' => 'ORDER_CREATED',
                'from_status' => null,
                'to_status' => 'PENDING_PAYMENT',
                'correlation_id' => $correlationId,
                'metadata' => json_encode(['source' => 'checkout'], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);

            return [
                'order' => DB::table('orders')->where('id', $orderId)->first(),
                'created' => true,
            ];
        }, 3);

        if (! $result['created']) {
            return $this->existingResult($result['order'], $fingerprint, $data['idempotency_key']);
        }

        $accessCode = $user ? null : $this->guestAccess->issueStable(
            $result['order']->id,
            $data['idempotency_key']
        );

        $snapshot = json_decode((string) $result['order']->snapshot, true) ?: [];
        $this->notifications->record(
            'order.created',
            'Order baru',
            'Order '.$result['order']->order_number.' dibuat dengan total Rp'.number_format((int) $result['order']->total_idr, 0, ',', '.').'.',
            'INFO',
            'order',
            $result['order']->id,
            ['order_number' => $result['order']->order_number]
        );
        if (data_get($snapshot, 'payment.gateway_code') === 'MANUAL_QRIS') {
            $this->notifications->record(
                'payment.manual_qris.pending',
                'QRIS manual menunggu konfirmasi',
                'Order '.$result['order']->order_number.' menggunakan QRIS manual.',
                'WARNING',
                'order',
                $result['order']->id
            );
        }

        return [
            'order' => $result['order'],
            'access_code' => $accessCode,
            'created' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $price
     * @return array{voucher_id:?int,code:?string,discount_idr:int,snapshot:?array}
     */
    private function voucher(
        ?string $code,
        array $price,
        ?User $user,
        ?string $guestEmail,
        ?string $guestPhone,
        bool $lock,
    ): array {
        $code = Str::upper(trim((string) $code));
        if ($code === '') {
            return ['voucher_id' => null, 'code' => null, 'discount_idr' => 0, 'snapshot' => null];
        }

        $query = DB::table('vouchers')->whereRaw('UPPER(code) = ?', [$code]);
        if ($lock) {
            $query->lockForUpdate();
        }
        $voucher = $query->first();

        if (! $voucher || ! $voucher->is_active) {
            throw ValidationException::withMessages(['voucher_code' => 'Voucher tidak tersedia.']);
        }

        $now = now();
        if ($voucher->starts_at && $now->lt(Carbon::parse($voucher->starts_at))) {
            throw ValidationException::withMessages(['voucher_code' => 'Voucher belum aktif.']);
        }
        if ($voucher->ends_at && $now->gt(Carbon::parse($voucher->ends_at))) {
            throw ValidationException::withMessages(['voucher_code' => 'Voucher sudah berakhir.']);
        }
        if ($price['subtotal_idr'] < (int) $voucher->minimum_total_idr) {
            throw ValidationException::withMessages(['voucher_code' => 'Minimum transaksi voucher belum terpenuhi.']);
        }

        $productScoped = DB::table('voucher_products')->where('voucher_id', $voucher->id)->exists();
        $categoryScoped = DB::table('voucher_categories')->where('voucher_id', $voucher->id)->exists();
        if (($productScoped || $categoryScoped)
            && ! DB::table('voucher_products')->where('voucher_id', $voucher->id)
                ->where('product_id', $price['product_id'])->exists()
            && ! DB::table('voucher_categories')->where('voucher_id', $voucher->id)
                ->where('category_id', $price['category_id'])->exists()) {
            throw ValidationException::withMessages(['voucher_code' => 'Voucher tidak berlaku untuk produk ini.']);
        }

        $active = fn ($query) => $query->where(function ($status) use ($now): void {
            $status->where('status', 'REDEEMED')
                ->orWhere(function ($reserved) use ($now): void {
                    $reserved->where('status', 'RESERVED')->where('reserved_until', '>', $now);
                });
        });

        if ($voucher->total_quota !== null) {
            $used = $active(DB::table('voucher_redemptions')->where('voucher_id', $voucher->id))->count();
            if ($used >= (int) $voucher->total_quota) {
                throw ValidationException::withMessages(['voucher_code' => 'Kuota voucher sudah habis.']);
            }
        }

        if ($voucher->per_customer_limit !== null) {
            $customerQuery = DB::table('voucher_redemptions')->where('voucher_id', $voucher->id);
            if ($user) {
                $customerQuery->where('user_id', $user->id);
            } else {
                $customerQuery->where('guest_identifier_hash', $this->guestIdentifier(
                    (string) $guestEmail,
                    (string) $guestPhone
                ));
            }
            $used = $active($customerQuery)->count();
            if ($used >= (int) $voucher->per_customer_limit) {
                throw ValidationException::withMessages(['voucher_code' => 'Batas penggunaan voucher sudah tercapai.']);
            }
        }

        $type = Str::upper((string) $voucher->discount_type);
        $value = (int) $voucher->discount_value;
        $discount = $type === 'PERCENT'
            ? intdiv($price['subtotal_idr'] * $value, 100)
            : $value;

        if ($discount <= 0 || $discount >= $price['subtotal_idr']) {
            throw ValidationException::withMessages(['voucher_code' => 'Nilai voucher menghasilkan total tidak valid.']);
        }

        return [
            'voucher_id' => (int) $voucher->id,
            'code' => (string) $voucher->code,
            'discount_idr' => $discount,
            'snapshot' => [
                'id' => (int) $voucher->id,
                'code' => (string) $voucher->code,
                'discount_type' => $type,
                'discount_value' => $value,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function fingerprint(array $data, ?User $user): string
    {
        $input = $data['customer_input'];
        ksort($input);

        $payload = [
            'actor' => $user
                ? ['type' => 'user', 'id' => $user->id]
                : [
                    'type' => 'guest',
                    'email' => Str::lower(trim((string) $data['guest_email'])),
                    'phone' => $this->normalizePhone((string) $data['guest_phone']),
                ],
            'package_id' => (int) $data['package_id'],
            'payment_channel_code' => (string) $data['payment_channel_code'],
            'voucher_code' => Str::upper(trim((string) ($data['voucher_code'] ?? ''))),
            'customer_input' => $input,
        ];

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array{order:object,access_code:?string,created:bool}
     */
    private function existingResult(object $order, string $fingerprint, string $idempotencyKey): array
    {
        $snapshot = is_string($order->snapshot)
            ? json_decode($order->snapshot, true, 512, JSON_THROW_ON_ERROR)
            : (array) $order->snapshot;

        if (($snapshot['checkout_fingerprint'] ?? null) !== $fingerprint) {
            throw new HttpException(409, 'Idempotency key sudah digunakan untuk checkout berbeda.');
        }

        return [
            'order' => $order,
            'access_code' => $order->user_id === null
                ? $this->guestAccess->issueStable($order->id, $idempotencyKey)
                : null,
            'created' => false,
        ];
    }

    private function guestIdentifier(string $email, string $phone): string
    {
        $email = Str::lower(trim($email));
        $phone = $this->normalizePhone($phone);
        if ($email === '' || $phone === '') {
            throw ValidationException::withMessages([
                'guest_email' => 'Email dan nomor HP guest diperlukan untuk voucher.',
            ]);
        }

        return hash_hmac('sha256', $email.'|'.$phone, (string) config('app.key'));
    }

    private function normalizePhone(string $phone): string
    {
        return preg_replace('/\s+/', '', trim($phone)) ?? '';
    }

    private function orderNumber(): string
    {
        do {
            $number = 'LF'.now()->format('ymd').'-'.Str::upper(Str::random(10));
        } while (DB::table('orders')->where('order_number', $number)->exists());

        return $number;
    }
}
