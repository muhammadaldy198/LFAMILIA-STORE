<?php

namespace App\Http\Controllers;

use App\Services\PublicOrderStatusService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PublicOrderTrackingController
{
    public function __construct(private readonly PublicOrderStatusService $statuses) {}

    public function index(): Response
    {
        return Inertia::render('Guest/Track', [
            'transactions' => $this->recentTransactions(),
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        $data = $request->validate([
            'query' => ['required', 'string', 'max:100'],
        ]);

        $query = trim((string) $data['query']);
        if ($this->looksLikeInvoice($query)) {
            $order = $this->orderByNumber(strtoupper($query));

            return $order
                ? response()->json(['mode' => 'order', 'order' => $this->publicOrder($order)])
                    ->header('Cache-Control', 'no-store, private')
                : response()->json(['message' => 'Invoice tidak ditemukan.'], 404);
        }

        $phones = $this->phoneVariants($query);
        if ($phones === []) {
            return response()->json(['message' => 'Masukkan invoice atau nomor WhatsApp yang valid.'], 422);
        }

        $orders = DB::table('orders')
            ->leftJoin('users', 'users.id', '=', 'orders.user_id')
            ->join('products', 'products.id', '=', 'orders.product_id')
            ->join('product_packages', 'product_packages.id', '=', 'orders.product_package_id')
            ->where(function ($builder) use ($phones): void {
                $builder->whereIn('orders.guest_phone_normalized', $phones)
                    ->orWhereIn('users.phone_normalized', $phones);
            })
            ->orderByDesc('orders.id')->limit(25)
            ->get([
                'orders.id', 'orders.order_number', 'orders.status', 'orders.total_idr', 'orders.created_at',
                'products.name as product_name', 'product_packages.name as package_name',
            ])->map(fn (object $order): array => [
                'trackingToken' => $this->trackingToken((int) $order->id, false),
                'maskedReferenceId' => $this->maskReference((string) $order->order_number),
                'productName' => $order->product_name,
                'packageLabel' => $order->package_name,
                'total' => (int) $order->total_idr,
                'status' => $this->statuses->slug((string) $order->status),
                'createdAt' => $order->created_at,
            ])->values();

        return response()->json(['mode' => 'phone', 'orders' => $orders])
            ->header('Cache-Control', 'no-store, private');
    }

    public function status(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tracking_token' => ['required', 'string', 'max:1200'],
        ]);
        $token = $this->trackingTokenData($data['tracking_token']);
        abort_unless($token, 404);

        $order = $this->orderById($token['id']);
        abort_unless($order, 404);

        return response()->json([
            'order' => $this->publicOrder($order, $token['reveal_reference']),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function feed(): JsonResponse
    {
        return response()->json(['transactions' => $this->recentTransactions()])
            ->header('Cache-Control', 'no-store, public, max-age=0');
    }

    private function orderByNumber(string $orderNumber): ?object
    {
        return DB::table('orders')
            ->join('products', 'products.id', '=', 'orders.product_id')
            ->join('product_packages', 'product_packages.id', '=', 'orders.product_package_id')
            ->leftJoin('payment_channels', 'payment_channels.id', '=', 'orders.payment_channel_id')
            ->where('orders.order_number', $orderNumber)
            ->select(
                'orders.id', 'orders.order_number', 'orders.status', 'orders.customer_input',
                'orders.total_idr', 'orders.paid_at', 'orders.created_at', 'orders.updated_at',
                'products.name as product_name', 'product_packages.name as package_name',
                'payment_channels.name as payment_channel_name'
            )->first();
    }

    private function orderById(int $orderId): ?object
    {
        return DB::table('orders')
            ->join('products', 'products.id', '=', 'orders.product_id')
            ->join('product_packages', 'product_packages.id', '=', 'orders.product_package_id')
            ->leftJoin('payment_channels', 'payment_channels.id', '=', 'orders.payment_channel_id')
            ->where('orders.id', $orderId)
            ->select(
                'orders.id', 'orders.order_number', 'orders.status', 'orders.customer_input',
                'orders.total_idr', 'orders.paid_at', 'orders.created_at', 'orders.updated_at',
                'products.name as product_name', 'product_packages.name as package_name',
                'payment_channels.name as payment_channel_name'
            )->first();
    }

    private function publicOrder(object $order, bool $revealReference = true): array
    {
        $payment = DB::table('payment_transactions')->where('order_id', $order->id)
            ->orderByDesc('id')->first(['status']);
        $events = DB::table('order_events')->where('order_id', $order->id)
            ->orderBy('id')->get(['id', 'event_type', 'from_status', 'to_status', 'created_at'])
            ->map(fn (object $event): array => [
                'id' => (int) $event->id,
                'source' => $this->eventSource((string) $event->event_type),
                'status' => $event->to_status ? $this->statuses->slug((string) $event->to_status) : null,
                'label' => $this->eventLabel((string) $event->event_type, $event->to_status),
                'createdAt' => $event->created_at,
            ])->values()->all();

        return [
            'referenceId' => $revealReference
                ? $order->order_number
                : $this->maskReference((string) $order->order_number),
            'referenceMasked' => ! $revealReference,
            'trackingToken' => $this->trackingToken((int) $order->id, $revealReference),
            'productName' => $order->product_name,
            'packageLabel' => $order->package_name,
            'destination' => $this->maskedDestination($order->customer_input),
            'total' => (int) $order->total_idr,
            'paymentMethod' => $order->payment_channel_name ?: 'Pembayaran',
            'paymentStatus' => $this->paymentStatus((string) ($payment?->status ?? ''), (string) $order->status, $order->paid_at !== null),
            'wasPaid' => $order->paid_at !== null,
            'fulfillmentStatus' => $this->statuses->slug((string) $order->status),
            'events' => $events,
            'createdAt' => $order->created_at,
            'updatedAt' => $order->updated_at,
        ];
    }

    private function recentTransactions(): array
    {
        return DB::table('orders')
            ->join('products', 'products.id', '=', 'orders.product_id')
            ->join('product_packages', 'product_packages.id', '=', 'orders.product_package_id')
            ->orderByDesc('orders.id')->limit(20)
            ->get([
                'orders.order_number', 'orders.status', 'orders.total_idr', 'orders.created_at',
                'products.name as product_name', 'product_packages.name as package_name',
            ])->map(fn (object $order): array => [
                'referenceId' => null,
                'maskedReferenceId' => $this->maskReference((string) $order->order_number),
                'productName' => $order->product_name,
                'packageLabel' => $order->package_name,
                'total' => (int) $order->total_idr,
                'status' => $this->statuses->slug((string) $order->status),
                'createdAt' => $order->created_at,
            ])->values()->all();
    }

    private function looksLikeInvoice(string $value): bool
    {
        return str_starts_with(strtoupper(trim($value)), 'LF');
    }

    private function phoneVariants(string $value): array
    {
        $raw = trim($value);
        $digits = preg_replace('/\D+/', '', $raw) ?: '';
        if (strlen($digits) < 6) {
            return [];
        }

        $variants = [$digits, '+'.$digits];

        // Jika nomor diawali '+' eksplisit internasional:
        // Cek apakah benar nomor internasional Indonesia (+62) atau negara lain (+81, +1, dsb).
        // Jangan pernah meng-expand nomor internasional non-ID ke variasi lokal Indonesia!
        // Untuk Indonesia (+62), JANGAN masukkan varian '8...' polos ($local) agar tidak collision dengan +81 Jepang.
        if (str_starts_with($raw, '+')) {
            if (str_starts_with($digits, '62') && strlen($digits) > 2) {
                $local = substr($digits, 2);
                $variants[] = '0'.$local;
            }

            return array_values(array_unique($variants));
        }

        // Variasi lokal Indonesia:
        // Hanya ekspansi ke varian Indonesia jika input eksplisit menggunakan format lokal Indo:
        // - Diawali '628...' (khas kode negara Indonesia)
        // - Diawali '08...' (khas nomor lokal domestik Indonesia)
        // JANGAN masukkan varian '8...' polos ($local) ke dalam variants agar nomor Indonesia
        // tidak pernah menabrak nomor internasional (seperti nomor Jepang +81 yang canonicalnya 81...).
        if (str_starts_with($digits, '628') && strlen($digits) >= 10) {
            $local = substr($digits, 2);
            $variants[] = '0'.$local;
            $variants[] = '+'.$digits;
        } elseif (str_starts_with($digits, '08') && strlen($digits) >= 10) {
            $local = substr($digits, 1);
            $variants[] = '62'.$local;
            $variants[] = '+62'.$local;
        }

        return array_values(array_unique($variants));
    }

    private function trackingToken(int $orderId, bool $revealReference): string
    {
        return Crypt::encryptString(json_encode([
            'id' => $orderId,
            'reveal_reference' => $revealReference,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array{id:int,reveal_reference:bool}|null
     */
    private function trackingTokenData(string $token): ?array
    {
        try {
            $payload = json_decode(Crypt::decryptString($token), true, 8, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            return null;
        }

        if (! is_array($payload)) {
            return null;
        }

        $orderId = filter_var($payload['id'] ?? null, FILTER_VALIDATE_INT);
        if (! $orderId || $orderId < 1) {
            return null;
        }

        return [
            'id' => (int) $orderId,
            'reveal_reference' => (bool) ($payload['reveal_reference'] ?? false),
        ];
    }

    private function maskReference(string $reference): string
    {
        if (strlen($reference) <= 9) {
            return substr($reference, 0, 3).'***';
        }

        return substr($reference, 0, 5).'••••'.substr($reference, -4);
    }

    private function maskedDestination(mixed $raw): ?string
    {
        $input = is_string($raw) ? json_decode($raw, true) : $raw;
        if (! is_array($input) || $input === []) {
            return null;
        }

        $parts = [];
        foreach (array_values($input) as $value) {
            $text = trim((string) $value);
            if ($text === '') {
                continue;
            }
            if (strlen($text) <= 4) {
                $parts[] = str_repeat('•', max(2, strlen($text)));
            } else {
                $parts[] = substr($text, 0, 2).str_repeat('•', min(6, max(2, strlen($text) - 4))).substr($text, -2);
            }
        }

        return $parts ? implode(' / ', $parts) : null;
    }

    private function paymentStatus(string $paymentStatus, string $orderStatus, bool $wasPaid): string
    {
        $payment = strtoupper($paymentStatus);
        if ($payment === 'REFUNDED' || in_array(strtoupper($orderStatus), ['REFUND', 'REFUNDED'], true)) {
            return 'refunded';
        }
        if (in_array($payment, ['SETTLEMENT', 'CAPTURE', 'PAID', 'SUCCESS'], true)) {
            return 'paid';
        }
        // Keep the paid flag when a previously paid fulfillment fails.
        if ($wasPaid && $this->statuses->value($orderStatus) === 'FAILED') {
            return 'paid';
        }
        if (in_array($payment, ['EXPIRE', 'EXPIRED'], true) || strtoupper($orderStatus) === 'EXPIRED') {
            return 'expired';
        }
        if (in_array($payment, ['CANCEL', 'CANCELLED'], true) || strtoupper($orderStatus) === 'CANCELLED') {
            return 'cancelled';
        }
        if (in_array($payment, ['DENY', 'DENIED', 'FAILED', 'REJECTED'], true) || strtoupper($orderStatus) === 'FAILED') {
            return 'failed';
        }

        return in_array(strtoupper($orderStatus), ['PAID', 'PROCESSING', 'SUCCESS'], true) ? 'paid' : 'pending';
    }

    private function eventSource(string $eventType): string
    {
        $event = strtoupper($eventType);
        if (str_contains($event, 'PAYMENT')) {
            return 'payment';
        }
        if (str_contains($event, 'FULFILL') || str_contains($event, 'PROCESS')) {
            return 'processing';
        }
        if (str_contains($event, 'DELIVERY') || str_contains($event, 'SUCCESS')) {
            return 'delivery';
        }
        if (str_contains($event, 'ADMIN')) {
            return 'admin';
        }

        return 'system';
    }

    private function eventLabel(string $eventType, mixed $toStatus): string
    {
        if (is_string($toStatus) && $toStatus !== '') {
            return $this->statuses->label($toStatus);
        }

        return match (strtoupper($eventType)) {
            'ORDER_CREATED' => 'Pesanan dibuat',
            default => 'Status pesanan diperbarui',
        };
    }
}
