<?php

namespace App\Http\Controllers;

use App\Models\MembershipTier;
use App\Models\Wallet;
use App\Services\CustomerAccountDeletion;
use App\Services\PaymentRoutingService;
use App\Services\TransactionalEmailService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CustomerAccountController
{
    public function dashboard(Request $request): Response
    {
        $user = $request->user();
        $wallet = Wallet::firstOrCreate(['user_id' => $user->id]);

        return Inertia::render('Account', [
            'customer' => $user->only('id', 'name', 'email', 'phone', 'membership_tier_code'),
            'balanceIdr' => (int) $wallet->balance_idr,
            'orderCount' => DB::table('orders')->where('user_id', $user->id)->count(),
            'ticketCount' => DB::table('support_tickets')->where('user_id', $user->id)->count(),
        ]);
    }

    public function profile(Request $request): Response
    {
        return Inertia::render('Customer/Profile', [
            'customer' => $request->user()->only('name', 'email', 'phone', 'leaderboard_opt_in'),
            'hasPassword' => (bool) $request->user()->password,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['required', 'regex:/^\+?[0-9]{8,16}$/'],
            'current_password' => ['nullable', 'string'],
            'leaderboard_opt_in' => ['required', 'boolean'],
        ]);
        $email = $data['email'];

        if ($email !== $user->email && $user->password
            && (! isset($data['current_password'])
                || ! Hash::check($data['current_password'], $user->password))) {
            throw ValidationException::withMessages(['current_password' => 'Kata sandi saat ini salah.']);
        }

        $oldEmail = $user->email;
        $user->forceFill([
            'name' => $data['name'],
            'email' => $email,
            'phone' => $data['phone'],
            'leaderboard_opt_in' => (bool) $data['leaderboard_opt_in'],
            'email_verified_at' => $email === $user->email ? $user->email_verified_at : null,
        ])->save();

        if ($oldEmail !== $email) {
            DB::table('password_reset_tokens')->where('email', $oldEmail)->delete();
            $user->sendEmailVerificationNotification();
        }

        return back()->with('status', 'Profil diperbarui.');
    }

    public function password(Request $request, TransactionalEmailService $emails): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['nullable', 'string'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ]);
        $user = $request->user();

        if ($user->password && (! isset($data['current_password'])
            || ! Hash::check($data['current_password'], $user->password))) {
            throw ValidationException::withMessages(['current_password' => 'Kata sandi saat ini salah.']);
        }

        $user->forceFill([
            'password' => Hash::make($data['password']),
            'remember_token' => Str::random(60),
        ])->save();
        $request->session()->regenerate();
        if (is_string($user->email)) {
            $emails->queue(
                $user->email,
                'Password LFAMILIA STORE diperbarui',
                'Password akun LFAMILIA STORE Anda baru saja diubah.'
            );
        }

        return back()->with('status', 'Kata sandi diperbarui.');
    }

    public function destroy(Request $request, CustomerAccountDeletion $deletion): RedirectResponse
    {
        $data = $request->validate([
            'confirmation' => ['required', 'in:HAPUS'],
            'password' => ['nullable', 'string'],
        ]);
        $user = $request->user();

        if ($user->password && (! isset($data['password'])
            || ! Hash::check($data['password'], $user->password))) {
            throw ValidationException::withMessages(['password' => 'Kata sandi salah.']);
        }

        $deletion->delete($user);
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/')->with('status', 'Akun dihapus.');
    }

    public function wallet(Request $request, PaymentRoutingService $paymentRouting): Response
    {
        $wallet = Wallet::firstOrCreate(['user_id' => $request->user()->id]);
        $minimumRaw = DB::table('system_settings')->where('key', 'wallet.minimum_topup_idr')->value('value');

        return Inertia::render('Customer/Wallet', [
            'balanceIdr' => (int) $wallet->balance_idr,
            'minimumTopupIdr' => max(1, (int) json_decode((string) $minimumRaw, true)),
            'paymentChannels' => $paymentRouting->publicTopupChannels(),
            'entries' => DB::table('wallet_ledger')->where('wallet_id', $wallet->id)
                ->orderByDesc('id')->select('id', 'amount_idr', 'balance_after_idr', 'source', 'created_at')
                ->paginate(10),
        ]);
    }

    public function codes(Request $request): Response
    {
        $items = DB::table('orders')
            ->join('products', 'products.id', '=', 'orders.product_id')
            ->join('product_packages', 'product_packages.id', '=', 'orders.product_package_id')
            ->where('orders.user_id', $request->user()->id)
            ->where('orders.status', 'SUCCESS')
            ->whereNotNull('orders.delivery_payload')
            ->orderByDesc('orders.id')
            ->limit(100)
            ->get([
                'orders.id', 'orders.order_number', 'orders.delivery_payload', 'orders.updated_at',
                'products.name as product_name', 'product_packages.name as package_name',
            ])
            ->map(function (object $order): ?array {
                $delivery = is_string($order->delivery_payload)
                    ? (json_decode($order->delivery_payload, true) ?: []) : [];
                $code = trim((string) ($delivery['code'] ?? $delivery['serial_number'] ?? ''));
                if ($code === '') {
                    return null;
                }

                return [
                    'id' => (int) $order->id,
                    'order_number' => $order->order_number,
                    'product_name' => $order->product_name,
                    'package_name' => $order->package_name,
                    'code' => $code,
                    'note' => isset($delivery['note']) ? (string) $delivery['note'] : null,
                    'delivered_at' => $order->updated_at,
                ];
            })->filter()->values();

        return Inertia::render('Customer/Codes', ['items' => $items]);
    }

    public function gameAccounts(Request $request): Response
    {
        return Inertia::render('Customer/GameAccounts', [
            'products' => \App\Models\Product::query()
                ->with(['fields' => fn ($query) => $query->orderBy('sort_order')])
                ->where('is_active', true)
                ->whereHas('category', fn ($query) => $query->where('slug', 'game'))
                ->orderBy('name')
                ->get()
                ->map(fn (\App\Models\Product $product): array => [
                    'id' => (int) $product->id,
                    'name' => $product->name,
                    'fields' => $product->fields->map(fn ($field): array => [
                        'field_key' => $field->field_key,
                        'label' => $field->label,
                        'placeholder' => $field->placeholder,
                        'type' => $field->type,
                        'is_required' => (bool) $field->is_required,
                    ])->values()->all(),
                ]),
            'accounts' => DB::table('saved_game_accounts as saved')
                ->join('products', 'products.id', '=', 'saved.product_id')
                ->where('saved.user_id', $request->user()->id)
                ->orderByDesc('saved.id')
                ->get([
                    'saved.id', 'saved.product_id', 'saved.label', 'saved.customer_input',
                    'saved.nickname', 'saved.created_at', 'products.name as product_name',
                ])->map(fn (object $row): array => [
                    'id' => (int) $row->id,
                    'product_id' => (int) $row->product_id,
                    'product_name' => $row->product_name,
                    'label' => $row->label,
                    'nickname' => $row->nickname,
                    'customer_input' => is_string($row->customer_input)
                        ? (json_decode($row->customer_input, true) ?: []) : (array) $row->customer_input,
                    'created_at' => $row->created_at,
                ]),
        ]);
    }

    public function notifications(Request $request): Response
    {
        $userId = (int) $request->user()->id;

        $orderItems = DB::table('order_events as events')
            ->join('orders', 'orders.id', '=', 'events.order_id')
            ->where('orders.user_id', $userId)
            ->orderByDesc('events.id')
            ->limit(40)
            ->get([
                'events.id', 'events.event_type', 'events.to_status', 'events.created_at',
                'orders.order_number',
            ])->map(fn (object $event): array => [
                'key' => 'order-'.$event->id,
                'title' => 'Pesanan '.$event->order_number,
                'detail' => $event->to_status
                    ? 'Status berubah menjadi '.str_replace('_', ' ', (string) $event->to_status)
                    : str_replace(['_', '.'], ' ', (string) $event->event_type),
                'type' => 'ORDER',
                'at' => $event->created_at,
            ]);

        $walletItems = DB::table('wallet_topups')
            ->where('user_id', $userId)
            ->orderByDesc('id')
            ->limit(20)
            ->get(['id', 'amount_idr', 'status', 'created_at'])
            ->map(fn (object $topup): array => [
                'key' => 'topup-'.$topup->id,
                'title' => 'Top up saldo',
                'detail' => 'Rp'.number_format((int) $topup->amount_idr, 0, ',', '.').' · '.str_replace('_', ' ', (string) $topup->status),
                'type' => 'WALLET',
                'at' => $topup->created_at,
            ]);

        $items = $orderItems->concat($walletItems)
            ->sortByDesc(fn (array $item) => (string) $item['at'])
            ->take(50)->values();

        return Inertia::render('Customer/Notifications', ['items' => $items]);
    }

    public function membership(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Customer/Membership', [
            'currentCode' => $user->membership_tier_code,
            'tiers' => MembershipTier::query()->where('is_active', true)->orderBy('rank')
                ->get(['code', 'rank', 'requirements', 'benefits']),
        ]);
    }
}
