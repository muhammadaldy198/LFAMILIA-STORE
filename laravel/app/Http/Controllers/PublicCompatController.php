<?php

namespace App\Http\Controllers;

use App\Exceptions\PromotionQuoteException;
use App\Services\CustomerAuthService;
use App\Services\GatewayPaymentService;
use App\Services\IntegrationConfigService;
use App\Services\PaymentChannelService;
use App\Services\PromotionService;
use App\Services\SecurityGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class PublicCompatController extends Controller
{
    public function accountSummary(Request $request, CustomerAuthService $auth): JsonResponse
    {
        $customer = $auth->current($request);
        return response()->json(['customer' => $customer], $customer ? 200 : 401, ['Cache-Control' => 'no-store']);
    }

    public function membership(Request $request, CustomerAuthService $auth): JsonResponse
    {
        $customer = $auth->current($request);
        if (!$customer) return response()->json(['error' => 'Silakan masuk ke akun terlebih dahulu.'], 401, ['Cache-Control' => 'no-store']);
        return response()->json(['membership' => $this->membershipProfile((string) $customer['id'])], 200, ['Cache-Control' => 'no-store']);
    }

    public function gameAccounts(Request $request, CustomerAuthService $auth, SecurityGuard $security): JsonResponse
    {
        $customer = $auth->current($request);
        if (!$customer) return response()->json(['error' => 'Silakan masuk ke akun terlebih dahulu.'], 401, ['Cache-Control' => 'no-store']);

        if ($request->isMethod('get')) {
            $product = trim((string) $request->query('product', ''));
            $query = DB::table('customer_game_accounts as a')
                ->join('products as p', 'p.slug', '=', 'a.product_slug')
                ->where('a.customer_id', $customer['id'])
                ->when($product !== '', fn ($q) => $q->where('a.product_slug', $product))
                ->orderByDesc('a.updated_at')
                ->get(['a.id','a.product_slug','a.label','a.values_json','a.nickname','a.updated_at','p.name as product_name']);

            return response()->json(['accounts' => $query->map(fn ($row) => [
                'id' => (string) $row->id,
                'productSlug' => (string) $row->product_slug,
                'productName' => (string) $row->product_name,
                'label' => (string) $row->label,
                'nickname' => $row->nickname,
                'values' => $this->jsonArray((string) $row->values_json),
                'updatedAt' => (string) $row->updated_at,
            ])->values()->all()], 200, ['Cache-Control' => 'no-store']);
        }

        $security->assertSameOrigin($request);
        try {
            if ($request->isMethod('delete')) {
                $id = trim((string) $request->query('id', ''));
                if (!Str::isUuid($id)) throw new RuntimeException('ID akun game tidak valid.');
                DB::table('customer_game_accounts')->where('id', $id)->where('customer_id', $customer['id'])->delete();
                return response()->json(['ok' => true]);
            }

            $input = $request->validate([
                'id' => [$request->isMethod('patch') ? 'required' : 'nullable', 'uuid'],
                'productSlug' => ['required','regex:/^[a-z0-9-]{2,80}$/'],
                'label' => ['required','string','min:2','max:60'],
                'values' => ['required','array','min:1','max:10'],
                'values.*.id' => ['required','string','min:1','max:60'],
                'values.*.value' => ['nullable','string','max:300'],
                'nickname' => ['nullable','string','max:100'],
            ]);
            $product = DB::table('products')->where('slug', $input['productSlug'])->where('is_active', 1)->first([
                'slug','category','input_fields_json','input_label','needs_server',
            ]);
            if (!$product || strtolower((string) $product->category) !== 'game') throw new RuntimeException('Produk game tidak ditemukan.');

            $fields = $this->productFields($product);
            $submitted = collect($input['values'])->keyBy('id');
            $values = [];
            foreach ($fields as $field) {
                $value = trim((string) data_get($submitted->get($field['id']), 'value', ''));
                if (($field['required'] ?? true) && $value === '') throw new RuntimeException($field['label'].' wajib diisi.');
                if ($value !== '') $values[] = ['id'=>$field['id'],'label'=>$field['label'],'value'=>$value];
            }

            if ($request->isMethod('patch')) {
                $changed = DB::table('customer_game_accounts')
                    ->where('id', $input['id'])->where('customer_id', $customer['id'])
                    ->update([
                        'product_slug'=>$input['productSlug'],
                        'label'=>trim($input['label']),
                        'values_json'=>json_encode($values, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        'nickname'=>trim((string) ($input['nickname'] ?? '')) ?: null,
                        'updated_at'=>now(),
                    ]);
                if (!$changed) throw new RuntimeException('Akun game tidak ditemukan.');
                return response()->json(['ok'=>true]);
            }

            $id = (string) Str::uuid();
            DB::table('customer_game_accounts')->insert([
                'id'=>$id,
                'customer_id'=>$customer['id'],
                'product_slug'=>$input['productSlug'],
                'label'=>trim($input['label']),
                'values_json'=>json_encode($values, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'nickname'=>trim((string) ($input['nickname'] ?? '')) ?: null,
                'created_at'=>now(),
                'updated_at'=>now(),
            ]);
            return response()->json(['ok'=>true,'id'=>$id], 201);
        } catch (ValidationException $error) {
            return response()->json(['error'=>$error->validator->errors()->first()], 400);
        } catch (RuntimeException $error) {
            return response()->json(['error'=>$error->getMessage()], 400);
        }
    }

    public function leaderboard(Request $request): JsonResponse
    {
        $period = $request->query('period') === 'all' ? 'all' : 'month';
        $query = DB::table('customer_users as u')
            ->join('orders as o', 'o.customer_id', '=', 'u.id')
            ->where('u.is_active', 1)
            ->where('u.leaderboard_opt_in', 1)
            ->where('o.payment_status', 'paid');
        if ($period === 'month') $query->where('o.created_at', '>=', now()->startOfMonth());

        $rows = $query->groupBy('u.id','u.name')
            ->orderByDesc(DB::raw('SUM(o.total)'))
            ->orderByDesc(DB::raw('COUNT(o.id)'))
            ->limit(20)
            ->get([
                'u.name',
                DB::raw('COUNT(o.id) as order_count'),
                DB::raw('COALESCE(SUM(o.total),0) as total_spent'),
            ]);

        return response()->json([
            'period'=>$period,
            'entries'=>$rows->values()->map(fn ($row, $index) => [
                'rank'=>$index + 1,
                'name'=>$this->leaderboardName((string) $row->name),
                'orderCount'=>(int) $row->order_count,
                'totalSpent'=>(int) $row->total_spent,
            ])->all(),
        ], 200, ['Cache-Control'=>'public, max-age=60']);
    }

    public function paymentMethods(
        IntegrationConfigService $integrations,
        GatewayPaymentService $gateway,
        PaymentChannelService $channels,
    ): JsonResponse {
        $activeGateways = DB::table('payment_gateway_settings')->pluck('is_active','gateway');
        $rows = DB::table('payment_channels')->where('is_active', 1)->orderBy('sort_order')->orderBy('id')->get();
        $result = [];

        foreach ($rows as $row) {
            if (!(bool) ($activeGateways[$row->gateway] ?? false)) continue;
            $config = $this->stringMap((string) $row->gateway_config_json);
            $config = ['customerFeeEnabled'=>'true','customerFeeBps'=>'0','customerFeeFixed'=>'0', ...$config];
            $ready = $gateway->readiness((string) $row->gateway, (string) $row->method, (string) $row->channel, $config);
            if (!($ready['ready'] ?? false)) continue;
            if ($channels->paymentType((string) $row->gateway, (string) $row->method, (string) $row->channel, $config) === null) continue;

            $feeEnabled = filter_var($config['customerFeeEnabled'] ?? 'true', FILTER_VALIDATE_BOOLEAN);
            $result[] = [
                'method'=>(string) $row->method,
                'channel'=>(string) $row->channel,
                'name'=>(string) $row->name,
                'description'=>(string) $row->description,
                'customerFeeEnabled'=>$feeEnabled,
                'customerFeeBps'=>$feeEnabled ? max(0, (int) ($config['customerFeeBps'] ?? 0)) : 0,
                'customerFeeFixed'=>$feeEnabled ? max(0, (int) ($config['customerFeeFixed'] ?? 0)) : 0,
                ...($row->image_url ? ['imageUrl'=>(string) $row->image_url] : []),
            ];
        }

        return response()->json(['channels'=>$result], 200, [
            'Cache-Control'=>'public, max-age=10, s-maxage=10, stale-while-revalidate=20',
        ]);
    }

    public function promotions(): JsonResponse
    {
        $now = now();
        $vouchers = DB::table('discount_vouchers')
            ->where('is_active', 1)->where('starts_at','<=',$now)->where('ends_at','>=',$now)
            ->where(fn ($q) => $q->whereNull('usage_limit')->orWhereRaw('used_count + reserved_count < usage_limit'))
            ->orderBy('ends_at')->get()
            ->map(fn ($row) => [
                'id'=>(int) $row->id,'code'=>(string) $row->code,'name'=>(string) $row->name,
                'description'=>(string) ($row->description ?? ''),'discountType'=>(string) $row->discount_type,
                'discountValue'=>(int) $row->discount_value,'minPurchase'=>(int) $row->min_purchase,
                'maxDiscount'=>$row->max_discount === null ? null : (int) $row->max_discount,
                'usageLimit'=>$row->usage_limit === null ? null : (int) $row->usage_limit,
                'usedCount'=>(int) $row->used_count,'startsAt'=>(string) $row->starts_at,'endsAt'=>(string) $row->ends_at,
                'isActive'=>(bool) $row->is_active,
            ])->values()->all();
        $flashSales = DB::table('flash_sales')
            ->where('is_active', 1)->where('starts_at','<=',$now)->where('ends_at','>=',$now)
            ->where(fn ($q) => $q->whereNull('stock_limit')->orWhereRaw('sold_count + reserved_count < stock_limit'))
            ->orderBy('ends_at')->get()
            ->map(fn ($row) => [
                'id'=>(int) $row->id,'productSlug'=>(string) $row->product_slug,'packageSku'=>(string) $row->package_sku,
                'salePrice'=>(int) $row->sale_price,'badge'=>(string) ($row->badge ?? ''),
                'startsAt'=>(string) $row->starts_at,'endsAt'=>(string) $row->ends_at,
                'stockLimit'=>$row->stock_limit === null ? null : (int) $row->stock_limit,
                'soldCount'=>(int) $row->sold_count,'isActive'=>(bool) $row->is_active,
            ])->values()->all();
        return response()->json(['vouchers'=>$vouchers,'flashSales'=>$flashSales], 200, [
            'Cache-Control'=>'public, max-age=30, s-maxage=60, stale-while-revalidate=120',
        ]);
    }

    public function promotionQuote(
        Request $request,
        CustomerAuthService $auth,
        PromotionService $promotions,
        SecurityGuard $security,
    ): JsonResponse {
        $security->assertSameOrigin($request);
        try {
            $input = $request->validate([
                'productSlug'=>['required','string','min:2','max:80'],
                'packageSku'=>['required','string','min:2','max:100'],
                'voucherCode'=>['nullable','string','max:40'],
                'quantity'=>['nullable','integer','min:1','max:5'],
            ]);
            $item = DB::table('product_packages as pp')->join('products as p','p.id','=','pp.product_id')
                ->where('p.slug',$input['productSlug'])->where('pp.sku',$input['packageSku'])
                ->where('p.is_active',1)->where('pp.is_active',1)
                ->first(['p.slug as product_slug','pp.sku as package_sku','pp.price']);
            if (!$item) return response()->json(['error'=>'Produk atau nominal tidak tersedia.'], 404);
            $customer = $auth->current($request);
            return response()->json($promotions->quote(
                (string) $item->product_slug,
                (string) $item->package_sku,
                (int) $item->price,
                trim((string) ($input['voucherCode'] ?? '')) ?: null,
                $customer ? (string) $customer['id'] : null,
                (int) ($input['quantity'] ?? 1),
            ));
        } catch (ValidationException $error) {
            return response()->json(['error'=>$error->validator->errors()->first()], 400);
        } catch (PromotionQuoteException|RuntimeException $error) {
            return response()->json(['error'=>$error->getMessage()], 400);
        }
    }

    public function paymentPageSettings(): JsonResponse
    {
        return response()->json(['settings'=>$this->readPaymentPageSettings()], 200, [
            'Cache-Control'=>'public, max-age=30, s-maxage=60, stale-while-revalidate=120',
        ]);
    }

    public function media(string $key)
    {
        if (!preg_match('/^media-[0-9a-f-]{36}\.(?:jpg|png|webp|gif)$/i', $key)) abort(404);
        $row = DB::table('media_assets')->where('media_key',$key)->first(['content_type','data','etag']);
        if (!$row) abort(404);
        return response($row->data, 200, [
            'Content-Type'=>(string) $row->content_type,
            'Cache-Control'=>'public, max-age=31536000, immutable',
            'ETag'=>'"'.((string) $row->etag).'"',
            'X-Content-Type-Options'=>'nosniff',
        ]);
    }

    private function membershipProfile(string $customerId): array
    {
        $lifetime = (int) DB::table('orders')->where('customer_id',$customerId)->where('payment_status','paid')->sum('total');
        $user = DB::table('customer_users')->where('id',$customerId)->first(['tier_mode','tier_override','tier_progress_bonus']);
        $defs = [
            ['tier'=>'basic','label'=>'BASIC','min'=>0],
            ['tier'=>'gold','label'=>'GOLD','min'=>1000000],
            ['tier'=>'diamond','label'=>'DIAMOND','min'=>10000000],
            ['tier'=>'platinum','label'=>'PLATINUM','min'=>50000000],
        ];
        $bonus = max(0,(int)($user?->tier_progress_bonus ?? 0));
        $progress = $lifetime + $bonus;
        $automatic = 'basic';
        foreach ($defs as $def) if ($progress >= $def['min']) $automatic = $def['tier'];
        $override = in_array($user?->tier_override,['basic','gold','diamond','platinum','mafia'],true) ? $user->tier_override : null;
        $tier = ($user?->tier_mode === 'manual' && $override) ? $override : $automatic;
        if ($tier === 'mafia') {
            $current = ['tier'=>'mafia','label'=>'MAFIA','min'=>null];
            $next = null;
        } else {
            $idx = array_search($tier,array_column($defs,'tier'),true);
            $current = $defs[$idx === false ? 0 : $idx];
            $next = $defs[($idx === false ? 0 : $idx)+1] ?? null;
        }
        $setting = DB::table('member_tier_settings')->where('tier',$tier)->first();
        return [
            'tier'=>$tier,'label'=>$current['label'],'lifetimeSpend'=>$lifetime,'tierProgress'=>$progress,
            'tierProgressBonus'=>$bonus,'tierMode'=>$user?->tier_mode === 'manual' ? 'manual' : 'automatic',
            'tierOverride'=>$override,'nextTier'=>$next['tier'] ?? null,'nextTierLabel'=>$next['label'] ?? null,
            'nextTarget'=>$next['min'] ?? null,'remainingToNextTier'=>$next ? max(0,$next['min']-$progress) : 0,
            'setting'=>[
                'tier'=>$tier,'label'=>$current['label'],'minSpend'=>$current['min'],
                'discountPercent'=>(float)($setting?->discount_percent ?? 0),'benefits'=>(string)($setting?->benefits ?? ''),
            ],
        ];
    }

    private function productFields(object $product): array
    {
        $fields = $this->jsonArray((string) ($product->input_fields_json ?? '[]'));
        $normalized = [];
        foreach ($fields as $field) {
            if (!is_array($field)) continue;
            $id = trim((string)($field['id'] ?? ''));
            $label = trim((string)($field['label'] ?? ''));
            if ($id !== '' && $label !== '') $normalized[] = ['id'=>$id,'label'=>$label,'required'=>($field['required'] ?? true) !== false];
        }
        if ($normalized !== []) return $normalized;
        return [
            ['id'=>'account-id','label'=>(string)($product->input_label ?: 'User ID'),'required'=>true],
            ...((bool)$product->needs_server ? [['id'=>'server-zone','label'=>'Server / Zone ID','required'=>true]] : []),
        ];
    }

    private function readPaymentPageSettings(): array
    {
        $defaults = [
            'accentColor'=>'#b9ff35','headerImageUrl'=>'','eyebrow'=>'LFAMILIA PAYMENT',
            'pendingTitle'=>'Selesaikan pembayaran','paidTitle'=>'Pembayaran berhasil','failedTitle'=>'Pembayaran tidak aktif',
            'subtitle'=>'Pembayaran diproses aman oleh LFAMILIA STORE.','invoiceNoticeTitle'=>'Simpan invoice sebelum membayar',
            'invoiceNoticeText'=>'Invoice diperlukan untuk mengecek transaksi jika halaman pembayaran tertutup atau terjadi kendala.',
            'pendingStatusText'=>'Status diperiksa otomatis setiap 3 detik.',
            'paidStatusText'=>'Pembayaran sudah diterima. Status pesanan akan diperbarui otomatis.',
            'failedStatusText'=>'Transaksi ini tidak dapat dilanjutkan. Buat checkout baru bila diperlukan.',
            'payButtonText'=>'Bayar Sekarang','checkStatusButtonText'=>'Cek status','checkInvoiceButtonText'=>'Cek invoice',
            'supportText'=>'Butuh bantuan pembayaran?','supportUrl'=>'/contact',
            'showStoreBrand'=>true,'showInvoiceNotice'=>true,'showOrderSummary'=>true,'showStatusBox'=>true,'showSupport'=>true,
        ];
        $raw = DB::table('payment_page_settings')->where('id',1)->value('config_json');
        $decoded = is_string($raw) ? json_decode($raw,true) : null;
        return is_array($decoded) ? [...$defaults, ...array_intersect_key($decoded,$defaults)] : $defaults;
    }

    private function leaderboardName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($parts) < 2) return $parts[0] ?? 'Pelanggan';
        return $parts[0].' '.strtoupper(substr(end($parts) ?: '',0,1)).'.';
    }

    private function jsonArray(string $json): array
    {
        $value = json_decode($json ?: '[]', true);
        return is_array($value) ? array_values($value) : [];
    }

    private function stringMap(string $json): array
    {
        $value = json_decode($json ?: '{}', true);
        if (!is_array($value)) return [];
        $out = [];
        foreach ($value as $key=>$item) if (is_string($key) && is_scalar($item)) $out[$key]=(string)$item;
        return $out;
    }
}
