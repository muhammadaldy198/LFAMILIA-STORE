<?php

namespace App\Http\Controllers;

use App\Services\AdminAuthService;
use App\Services\AdminProductService;
use App\Services\SecurityGuard;
use App\Services\StoreContentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class AdminProductController extends Controller
{
    public function index(Request $request, AdminAuthService $auth, AdminProductService $products): JsonResponse
    {
        try {
            $access = $auth->require($request, 'admin');
            return response()->json([
                'products' => $products->all(),
                'databaseReady' => true,
                'adminEmail' => $access['email'],
                'role' => $access['role'],
                'sellerMonitor' => $access['role'] === 'super_admin'
                    ? DB::table('digiflazz_seller_monitor')->get()
                    : null,
            ], 200, ['Cache-Control' => 'no-store']);
        } catch (RuntimeException $error) {
            return $this->accessError($error);
        } catch (Throwable $error) {
            return response()->json([
                'error' => $error->getMessage() ?: 'Database belum siap.',
                'databaseReady' => false,
            ], 503);
        }
    }

    public function create(
        Request $request,
        AdminAuthService $auth,
        AdminProductService $products,
        StoreContentService $content,
        SecurityGuard $security,
    ): JsonResponse {
        $security->assertSameOrigin($request);
        try {
            $auth->require($request, 'admin');
            $input = $this->validated($request, $content, false);
            $id = $products->save($input);

            return response()->json([
                'ok' => true,
                'id' => $id,
                'digiflazzSyncWarning' => $this->hasDigiflazz($input)
                    ? 'Harga DigiFlazz menunggu sinkronisasi katalog provider; harga provider tidak diambil dari payload Admin.'
                    : null,
            ], 201);
        } catch (ValidationException $error) {
            return response()->json(['error' => $error->validator->errors()->first()], 400);
        } catch (RuntimeException $error) {
            if ($this->isAccessError($error)) return $this->accessError($error);
            return response()->json(['error' => $error->getMessage()], 400);
        } catch (Throwable $error) {
            return response()->json(['error' => $error->getMessage() ?: 'Produk gagal disimpan.'], 400);
        }
    }

    public function update(
        Request $request,
        AdminAuthService $auth,
        AdminProductService $products,
        StoreContentService $content,
        SecurityGuard $security,
    ): JsonResponse {
        $security->assertSameOrigin($request);
        try {
            $auth->require($request, 'admin');
            $input = $this->validated($request, $content, true);
            $products->save($input, (int) $input['dbId']);

            return response()->json([
                'ok' => true,
                'digiflazzSyncWarning' => $this->hasDigiflazz($input)
                    ? 'Snapshot harga DigiFlazz lama dipertahankan. Sinkronisasi provider memperbarui modal/Max Price secara terpisah.'
                    : null,
            ]);
        } catch (ValidationException $error) {
            return response()->json(['error' => $error->validator->errors()->first()], 400);
        } catch (RuntimeException $error) {
            if ($this->isAccessError($error)) return $this->accessError($error);
            return response()->json(['error' => $error->getMessage()], 400);
        } catch (Throwable $error) {
            return response()->json(['error' => $error->getMessage() ?: 'Produk gagal diperbarui.'], 400);
        }
    }

    public function destroy(
        Request $request,
        AdminAuthService $auth,
        AdminProductService $products,
        SecurityGuard $security,
    ): JsonResponse {
        $security->assertSameOrigin($request);
        try {
            $auth->require($request, 'admin');
            $id = (int) $request->query('id', 0);
            if ($id < 1) throw new RuntimeException('ID produk tidak valid.');
            $products->delete($id);

            return response()->json(['ok' => true]);
        } catch (RuntimeException $error) {
            if ($this->isAccessError($error)) return $this->accessError($error);
            return response()->json(['error' => $error->getMessage()], 400);
        }
    }

    /** @return array<string,mixed> */
    private function validated(Request $request, StoreContentService $content, bool $requireId): array
    {
        $rules = [
            'dbId' => [$requireId ? 'required' : 'nullable','integer','min:1'],
            'slug' => ['required','regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/','max:80'],
            'name' => ['required','string','min:2','max:80'],
            'publisher' => ['nullable','string','max:80'],
            'category' => ['required','regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/','max:60'],
            'imageUrl' => ['nullable','string','max:500'],
            'bannerUrl' => ['nullable','string','max:500'],
            'description' => ['nullable','string','max:3000'],
            'initials' => ['required','string','min:1','max:3'],
            'accent' => ['required','string','min:5','max:160'],
            'inputLabel' => ['required','string','min:2','max:80'],
            'inputPlaceholder' => ['required','string','min:2','max:120'],
            'inputFields' => ['array','max:12'],
            'inputFields.*.id' => ['required','regex:/^[a-z0-9-]+$/','max:60'],
            'inputFields.*.label' => ['required','string','min:1','max:80'],
            'inputFields.*.placeholder' => ['nullable','string','max:120'],
            'inputFields.*.required' => ['required','boolean'],
            'needsServer' => ['required','boolean'],
            'popular' => ['required','boolean'],
            'instant' => ['required','boolean'],
            'fulfillmentType' => ['required','in:automatic,manual'],
            'targetTemplate' => ['required','string','min:3','max:500'],
            'manualInstructions' => ['nullable','string','max:500'],
            'manualOpenTime' => ['nullable','regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'manualCloseTime' => ['nullable','regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'manualTimezone' => ['nullable','string','max:60'],
            'packageTabsEnabled' => ['required','boolean'],
            'packageTabs' => ['array','max:20'],
            'packageTabs.*' => ['required','string','min:1','max:60'],
            'isActive' => ['required','boolean'],
            'sortOrder' => ['required','integer','min:0','max:10000'],
            'packages' => ['array','max:50'],
            'packages.*.id' => ['required','string','min:2','max:100'],
            'packages.*.label' => ['required','string','min:2','max:100'],
            'packages.*.price' => ['required','integer','min:1','max:100000000'],
            'packages.*.note' => ['nullable','string','max:40'],
            'packages.*.group' => ['nullable','string','max:60'],
            'packages.*.imageUrl' => ['nullable','string','max:500'],
            'packages.*.providerCode' => ['nullable','in:digiflazz,voucher-stock'],
            'packages.*.providerSku' => ['nullable','string','max:100'],
            'packages.*.supplierPrice' => ['nullable','integer','min:0','max:100000000'],
            'packages.*.providerMaxPrice' => ['nullable','integer','min:1','max:100000000'],
            'packages.*.pricingMode' => ['nullable','in:manual,auto'],
            'packages.*.marginType' => ['nullable','in:fixed,percent'],
            'packages.*.marginValue' => ['nullable','integer','min:0','max:1000000'],
            'packages.*.isActive' => ['required','boolean'],
            'packages.*.sortOrder' => ['nullable','integer','min:0','max:10000'],
            'notices' => ['array','max:10'],
            'notices.*.id' => ['nullable','integer','min:1'],
            'notices.*.title' => ['required','string','min:2','max:180'],
            'notices.*.body' => ['required','string','min:2','max:2000'],
            'notices.*.isActive' => ['required','boolean'],
            'notices.*.sortOrder' => ['nullable','integer','min:0','max:10000'],
        ];

        $input = $request->validate($rules);
        $input += [
            'publisher'=>'','imageUrl'=>'','bannerUrl'=>'','description'=>'',
            'inputFields'=>[],'manualInstructions'=>'','manualOpenTime'=>null,
            'manualCloseTime'=>null,'manualTimezone'=>'Asia/Jakarta',
            'packageTabs'=>[],'packages'=>[],'notices'=>[],
        ];

        foreach (['publisher','imageUrl','bannerUrl','description','manualInstructions'] as $key) {
            if (($input[$key] ?? null) === null) $input[$key] = '';
        }
        foreach ($input['inputFields'] as &$field) {
            if (($field['placeholder'] ?? null) === null) $field['placeholder'] = '';
        }
        unset($field);

        foreach (['imageUrl','bannerUrl'] as $key) {
            if (!$content->validMedia((string) $input[$key])) {
                throw new RuntimeException('URL gambar tidak valid.');
            }
        }
        foreach ($input['packages'] as $package) {
            if (!$content->validMedia(trim((string) ($package['imageUrl'] ?? '')))) {
                throw new RuntimeException('URL gambar nominal tidak valid.');
            }
        }

        return $input;
    }

    private function hasDigiflazz(array $input): bool
    {
        foreach ($input['packages'] as $package) {
            if (($package['providerCode'] ?? null) === 'digiflazz') return true;
        }
        return false;
    }

    private function isAccessError(RuntimeException $error): bool
    {
        return str_contains($error->getMessage(),'Sesi panel')
            || str_contains($error->getMessage(),'Akses panel');
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
