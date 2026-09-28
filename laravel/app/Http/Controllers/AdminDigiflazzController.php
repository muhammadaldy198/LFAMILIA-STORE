<?php

namespace App\Http\Controllers;

use App\Services\AdminAuthService;
use App\Services\AdminDigiflazzService;
use App\Services\SecurityGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class AdminDigiflazzController extends Controller
{
    public function pricing(Request $request, AdminAuthService $auth, AdminDigiflazzService $digiflazz): JsonResponse
    {
        try {
            $auth->require($request, 'admin');
            $payload = [
                'settings' => $digiflazz->pricingSettings(),
                'cache' => $digiflazz->cacheMeta(),
            ];
            if ($request->query('catalog') === '1') {
                $payload['catalog'] = $digiflazz->catalog();
            }

            return response()->json($payload, 200, ['Cache-Control' => 'no-store']);
        } catch (RuntimeException $error) {
            if ($this->isAccessError($error)) return $this->accessError($error);
            return response()->json(['error' => $error->getMessage()], 503);
        } catch (Throwable $error) {
            return response()->json(['error' => $error->getMessage() ?: 'Pengaturan harga belum siap.'], 503);
        }
    }

    public function syncPricing(
        Request $request,
        AdminAuthService $auth,
        AdminDigiflazzService $digiflazz,
        SecurityGuard $security,
    ): JsonResponse {
        $security->assertSameOrigin($request);
        try {
            $auth->require($request, 'admin');
            $input = $request->validate([
                'isAutoSync' => ['nullable','boolean'],
                'syncNow' => ['nullable','boolean'],
                'productId' => ['nullable','integer','min:1'],
                'packageSku' => ['nullable','string','min:2','max:100'],
            ]);
            if (isset($input['isAutoSync'])) {
                $digiflazz->savePricingSettings((bool) $input['isAutoSync']);
            }
            if (isset($input['packageSku']) && empty($input['productId'])) {
                throw new RuntimeException('productId wajib dikirim saat sinkronisasi satu nominal.');
            }

            $result = !empty($input['productId']) && !empty($input['packageSku'])
                ? $digiflazz->syncPackage((int) $input['productId'], (string) $input['packageSku'])
                : (!empty($input['productId'])
                    ? $digiflazz->syncProduct((int) $input['productId'])
                    : (!empty($input['syncNow']) ? $digiflazz->syncAll(true) : null));

            return response()->json([
                'ok' => true,
                'result' => $result,
                'cache' => $digiflazz->cacheMeta(),
            ]);
        } catch (ValidationException $error) {
            return response()->json(['error' => $error->validator->errors()->first()], 400);
        } catch (RuntimeException $error) {
            if ($this->isAccessError($error)) return $this->accessError($error);
            return response()->json(['error' => $error->getMessage()], 400);
        } catch (Throwable $error) {
            return response()->json(['error' => $error->getMessage() ?: 'Sinkron harga gagal.'], 400);
        }
    }

    public function updatePricing(
        Request $request,
        AdminAuthService $auth,
        AdminDigiflazzService $digiflazz,
        SecurityGuard $security,
    ): JsonResponse {
        $security->assertSameOrigin($request);
        try {
            $auth->require($request, 'admin');
            $input = $request->validate([
                'packageId' => ['required','integer','min:1'],
                'maxPrice' => ['required','integer','min:1','max:100000000'],
                'marginType' => ['required','in:fixed,percent'],
                'marginValue' => ['required','integer','min:0','max:1000000'],
            ]);
            $pricing = $digiflazz->updatePackagePricing(
                (int) $input['packageId'],
                (int) $input['maxPrice'],
                (string) $input['marginType'],
                (int) $input['marginValue'],
            );

            return response()->json(['ok' => true, 'pricing' => $pricing]);
        } catch (ValidationException $error) {
            return response()->json(['error' => $error->validator->errors()->first()], 400);
        } catch (RuntimeException $error) {
            if ($this->isAccessError($error)) return $this->accessError($error);
            return response()->json(['error' => $error->getMessage()], 400);
        }
    }

    public function monitor(Request $request, AdminAuthService $auth, AdminDigiflazzService $digiflazz): JsonResponse
    {
        try {
            $access = $auth->require($request, 'admin');
            return response()->json($digiflazz->dashboard((string) $access['role']), 200, ['Cache-Control' => 'no-store']);
        } catch (RuntimeException $error) {
            if ($this->isAccessError($error)) return $this->accessError($error);
            return response()->json(['error' => $error->getMessage()], 503);
        } catch (Throwable $error) {
            return response()->json(['error' => $error->getMessage() ?: 'Monitor seller DigiFlazz gagal dimuat.'], 503);
        }
    }

    public function refreshMonitor(
        Request $request,
        AdminAuthService $auth,
        AdminDigiflazzService $digiflazz,
        SecurityGuard $security,
    ): JsonResponse {
        $security->assertSameOrigin($request);
        try {
            $access = $auth->require($request, 'admin');
            $result = $digiflazz->syncAll(true);
            $message = !empty($result['skipped'])
                ? match ($result['reason'] ?? '') {
                    'sync_in_progress' => 'Sinkronisasi pricelist sedang berjalan. Cache terakhir tetap digunakan.',
                    'cooldown' => 'Pricelist baru saja disinkronkan. Cache terakhir tetap digunakan untuk mencegah limit DigiFlazz.',
                    'maintenance' => 'Konfigurasi DigiFlazz sedang dalam mode maintenance. Cache terakhir tetap digunakan.',
                    default => 'Auto Sync sedang nonaktif.',
                }
                : ((int) ($result['updated'] ?? 0)).' nominal berhasil diperbarui dari pricelist terbaru.';

            return response()->json([
                'ok' => true,
                'result' => $result,
                'message' => $message,
                ...$digiflazz->dashboard((string) $access['role']),
            ]);
        } catch (RuntimeException $error) {
            if ($this->isAccessError($error)) return $this->accessError($error);
            return response()->json(['error' => $error->getMessage()], 400);
        } catch (Throwable $error) {
            return response()->json(['error' => $error->getMessage() ?: 'Monitor seller DigiFlazz gagal diperbarui.'], 400);
        }
    }

    private function isAccessError(RuntimeException $error): bool
    {
        return str_contains($error->getMessage(), 'Sesi panel') || str_contains($error->getMessage(), 'Akses panel');
    }

    private function accessError(RuntimeException $error): JsonResponse
    {
        return response()->json(
            ['error' => $error->getMessage()],
            str_contains($error->getMessage(), 'Sesi panel') ? 401 : 403,
            ['Cache-Control' => 'no-store'],
        );
    }
}
