<?php

namespace App\Http\Controllers;

use App\Services\FulfillmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class AdminFulfillmentController
{
    public function index(): Response
    {
        $attempts = DB::table('fulfillment_attempts as attempts')
            ->join('orders', 'orders.id', '=', 'attempts.order_id')
            ->join('products', 'products.id', '=', 'orders.product_id')
            ->join('product_packages', 'product_packages.id', '=', 'orders.product_package_id')
            ->join('providers', 'providers.id', '=', 'attempts.provider_id')
            ->orderByDesc('attempts.id')
            ->limit(100)
            ->get([
                'attempts.id', 'attempts.attempt_no', 'attempts.external_reference',
                'attempts.status', 'attempts.provider_status', 'attempts.provider_rc',
                'attempts.serial_number', 'attempts.price_idr', 'attempts.safe_to_failover',
                'attempts.last_error', 'attempts.last_checked_at', 'attempts.created_at',
                'orders.order_number', 'orders.status as order_status', 'orders.customer_input',
                'orders.snapshot', 'products.name as product_name',
                'products.manual_instructions', 'product_packages.name as package_name',
                'providers.code as provider_code',
            ])->map(function (object $attempt): array {
                $customerInput = json_decode((string) $attempt->customer_input, true) ?: [];
                $snapshot = json_decode((string) $attempt->snapshot, true) ?: [];

                return [
                    'id' => (int) $attempt->id,
                    'attempt_no' => (int) $attempt->attempt_no,
                    'external_reference' => $attempt->external_reference,
                    'status' => $attempt->status,
                    'provider_status' => $attempt->provider_status,
                    'provider_rc' => $attempt->provider_rc,
                    'serial_number' => $attempt->serial_number,
                    'price_idr' => $attempt->price_idr,
                    'safe_to_failover' => (bool) $attempt->safe_to_failover,
                    'last_error' => $attempt->last_error,
                    'last_checked_at' => $attempt->last_checked_at,
                    'created_at' => $attempt->created_at,
                    'order_number' => $attempt->order_number,
                    'order_status' => $attempt->order_status,
                    'product_name' => $attempt->product_name,
                    'package_name' => $attempt->package_name,
                    'provider_code' => $attempt->provider_code,
                    'customer_input' => $customerInput,
                    'manual_instructions' => $attempt->manual_instructions,
                    'fulfillment_mode' => data_get($snapshot, 'product.fulfillment_mode'),
                ];
            });

        return Inertia::render('Admin/Fulfillment', ['attempts' => $attempts]);
    }

    public function completeManual(
        Request $request,
        int $attemptId,
        FulfillmentService $fulfillment,
    ): RedirectResponse {
        $data = $request->validate([
            'delivery_code' => ['nullable', 'string', 'max:2000'],
            'note' => ['nullable', 'string', 'max:4000'],
        ]);
        $admin = $request->user('admin');

        $before = DB::table('fulfillment_attempts')->where('id', $attemptId)->firstOrFail();
        $fulfillment->completeManual(
            $attemptId,
            $data['delivery_code'] ?? null,
            $data['note'] ?? null,
            (int) $admin->id
        );
        $this->audit($request, 'fulfillment.manual.completed', $attemptId, (array) $before);

        return back();
    }

    public function failManual(
        Request $request,
        int $attemptId,
        FulfillmentService $fulfillment,
    ): RedirectResponse {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:4000'],
        ]);
        $admin = $request->user('admin');

        $before = DB::table('fulfillment_attempts')->where('id', $attemptId)->firstOrFail();
        $fulfillment->failManual($attemptId, $data['reason'], (int) $admin->id);
        $this->audit($request, 'fulfillment.manual.failed', $attemptId, (array) $before);

        return back();
    }

    public function retry(
        Request $request,
        int $attemptId,
        FulfillmentService $fulfillment,
    ): RedirectResponse {
        $before = DB::table('fulfillment_attempts')->where('id', $attemptId)->firstOrFail();
        $fulfillment->retrySafeAttempt($attemptId);
        $this->audit($request, 'fulfillment.retry.requested', $attemptId, (array) $before);

        return back();
    }

    private function audit(Request $request, string $action, int $attemptId, array $before): void
    {
        $admin = $request->user('admin');
        $after = DB::table('fulfillment_attempts')->where('id', $attemptId)->first();

        DB::table('audit_logs')->insert([
            'actor_type' => 'admin_user',
            'actor_id' => (string) $admin->id,
            'actor_role' => $admin->role,
            'action' => $action,
            'target_type' => 'fulfillment_attempt',
            'target_id' => (string) $attemptId,
            'before' => json_encode($before, JSON_THROW_ON_ERROR),
            'after' => $after ? json_encode((array) $after, JSON_THROW_ON_ERROR) : null,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'correlation_id' => (string) Str::uuid(),
            'created_at' => now(),
        ]);
    }
}
