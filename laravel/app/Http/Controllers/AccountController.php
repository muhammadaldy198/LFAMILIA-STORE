<?php

namespace App\Http\Controllers;

use App\Services\CustomerAuthService;
use App\Services\SecurityGuard;
use App\Support\PhoneNormalizer;
use App\Support\NominalLabel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class AccountController extends Controller
{
    public function show(Request $request, CustomerAuthService $auth): JsonResponse
    {
        $customer = $auth->current($request);
        if (!$customer) {
            return response()->json(['error' => 'Silakan masuk ke akun terlebih dahulu.'], 401, [
                'Cache-Control' => 'no-store',
            ]);
        }

        $topups = DB::table('wallet_topups')
            ->where('customer_id', $customer['id'])
            ->orderByDesc('created_at')
            ->limit(40)
            ->get(['id', 'amount', 'sender_name', 'payment_method', 'proof_url', 'status', 'admin_notes', 'created_at']);

        $transactions = DB::table('wallet_transactions')
            ->where('customer_id', $customer['id'])
            ->orderByDesc('created_at')
            ->limit(60)
            ->get(['id', 'direction', 'amount', 'balance_before', 'balance_after', 'reference', 'description', 'created_at']);

        $orders = DB::table('orders')
            ->where('customer_id', $customer['id'])
            ->orderByDesc('created_at')
            ->limit(50)
            ->get(['id', 'reference_id', 'product_name', 'package_label', 'total', 'payment_status', 'fulfillment_status', 'created_at'])
            ->map(fn ($order) => [
                ...((array) $order),
                'reference_id' => $this->publicReference((string) $order->reference_id),
                'package_label' => NominalLabel::clean((string) $order->product_name, (string) $order->package_label),
            ]);

        return response()->json([
            'customer' => $customer,
            'membership' => $this->membership((string) $customer['id']),
            'topups' => $topups,
            'transactions' => $transactions,
            'orders' => $orders,
            'vouchers' => [],
        ], 200, ['Cache-Control' => 'no-store']);
    }

    public function update(Request $request, CustomerAuthService $auth, SecurityGuard $security): JsonResponse
    {
        $security->assertSameOrigin($request);
        $customer = $auth->current($request);
        if (!$customer) {
            return response()->json(['error' => 'Silakan masuk ke akun terlebih dahulu.'], 401, [
                'Cache-Control' => 'no-store',
            ]);
        }

        try {
            $input = $request->validate([
                'name' => ['required', 'string', 'min:2', 'max:80'],
                'phone' => ['required', 'string', 'max:20'],
                'leaderboardOptIn' => ['required', 'boolean'],
            ]);

            $phone = PhoneNormalizer::whatsapp($input['phone']);
            $currentPhone = (string) $customer['phone'];

            DB::table('customer_users')->where('id', $customer['id'])->update([
                'name' => trim($input['name']),
                'phone' => $phone,
                'phone_verified_at' => $phone === $currentPhone
                    ? DB::raw('phone_verified_at')
                    : null,
                'leaderboard_opt_in' => $input['leaderboardOptIn'] ? 1 : 0,
                'updated_at' => now(),
            ]);

            return response()->json(['ok' => true]);
        } catch (ValidationException $error) {
            return response()->json(['error' => $error->validator->errors()->first()], 400);
        } catch (Throwable $error) {
            return response()->json(['error' => $error->getMessage() ?: 'Profil gagal diperbarui.'], 400);
        }
    }

    /** @return array<string,mixed> */
    private function membership(string $customerId): array
    {
        $lifetime = (int) DB::table('orders')
            ->where('customer_id', $customerId)
            ->where('payment_status', 'paid')
            ->sum('total');

        $user = DB::table('customer_users')->where('id', $customerId)->first([
            'tier_mode', 'tier_override', 'tier_progress_bonus',
        ]);

        $definitions = [
            ['tier' => 'basic', 'label' => 'BASIC', 'min' => 0],
            ['tier' => 'gold', 'label' => 'GOLD', 'min' => 1000000],
            ['tier' => 'diamond', 'label' => 'DIAMOND', 'min' => 10000000],
            ['tier' => 'platinum', 'label' => 'PLATINUM', 'min' => 50000000],
        ];

        $bonus = max(0, (int) ($user?->tier_progress_bonus ?? 0));
        $progress = $lifetime + $bonus;
        $automatic = 'basic';
        foreach ($definitions as $definition) {
            if ($progress >= $definition['min']) {
                $automatic = $definition['tier'];
            }
        }

        $override = in_array($user?->tier_override, ['basic', 'gold', 'diamond', 'platinum', 'mafia'], true)
            ? $user->tier_override
            : null;
        $tier = ($user?->tier_mode === 'manual' && $override) ? $override : $automatic;
        if ($tier === 'mafia') {
            $current = ['tier' => 'mafia', 'label' => 'MAFIA', 'min' => null];
            $next = null;
        } else {
            $index = array_search($tier, array_column($definitions, 'tier'), true);
            $current = $definitions[$index === false ? 0 : $index];
            $next = $definitions[($index === false ? 0 : $index) + 1] ?? null;
        }
        $setting = DB::table('member_tier_settings')->where('tier', $tier)->first();

        return [
            'tier' => $tier,
            'label' => $current['label'],
            'lifetimeSpend' => $lifetime,
            'tierProgress' => $progress,
            'tierProgressBonus' => $bonus,
            'tierMode' => $user?->tier_mode === 'manual' ? 'manual' : 'automatic',
            'tierOverride' => $override,
            'nextTier' => $next['tier'] ?? null,
            'nextTierLabel' => $next['label'] ?? null,
            'nextTarget' => $next['min'] ?? null,
            'remainingToNextTier' => $next ? max(0, (int) $next['min'] - $progress) : 0,
            'setting' => [
                'tier' => $tier,
                'label' => $current['label'],
                'minSpend' => $current['min'],
                'discountPercent' => (float) ($setting?->discount_percent ?? 0),
                'benefits' => (string) ($setting?->benefits ?? ''),
            ],
        ];
    }

    private function publicReference(string $reference): string
    {
        $clean = strtoupper(trim($reference));
        if (!str_contains($clean, '-')) {
            return $clean;
        }

        $parts = explode('-', $clean);
        $token = end($parts) ?: preg_replace('/^LF/', '', $clean);

        return 'LF'.$token;
    }
}
