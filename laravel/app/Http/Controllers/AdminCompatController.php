<?php

namespace App\Http\Controllers;

use App\Services\AdminAuthService;
use App\Services\AdminDigiflazzService;
use App\Services\IntegrationConfigService;
use App\Services\SecurityGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class AdminCompatController extends Controller
{
    public function dashboardIntegrations(
        Request $request,
        AdminAuthService $auth,
        IntegrationConfigService $integrations,
        AdminDigiflazzService $digiflazz,
    ): JsonResponse {
        try {
            $auth->require($request, 'admin');
            $overview = $integrations->integrationOverview();
            $payments = $integrations->paymentOverview();
            $gatewayRows = DB::table('payment_gateway_settings')->pluck('is_active', 'gateway');
            $profiles = collect($overview['profiles'] ?? []);
            $kokin = $profiles->first(fn ($item) =>
                ($item['provider'] ?? null) === 'kokinpay'
                && ($item['environment'] ?? null) === 'global'
            );
            $digiflazzReady = false;
            try { $digiflazzReady = (bool) ($digiflazz->readiness()['ready'] ?? false); } catch (Throwable) {}

            $status = static function (bool $ready, bool $active, ?string $environment = null): string {
                if (!$ready) return 'Perlu konfigurasi';
                $state = $active ? 'Aktif' : 'Standby';
                if (!$environment) return $state;
                $label = match ($environment) {
                    'production' => 'Production',
                    'sandbox' => 'Sandbox',
                    'development' => 'Development',
                    default => $environment,
                };
                return $state.' · '.$label;
            };

            $digiflazzEnvironment = (string) data_get($overview, 'selections.digiflazzEnvironment', 'development');
            $dokuActive = (bool) ($gatewayRows['doku'] ?? false);
            $midtransActive = (bool) ($gatewayRows['midtrans'] ?? false);
            $kokinReady = (bool) ($kokin['configured'] ?? false) && !($kokin['decryptionError'] ?? false);

            return response()->json(['items' => [
                [
                    'id'=>'digiflazz','name'=>'Digiflazz API','ready'=>$digiflazzReady,'active'=>true,
                    'environment'=>$digiflazzEnvironment,'status'=>$status($digiflazzReady,true,$digiflazzEnvironment),
                ],
                [
                    'id'=>'kokinpay','name'=>'KokinPay Nickname','ready'=>$kokinReady,'active'=>$kokinReady,
                    'environment'=>null,'status'=>$status($kokinReady,$kokinReady),
                ],
                [
                    'id'=>'doku-checkout','name'=>'DOKU Checkout','ready'=>(bool)$payments['dokuCheckoutConfigured'],
                    'active'=>$dokuActive,'environment'=>$payments['dokuEnvironment'],
                    'status'=>$status((bool)$payments['dokuCheckoutConfigured'],$dokuActive,(string)$payments['dokuEnvironment']),
                ],
                [
                    'id'=>'midtrans-snap','name'=>'Midtrans Snap','ready'=>(bool)$payments['midtransSnapConfigured'],
                    'active'=>$midtransActive,'environment'=>$payments['midtransEnvironment'],
                    'status'=>$status((bool)$payments['midtransSnapConfigured'],$midtransActive,(string)$payments['midtransEnvironment']),
                ],
            ]], 200, ['Cache-Control'=>'no-store']);
        } catch (RuntimeException $error) {
            return $this->accessError($error);
        }
    }

    public function integrations(
        Request $request,
        AdminAuthService $auth,
        SecurityGuard $security,
        IntegrationConfigService $integrations,
        AdminDigiflazzService $digiflazz,
    ): JsonResponse {
        try {
            $access = $auth->require($request, 'owner');
            if ($request->isMethod('get')) {
                return response()->json($integrations->integrationOverview(), 200, ['Cache-Control'=>'no-store']);
            }
            $security->assertSameOrigin($request);
            $input = $request->validate([
                'action'=>['required','in:save_profile,save_selections,test_relay,test_digiflazz'],
                'provider'=>['nullable','in:digiflazz,kokinpay,google,resend,turnstile,relay,security'],
                'mode'=>['nullable','in:direct,service'],
                'environment'=>['nullable','in:sandbox,production,development,global'],
                'values'=>['nullable','array','max:32'],
                'values.*'=>['nullable','string','max:12000'],
                'clearFields'=>['nullable','array','max:32'],
                'clearFields.*'=>['string','max:80'],
                'selections'=>['nullable','array'],
                'selections.digiflazzEnvironment'=>['nullable','in:development,production'],
            ]);

            if ($input['action'] === 'test_digiflazz') {
                return response()->json([
                    'ok'=>true,
                    'digiflazz'=>['connected'=>true,'balance'=>$digiflazz->balance()],
                ], 200, ['Cache-Control'=>'no-store']);
            }

            if ($input['action'] === 'test_relay') {
                $profile = [];
                try { $profile = $integrations->profile('relay','service','global'); } catch (Throwable) {}
                $origin = rtrim(trim((string)($profile['digiflazzOrigin'] ?? '')), '/');
                if ($origin === '') throw new RuntimeException('Origin relay DigiFlazz belum disimpan.');
                $target = $origin.'/health';
                try {
                    $response = Http::acceptJson()->timeout(8)->get($target);
                    return response()->json([
                        'ok'=>true,
                        'relay'=>[[
                            'provider'=>'digiflazz',
                            'label'=>'Relay DigiFlazz',
                            'connected'=>$response->successful(),
                            'status'=>$response->status(),
                            'message'=>$response->successful() ? 'Relay dapat dijangkau.' : 'Relay merespons error.',
                        ]],
                    ], 200, ['Cache-Control'=>'no-store']);
                } catch (Throwable) {
                    return response()->json([
                        'ok'=>true,
                        'relay'=>[[
                            'provider'=>'digiflazz','label'=>'Relay DigiFlazz','connected'=>false,
                            'status'=>null,'message'=>'Relay tidak dapat dijangkau.',
                        ]],
                    ], 200, ['Cache-Control'=>'no-store']);
                }
            }

            if ($input['action'] === 'save_selections') {
                $environment = (string) data_get($input, 'selections.digiflazzEnvironment', '');
                if ($environment !== '') $integrations->saveSetting('digiflazz_environment', $environment);
            } else {
                $provider = (string)($input['provider'] ?? '');
                $mode = (string)($input['mode'] ?? '');
                $environment = (string)($input['environment'] ?? '');
                $values = [];
                foreach (($input['values'] ?? []) as $key=>$value) {
                    if (is_string($key) && is_string($value)) $values[$key]=$value;
                }
                $integrations->saveIntegrationProfile(
                    $provider,
                    $mode,
                    $environment,
                    $values,
                    array_values(array_filter($input['clearFields'] ?? [], 'is_string')),
                );
            }

            return response()->json(['ok'=>true,'overview'=>$integrations->integrationOverview()]);
        } catch (ValidationException $error) {
            return response()->json(['error'=>$error->validator->errors()->first()], 400);
        } catch (RuntimeException $error) {
            if ($this->isAccessError($error)) return $this->accessError($error);
            return response()->json(['error'=>$error->getMessage()], 400);
        } catch (Throwable $error) {
            return response()->json(['error'=>$error->getMessage() ?: 'Pengaturan integrasi gagal diproses.'], 400);
        }
    }

    public function mediaUpload(Request $request, AdminAuthService $auth, SecurityGuard $security): JsonResponse
    {
        $security->assertSameOrigin($request);
        try {
            $auth->require($request, 'staff');
            $input = $request->validate(['file'=>['required','file','mimes:jpg,jpeg,png,webp,gif','max:5120']]);
            $file = $input['file'];
            $mime = (string) $file->getMimeType();
            $extension = strtolower((string) $file->guessExtension());
            if ($extension === 'jpeg') $extension = 'jpg';
            if (!in_array($extension,['jpg','png','webp','gif'],true)) throw new RuntimeException('Format gambar tidak didukung.');
            $data = file_get_contents($file->getRealPath());
            if ($data === false) throw new RuntimeException('Gambar gagal dibaca.');
            $key = 'media-'.Str::uuid().'.'.$extension;
            DB::table('media_assets')->insert([
                'media_key'=>$key,
                'content_type'=>$mime ?: 'application/octet-stream',
                'data'=>$data,
                'etag'=>hash('sha256',$data),
                'original_name'=>$file->getClientOriginalName(),
                'created_at'=>now(),
            ]);
            return response()->json(['url'=>'/api/media/'.$key], 201);
        } catch (ValidationException $error) {
            return response()->json(['error'=>$error->validator->errors()->first()], 400);
        } catch (RuntimeException $error) {
            if ($this->isAccessError($error)) return $this->accessError($error);
            return response()->json(['error'=>$error->getMessage()], 400);
        }
    }

    public function nicknameTools(
        Request $request,
        AdminAuthService $auth,
        SecurityGuard $security,
        IntegrationConfigService $integrations,
    ): JsonResponse {
        $security->assertSameOrigin($request);
        try {
            $auth->require($request, 'admin');
            $input = $request->validate([
                'action'=>['required','in:game,region,pln'],
                'gameCode'=>['nullable','regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/','max:100'],
                'userId'=>['nullable','string','min:2','max:80'],
                'server'=>['nullable','string','max:40'],
                'customerNumber'=>['nullable','regex:/^\d{11,12}$/'],
            ]);
            $runtime = $integrations->kokinpayRuntime();
            $apiKey = $runtime['apiKey'];
            $base = $runtime['baseUrl'];
            if ($apiKey === '' || $base === '') throw new RuntimeException('KokinPay belum dikonfigurasi lengkap.');

            if ($input['action'] === 'pln') {
                if (empty($input['customerNumber'])) throw new RuntimeException('Nomor meter PLN wajib diisi.');
                $data = $this->kokinpayPost($base.'/v1/check-pln', [
                    'api_key'=>$apiKey,
                    'customer_number'=>$input['customerNumber'],
                ]);
                $name = trim((string) data_get($data,'data.customer_name',data_get($data,'data.name','')));
                if ($name === '') throw new RuntimeException('Layanan pengecekan PLN tidak mengembalikan nama pelanggan.');
                return response()->json(['ok'=>true,'action'=>'pln','customerName'=>$name], 200, ['Cache-Control'=>'no-store']);
            }

            if (empty($input['userId'])) throw new RuntimeException('User ID wajib diisi.');
            $gameCode = $input['action'] === 'region' ? 'mobile-legends' : trim((string)($input['gameCode'] ?? ''));
            if ($gameCode === '') throw new RuntimeException('Game Code wajib diisi.');
            $body = ['api_key'=>$apiKey,'id'=>$input['userId'],'game_code'=>$gameCode];
            if (!empty($input['server'])) $body['server']=$input['server'];
            $nicknameData = $this->kokinpayPost($base.'/v1/check-nickname',$body);
            $nickname = trim((string) data_get($nicknameData,'data.nickname',data_get($nicknameData,'data.username','')));
            if ($nickname === '') throw new RuntimeException('Layanan pengecekan tidak mengembalikan nickname.');
            $region = trim((string) data_get($nicknameData,'data.region',data_get($nicknameData,'data.country','')));

            if ($gameCode === 'mobile-legends') {
                if (empty($input['server'])) throw new RuntimeException('Server / Zone ID wajib diisi.');
                $regionData = $this->kokinpayPost($base.'/v1/check-region', [
                    'api_key'=>$apiKey,'id'=>$input['userId'],'server'=>$input['server'],
                ]);
                $region = trim((string) data_get($regionData,'data.region',data_get($regionData,'data.country','')));
                if ($region === '') throw new RuntimeException('Layanan pengecekan tidak mengembalikan region Mobile Legends.');
            }
            return response()->json([
                'ok'=>true,'action'=>$input['action'],'nickname'=>$nickname,'region'=>$region ?: null,
            ], 200, ['Cache-Control'=>'no-store']);
        } catch (ValidationException $error) {
            return response()->json(['error'=>$error->validator->errors()->first()], 400);
        } catch (RuntimeException $error) {
            if ($this->isAccessError($error)) return $this->accessError($error);
            return response()->json(['error'=>$error->getMessage()], 400);
        } catch (Throwable $error) {
            return response()->json(['error'=>$error->getMessage() ?: 'Pemeriksaan tidak dapat diproses.'], 503);
        }
    }

    public function paymentPage(Request $request, AdminAuthService $auth, SecurityGuard $security): JsonResponse
    {
        try {
            $auth->require($request, 'admin');
            if ($request->isMethod('get')) {
                return response()->json(['settings'=>$this->readPaymentPageSettings()]);
            }
            $security->assertSameOrigin($request);
            $input = $request->validate([
                'accentColor'=>['required','regex:/^#[0-9a-fA-F]{6}$/'],
                'headerImageUrl'=>['nullable','string','max:500'],
                'eyebrow'=>['required','string','min:1','max:60'],
                'pendingTitle'=>['required','string','min:2','max:100'],
                'paidTitle'=>['required','string','min:2','max:100'],
                'failedTitle'=>['required','string','min:2','max:100'],
                'subtitle'=>['nullable','string','max:240'],
                'invoiceNoticeTitle'=>['required','string','min:2','max:100'],
                'invoiceNoticeText'=>['nullable','string','max:240'],
                'pendingStatusText'=>['nullable','string','max:240'],
                'paidStatusText'=>['nullable','string','max:240'],
                'failedStatusText'=>['nullable','string','max:240'],
                'payButtonText'=>['required','string','min:1','max:40'],
                'checkStatusButtonText'=>['required','string','min:1','max:40'],
                'checkInvoiceButtonText'=>['required','string','min:1','max:40'],
                'supportText'=>['nullable','string','max:120'],
                'supportUrl'=>['nullable','string','max:500'],
                'showStoreBrand'=>['required','boolean'],
                'showInvoiceNotice'=>['required','boolean'],
                'showOrderSummary'=>['required','boolean'],
                'showStatusBox'=>['required','boolean'],
                'showSupport'=>['required','boolean'],
            ]);
            DB::table('payment_page_settings')->updateOrInsert(
                ['id'=>1],
                ['config_json'=>json_encode($input,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),'updated_at'=>now()],
            );
            return response()->json(['ok'=>true,'settings'=>$input]);
        } catch (ValidationException $error) {
            return response()->json(['error'=>$error->validator->errors()->first()], 400);
        } catch (RuntimeException $error) {
            return $this->accessError($error);
        }
    }

    public function productContent(Request $request, AdminAuthService $auth, SecurityGuard $security): JsonResponse
    {
        try {
            $auth->require($request, 'staff');
            if ($request->isMethod('get')) {
                $products = DB::table('products')->orderBy('sort_order')->orderBy('name')->get();
                $notices = DB::table('product_notices')->orderBy('sort_order')->orderBy('id')->get()->groupBy('product_id');
                return response()->json(['products'=>$products->map(fn ($p) => [
                    'dbId'=>(int)$p->id,'name'=>(string)$p->name,'slug'=>(string)$p->slug,
                    'imageUrl'=>(string)($p->image_url ?? ''),'bannerUrl'=>(string)($p->banner_url ?? ''),
                    'manualInstructions'=>(string)($p->manual_instructions ?? ''),
                    'manualOpenTime'=>(string)($p->manual_open_time ?? ''),
                    'manualCloseTime'=>(string)($p->manual_close_time ?? ''),
                    'manualTimezone'=>(string)($p->manual_timezone ?: 'Asia/Jakarta'),
                    'notices'=>$notices->get($p->id,collect())->map(fn ($n)=>[
                        'title'=>(string)$n->title,'body'=>(string)$n->body,'isActive'=>(bool)$n->is_active,'sortOrder'=>(int)$n->sort_order,
                    ])->values()->all(),
                ])->values()->all()], 200, ['Cache-Control'=>'no-store']);
            }

            $security->assertSameOrigin($request);
            $input = $request->validate([
                'dbId'=>['required','integer','min:1'],
                'imageUrl'=>['nullable','string','max:500'],
                'bannerUrl'=>['nullable','string','max:500'],
                'manualInstructions'=>['nullable','string','max:500'],
                'manualOpenTime'=>['nullable','regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
                'manualCloseTime'=>['nullable','regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
                'manualTimezone'=>['required','in:Asia/Jakarta,Asia/Makassar,Asia/Jayapura'],
                'notices'=>['nullable','array','max:10'],
                'notices.*.title'=>['required','string','min:2','max:180'],
                'notices.*.body'=>['required','string','min:1','max:2000'],
                'notices.*.isActive'=>['required','boolean'],
                'notices.*.sortOrder'=>['nullable','integer','min:0'],
            ]);
            DB::transaction(function () use ($input) {
                $exists = DB::table('products')->where('id',$input['dbId'])->exists();
                if (!$exists) throw new RuntimeException('Produk tidak ditemukan.');
                DB::table('products')->where('id',$input['dbId'])->update([
                    'image_url'=>trim((string)($input['imageUrl'] ?? '')) ?: null,
                    'banner_url'=>trim((string)($input['bannerUrl'] ?? '')) ?: null,
                    'manual_instructions'=>trim((string)($input['manualInstructions'] ?? '')) ?: null,
                    'manual_open_time'=>trim((string)($input['manualOpenTime'] ?? '')) ?: null,
                    'manual_close_time'=>trim((string)($input['manualCloseTime'] ?? '')) ?: null,
                    'manual_timezone'=>$input['manualTimezone'],
                    'updated_at'=>now(),
                ]);
                DB::table('product_notices')->where('product_id',$input['dbId'])->delete();
                foreach (($input['notices'] ?? []) as $index=>$notice) {
                    DB::table('product_notices')->insert([
                        'product_id'=>$input['dbId'],'title'=>trim($notice['title']),'body'=>trim($notice['body']),
                        'is_active'=>$notice['isActive']?1:0,'sort_order'=>(int)($notice['sortOrder'] ?? $index),
                        'created_at'=>now(),'updated_at'=>now(),
                    ]);
                }
            });
            return response()->json(['ok'=>true]);
        } catch (ValidationException $error) {
            return response()->json(['error'=>$error->validator->errors()->first()], 400);
        } catch (RuntimeException $error) {
            if ($this->isAccessError($error)) return $this->accessError($error);
            return response()->json(['error'=>$error->getMessage()], 400);
        }
    }

    public function productInput(Request $request, AdminAuthService $auth, SecurityGuard $security): JsonResponse
    {
        try {
            $auth->require($request, 'admin');
            if ($request->isMethod('get')) {
                $slug = trim((string)$request->query('slug',''));
                if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/',$slug)) throw new RuntimeException('Slug produk tidak valid.');
                $row = DB::table('products')->where('slug',$slug)->first([
                    'slug','category','input_label','input_placeholder','input_fields_json','needs_server','target_template','nickname_game_code',
                ]);
                if (!$row) return response()->json(['error'=>'Produk tidak ditemukan.'],404);
                return response()->json(['input'=>$this->serializeProductInput($row)], 200, ['Cache-Control'=>'no-store']);
            }

            $security->assertSameOrigin($request);
            $input = $request->validate([
                'slug'=>['required','regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/','max:80'],
                'checkoutType'=>['required','in:id,id-server'],
                'labelId'=>['required','string','min:1','max:80'],
                'labelServer'=>['nullable','string','max:80'],
                'nicknameGameCode'=>['nullable','regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/','max:100'],
            ]);
            $row = DB::table('products')->where('slug',$input['slug'])->first([
                'slug','category','input_label','input_placeholder','input_fields_json','needs_server','target_template','nickname_game_code',
            ]);
            if (!$row) return response()->json(['error'=>'Produk tidak ditemukan.'],404);
            $needsServer = $input['checkoutType'] === 'id-server';
            $nicknameCode = trim((string)($input['nicknameGameCode'] ?? '')) ?: null;
            if (strtolower((string)$row->category) === 'voucher' && $nicknameCode) throw new RuntimeException('Produk voucher tidak memakai Kode Game Nickname.');
            $serverLabel = trim((string)($input['labelServer'] ?? '')) ?: 'Server ID';
            $fields = [
                ['id'=>'destination','label'=>trim($input['labelId']),'placeholder'=>'Masukkan '.trim($input['labelId']),'required'=>true],
            ];
            if ($needsServer) $fields[]=['id'=>'server','label'=>$serverLabel,'placeholder'=>'Masukkan '.$serverLabel,'required'=>true];
            DB::table('products')->where('slug',$input['slug'])->update([
                'input_label'=>trim($input['labelId']),
                'input_placeholder'=>'Masukkan '.trim($input['labelId']),
                'input_fields_json'=>json_encode($fields,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
                'needs_server'=>$needsServer?1:0,
                'target_template'=>$needsServer ? '{{destination}}{{server}}' : '{{destination}}',
                'nickname_game_code'=>$nicknameCode,
                'updated_at'=>now(),
            ]);
            $updated = DB::table('products')->where('slug',$input['slug'])->first([
                'slug','category','input_label','input_placeholder','input_fields_json','needs_server','target_template','nickname_game_code',
            ]);
            return response()->json(['ok'=>true,'input'=>$this->serializeProductInput($updated)], 200, ['Cache-Control'=>'no-store']);
        } catch (ValidationException $error) {
            return response()->json(['error'=>$error->validator->errors()->first()],400);
        } catch (RuntimeException $error) {
            if ($this->isAccessError($error)) return $this->accessError($error);
            return response()->json(['error'=>$error->getMessage()],400);
        }
    }

    public function packageProvider(Request $request, AdminAuthService $auth, SecurityGuard $security): JsonResponse
    {
        $security->assertSameOrigin($request);
        try {
            $auth->require($request,'admin');
            $input = $request->validate([
                'packageId'=>['required','integer','min:1'],
                'providerCode'=>['nullable','in:digiflazz,voucher-stock'],
                'providerSku'=>['nullable','string','max:100'],
                'pricingMode'=>['nullable','in:manual,auto'],
                'marginType'=>['nullable','in:fixed,percent'],
                'marginValue'=>['nullable','integer','min:0','max:1000000'],
            ]);
            $provider = $input['providerCode'] ?? null;
            $sku = trim((string)($input['providerSku'] ?? '')) ?: null;
            if ($provider === 'digiflazz' && !$sku) throw new RuntimeException('SKU DigiFlazz wajib diisi.');
            if ($provider === 'voucher-stock' && (!$sku || !preg_match('/^[a-z0-9][a-z0-9._:-]{1,99}$/',$sku))) throw new RuntimeException('Kunci stok internal tidak valid.');
            $changed = DB::table('product_packages')->where('id',$input['packageId'])->update([
                'provider_code'=>$provider,
                'provider_sku'=>$sku,
                'pricing_mode'=>$provider === 'digiflazz' ? 'auto' : ($input['pricingMode'] ?? 'manual'),
                'margin_type'=>$input['marginType'] ?? 'fixed',
                'margin_value'=>(int)($input['marginValue'] ?? 0),
                'updated_at'=>now(),
            ]);
            if (!$changed && !DB::table('product_packages')->where('id',$input['packageId'])->exists()) throw new RuntimeException('Nominal tidak ditemukan.');
            return response()->json(['ok'=>true]);
        } catch (ValidationException $error) {
            return response()->json(['error'=>$error->validator->errors()->first()],400);
        } catch (RuntimeException $error) {
            if ($this->isAccessError($error)) return $this->accessError($error);
            return response()->json(['error'=>$error->getMessage()],400);
        }
    }

    public function packageStatus(Request $request, AdminAuthService $auth, SecurityGuard $security): JsonResponse
    {
        $security->assertSameOrigin($request);
        try {
            $auth->require($request,'admin');
            $input = $request->validate([
                'packageId'=>['required','integer','min:1'],
                'isActive'=>['required','boolean'],
            ]);
            $changed = DB::table('product_packages')->where('id',$input['packageId'])->update([
                'is_active'=>$input['isActive']?1:0,'updated_at'=>now(),
            ]);
            if (!$changed && !DB::table('product_packages')->where('id',$input['packageId'])->exists()) throw new RuntimeException('Nominal tidak ditemukan.');
            return response()->json(['ok'=>true]);
        } catch (ValidationException $error) {
            return response()->json(['error'=>$error->validator->errors()->first()],400);
        } catch (RuntimeException $error) {
            if ($this->isAccessError($error)) return $this->accessError($error);
            return response()->json(['error'=>$error->getMessage()],400);
        }
    }

    public function wallet(Request $request, AdminAuthService $auth, SecurityGuard $security): JsonResponse
    {
        try {
            $auth->require($request,'owner');
            if ($request->isMethod('get')) {
                $row = DB::table('wallet_settings')->where('id',1)->first();
                return response()->json([
                    'settings'=>[
                        'minTopup'=>max(1000,(int)($row?->min_topup ?? 10000)),
                        'automaticTopupEnabled'=>(bool)($row?->doku_topup_enabled ?? false),
                    ],
                    'topups'=>DB::table('wallet_topups')->orderByDesc('created_at')->limit(100)->get(),
                ], 200, ['Cache-Control'=>'no-store']);
            }
            $security->assertSameOrigin($request);
            $input = $request->validate([
                'minTopup'=>['required','integer','min:1000','max:100000000'],
                'automaticTopupEnabled'=>['required','boolean'],
            ]);
            DB::table('wallet_settings')->updateOrInsert(['id'=>1],[
                'min_topup'=>$input['minTopup'],
                'doku_topup_enabled'=>$input['automaticTopupEnabled']?1:0,
                'updated_at'=>now(),
            ]);
            return response()->json(['ok'=>true]);
        } catch (ValidationException $error) {
            return response()->json(['error'=>$error->validator->errors()->first()],400);
        } catch (RuntimeException $error) {
            return $this->accessError($error);
        }
    }

    public function walletProof(Request $request, AdminAuthService $auth)
    {
        try {
            $auth->require($request,'owner');
            $key = trim((string)$request->query('key',''));
            if (!preg_match('/^media-[0-9a-f-]{36}\.(?:jpg|png|webp|gif)$/i',$key)) return response('Bukti tidak valid.',400);
            $linked = DB::table('wallet_topups')->where('proof_url',$key)->orWhere('proof_url','/api/media/'.$key)->exists();
            if (!$linked) return response('Bukti tidak ditemukan.',404);
            $row = DB::table('media_assets')->where('media_key',$key)->first(['content_type','data']);
            if (!$row) return response('Bukti tidak ditemukan.',404);
            return response($row->data,200,[
                'Content-Type'=>(string)$row->content_type,'Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff',
            ]);
        } catch (RuntimeException $error) {
            return $this->accessError($error);
        }
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

    private function serializeProductInput(?object $row): array
    {
        if (!$row) return [];
        $fields = json_decode((string)($row->input_fields_json ?? '[]'),true);
        $fields = is_array($fields) ? $fields : [];
        $labelId = trim((string) data_get($fields,'0.label',(string)$row->input_label)) ?: (string)$row->input_label;
        $labelServer = trim((string) data_get($fields,'1.label','Server ID')) ?: 'Server ID';
        return [
            'slug'=>(string)$row->slug,
            'checkoutType'=>(bool)$row->needs_server ? 'id-server' : 'id',
            'labelId'=>$labelId,'labelServer'=>$labelServer,
            'targetTemplate'=>(string)$row->target_template,
            'nicknameGameCode'=>(string)($row->nickname_game_code ?? ''),
        ];
    }

    private function kokinpayPost(string $url, array $body): array
    {
        try {
            $response = Http::acceptJson()->timeout(8)->post($url,$body);
        } catch (Throwable) {
            throw new RuntimeException('Layanan KokinPay sedang tidak tersedia.');
        }
        $data = $response->json();
        if (!$response->successful() || !is_array($data) || ($data['status'] ?? null) !== true) {
            $message = is_array($data) && is_string($data['message'] ?? null) ? trim($data['message']) : '';
            throw new RuntimeException($message ?: 'Pemeriksaan KokinPay tidak berhasil.');
        }
        return $data;
    }

    private function isAccessError(RuntimeException $error): bool
    {
        return str_contains($error->getMessage(),'Sesi panel') || str_contains($error->getMessage(),'Akses panel');
    }

    private function accessError(RuntimeException $error): JsonResponse
    {
        return response()->json(
            ['error'=>$error->getMessage()],
            str_contains($error->getMessage(),'Sesi panel') ? 401 : 403,
            ['Cache-Control'=>'no-store'],
        );
    }
}