<?php

namespace App\Http\Controllers;

use App\Services\AdminAuthService;
use App\Services\SecurityGuard;
use App\Services\StoreContentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class AdminContentController extends Controller
{
    public function content(Request $request, AdminAuthService $auth, StoreContentService $content): JsonResponse
    {
        try {
            $auth->require($request, 'staff');
            return response()->json([
                'banners' => $content->banners(true),
                'popups' => $content->popups(true),
                'news' => $content->news(true),
            ], 200, ['Cache-Control' => 'no-store']);
        } catch (RuntimeException $error) {
            return $this->accessError($error);
        }
    }

    public function saveContent(
        Request $request,
        AdminAuthService $auth,
        StoreContentService $content,
        SecurityGuard $security,
    ): JsonResponse {
        $security->assertSameOrigin($request);
        try {
            $auth->require($request, 'staff');
            $kind = (string) $request->input('kind', '');
            $item = $request->input('item');
            if (!is_array($item)) {
                throw new RuntimeException('Data konten tidak valid.');
            }

            $validated = match ($kind) {
                'banner' => validator($item, [
                    'id' => ['nullable','integer','min:1'],
                    'title' => ['required','string','min:2','max:120'],
                    'subtitle' => ['nullable','string','max:300'],
                    'imageUrl' => ['required','string','max:500'],
                    'mobileImageUrl' => ['nullable','string','max:500'],
                    'ctaLabel' => ['nullable','string','max:50'],
                    'ctaHref' => ['nullable','string','max:500'],
                    'showDesktop' => ['required','boolean'],
                    'showMobile' => ['required','boolean'],
                    'isActive' => ['required','boolean'],
                    'sortOrder' => ['required','integer','min:0','max:10000'],
                ])->validate(),
                'popup' => validator($item, [
                    'id' => ['nullable','integer','min:1'],
                    'title' => ['required','string','min:2','max:140'],
                    'body' => ['required','string','min:2','max:3000'],
                    'primaryLabel' => ['nullable','string','max:80'],
                    'primaryHref' => ['nullable','string','max:500'],
                    'secondaryLabel' => ['nullable','string','max:80'],
                    'secondaryHref' => ['nullable','string','max:500'],
                    'dismissDays' => ['required','integer','min:0','max:365'],
                    'isActive' => ['required','boolean'],
                    'sortOrder' => ['required','integer','min:0','max:10000'],
                ])->validate(),
                'news' => validator($item, [
                    'id' => ['nullable','integer','min:1'],
                    'slug' => ['required','regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/','max:100'],
                    'title' => ['required','string','min:2','max:180'],
                    'summary' => ['nullable','string','max:500'],
                    'body' => ['required','string','min:2','max:12000'],
                    'coverUrl' => ['nullable','string','max:500'],
                    'isPublished' => ['required','boolean'],
                    'publishedAt' => ['nullable','string','max:40'],
                    'sortOrder' => ['required','integer','min:0','max:10000'],
                ])->validate(),
                default => throw new RuntimeException('Jenis konten tidak valid.'),
            };

            $validated = array_merge([
                'subtitle'=>'','mobileImageUrl'=>'','ctaLabel'=>'','ctaHref'=>'',
                'primaryLabel'=>'','primaryHref'=>'','secondaryLabel'=>'','secondaryHref'=>'',
                'summary'=>'','coverUrl'=>'','publishedAt'=>'',
            ], $validated);
            foreach ([
                'subtitle','mobileImageUrl','ctaLabel','ctaHref',
                'primaryLabel','primaryHref','secondaryLabel','secondaryHref',
                'summary','coverUrl','publishedAt',
            ] as $key) {
                if (($validated[$key] ?? null) === null) {
                    $validated[$key] = '';
                }
            }

            foreach (['imageUrl','mobileImageUrl','coverUrl'] as $key) {
                if (isset($validated[$key]) && !$content->validMedia((string) $validated[$key])) {
                    throw new RuntimeException('URL gambar tidak valid.');
                }
            }
            foreach (['ctaHref','primaryHref','secondaryHref'] as $key) {
                if (!empty($validated[$key]) && !$content->validNavigation((string) $validated[$key])) {
                    throw new RuntimeException('URL tujuan tidak valid.');
                }
            }
            if ($kind === 'banner' && empty($validated['showDesktop']) && empty($validated['showMobile'])) {
                throw new RuntimeException('Pilih minimal satu tampilan banner.');
            }

            $id = $content->saveContent($kind, $validated);
            return response()->json(['ok'=>true,'id'=>$id]);
        } catch (ValidationException $error) {
            return response()->json(['error'=>$error->validator->errors()->first()],400);
        } catch (RuntimeException $error) {
            if ($this->isAccessError($error)) return $this->accessError($error);
            return response()->json(['error'=>$error->getMessage()],400);
        } catch (Throwable $error) {
            return response()->json(['error'=>$error->getMessage() ?: 'Konten gagal disimpan.'],400);
        }
    }

    public function deleteContent(Request $request, AdminAuthService $auth, StoreContentService $content, SecurityGuard $security): JsonResponse
    {
        $security->assertSameOrigin($request);
        try {
            $auth->require($request,'admin');
            $kind=(string)$request->query('kind','');
            $id=(int)$request->query('id',0);
            if(!in_array($kind,['banner','popup','news'],true)||$id<1) throw new RuntimeException('Konten tidak valid.');
            $content->deleteContent($kind,$id);
            return response()->json(['ok'=>true]);
        } catch(RuntimeException $error) {
            if($this->isAccessError($error)) return $this->accessError($error);
            return response()->json(['error'=>$error->getMessage()],400);
        }
    }

    public function storefront(Request $request, AdminAuthService $auth, StoreContentService $content): JsonResponse
    {
        try {
            $auth->require($request,'staff');
            return response()->json(['settings'=>$content->storefront(false)],200,['Cache-Control'=>'no-store']);
        } catch(RuntimeException $error) { return $this->accessError($error); }
    }

    public function saveStorefront(Request $request, AdminAuthService $auth, StoreContentService $content, SecurityGuard $security): JsonResponse
    {
        $security->assertSameOrigin($request);
        try {
            $access=$auth->require($request,'staff');
            $input=$request->validate([
                'storeName'=>['required','string','min:2','max:80'],
                'storeShortName'=>['required','string','min:1','max:6'],
                'tagline'=>['required','string','min:3','max:160'],
                'logoUrl'=>['nullable','string','max:500'],
                'announcement'=>['nullable','string','max:160'],
                'bannerEnabled'=>['required','boolean'],
                'bannerEyebrow'=>['required','string','min:2','max:80'],
                'bannerTitle'=>['required','string','min:2','max:100'],
                'bannerHighlight'=>['required','string','min:2','max:100'],
                'bannerDescription'=>['required','string','min:3','max:300'],
                'bannerImageUrl'=>['nullable','string','max:500'],
                'bannerCtaLabel'=>['required','string','min:2','max:40'],
                'bannerCtaHref'=>['required','string','min:1','max:200'],
                'supportWhatsapp'=>['nullable','regex:/^\+?[0-9]{8,16}$/'],
                'supportEmail'=>['nullable','email','max:150'],
                'instagramUrl'=>['nullable','string','max:500'],
                'discordUrl'=>['nullable','string','max:500'],
                'supportHours'=>['required','string','min:3','max:120'],
                'supportWidgetEnabled'=>['required','boolean'],
            ]);

            foreach(['logoUrl','bannerImageUrl'] as $key) if(!empty($input[$key])&&!$content->validMedia((string)$input[$key])) throw new RuntimeException('URL gambar tidak valid.');
            if(!$content->validNavigation((string)$input['bannerCtaHref'])) throw new RuntimeException('Tujuan tombol banner tidak valid.');
            foreach(['instagramUrl','discordUrl'] as $key) {
                if(!empty($input[$key])) {
                    $parts=parse_url((string)$input[$key]);
                    if(!is_array($parts)||!in_array(strtolower((string)($parts['scheme']??'')),['http','https'],true)||empty($parts['host'])) throw new RuntimeException('URL sosial tidak valid.');
                }
            }

            $content->saveStorefront($input,(string)$access['role']);
            return response()->json(['ok'=>true]);
        } catch(ValidationException $error) { return response()->json(['error'=>$error->validator->errors()->first()],400); }
        catch(RuntimeException $error) { if($this->isAccessError($error)) return $this->accessError($error); return response()->json(['error'=>$error->getMessage()],400); }
    }

    public function faqs(Request $request, AdminAuthService $auth, StoreContentService $content): JsonResponse
    {
        try { $auth->require($request,'staff'); return response()->json(['faqs'=>$content->faqs(true,false)]); }
        catch(RuntimeException $error){ return $this->accessError($error); }
    }

    public function saveFaq(Request $request, AdminAuthService $auth, StoreContentService $content, SecurityGuard $security): JsonResponse
    {
        $security->assertSameOrigin($request);
        try {
            $auth->require($request,'staff');
            $input=$request->validate([
                'id'=>['nullable','integer','min:1'],'question'=>['required','string','min:5','max:180'],
                'answer'=>['required','string','min:5','max:2000'],'isActive'=>['required','boolean'],
                'sortOrder'=>['required','integer','min:0','max:1000'],
            ]);
            return response()->json(['ok'=>true,'id'=>$content->saveFaq($input)]);
        } catch(ValidationException $error){return response()->json(['error'=>$error->validator->errors()->first()],400);}
        catch(RuntimeException $error){if($this->isAccessError($error))return $this->accessError($error);return response()->json(['error'=>$error->getMessage()],400);}
    }

    public function deleteFaq(Request $request, AdminAuthService $auth, SecurityGuard $security): JsonResponse
    {
        $security->assertSameOrigin($request);
        try { $auth->require($request,'admin'); $id=(int)$request->query('id',0); if($id<1)throw new RuntimeException('ID FAQ tidak valid.'); DB::table('faq_entries')->where('id',$id)->delete(); return response()->json(['ok'=>true]); }
        catch(RuntimeException $error){if($this->isAccessError($error))return $this->accessError($error);return response()->json(['error'=>$error->getMessage()],400);}
    }

    public function categories(Request $request, AdminAuthService $auth, StoreContentService $content): JsonResponse
    {
        try { $auth->require($request,'staff'); return response()->json(['categories'=>$content->categories(true)]); }
        catch(RuntimeException $error){return $this->accessError($error);}
    }

    public function saveCategory(Request $request, AdminAuthService $auth, StoreContentService $content, SecurityGuard $security): JsonResponse
    {
        $security->assertSameOrigin($request);
        try {
            $auth->require($request,'staff');
            $input=$request->validate([
                'id'=>['nullable','integer','min:1'],'slug'=>['required','regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/','max:60'],
                'name'=>['required','string','min:2','max:60'],'icon'=>['required','string','max:30'],
                'isActive'=>['required','boolean'],'sortOrder'=>['required','integer','min:0','max:1000'],
            ]);
            return response()->json(['ok'=>true,'id'=>$content->saveCategory($input)]);
        } catch(ValidationException $error){return response()->json(['error'=>$error->validator->errors()->first()],400);}
        catch(RuntimeException $error){if($this->isAccessError($error))return $this->accessError($error);return response()->json(['error'=>$error->getMessage()],400);}
    }

    public function deleteCategory(Request $request, AdminAuthService $auth, StoreContentService $content, SecurityGuard $security): JsonResponse
    {
        $security->assertSameOrigin($request);
        try { $auth->require($request,'staff'); $id=(int)$request->query('id',0); if($id<1)throw new RuntimeException('ID kategori tidak valid.'); $content->deleteCategory($id); return response()->json(['ok'=>true]); }
        catch(RuntimeException $error){if($this->isAccessError($error))return $this->accessError($error);return response()->json(['error'=>$error->getMessage()],400);}
    }

    private function isAccessError(RuntimeException $error): bool { return str_contains($error->getMessage(),'Sesi panel')||str_contains($error->getMessage(),'Akses panel'); }
    private function accessError(RuntimeException $error): JsonResponse { return response()->json(['error'=>$error->getMessage()],str_contains($error->getMessage(),'Sesi panel')?401:403,['Cache-Control'=>'no-store']); }
}
