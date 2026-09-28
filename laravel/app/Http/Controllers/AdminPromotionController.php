<?php

namespace App\Http\Controllers;

use App\Services\AdminAuthService;
use App\Services\AdminPromotionService;
use App\Services\SecurityGuard;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class AdminPromotionController extends Controller
{
    public function index(Request $request, AdminAuthService $auth, AdminPromotionService $promotions): JsonResponse
    {
        try {
            $access = $auth->require($request, 'admin');

            return response()->json([
                'vouchers' => $promotions->vouchers(),
                'flashSales' => $promotions->flashSales(),
                'role' => $access['role'],
            ], 200, ['Cache-Control' => 'no-store']);
        } catch (RuntimeException $error) {
            return $this->accessError($error);
        } catch (Throwable $error) {
            return response()->json(['error' => $error->getMessage() ?: 'Promo gagal dimuat.'], 503);
        }
    }

    public function save(
        Request $request,
        AdminAuthService $auth,
        AdminPromotionService $promotions,
        SecurityGuard $security,
    ): JsonResponse {
        $security->assertSameOrigin($request);
        try {
            $auth->require($request, 'admin');
            $kind = (string) $request->input('kind', '');
            $common = [
                'id' => ['nullable','integer','min:1'],
                'isActive' => ['required','boolean'],
                'startsAt' => ['required','date'],
                'endsAt' => ['required','date'],
            ];

            if ($kind === 'voucher') {
                $input = $request->validate([
                    ...$common,
                    'kind' => ['required','in:voucher'],
                    'code' => ['required','regex:/^[A-Za-z0-9_-]+$/','max:40'],
                    'name' => ['required','string','min:2','max:100'],
                    'description' => ['nullable','string','max:300'],
                    'discountType' => ['required','in:fixed,percentage'],
                    'discountValue' => ['required','integer','min:1','max:100000000'],
                    'minPurchase' => ['required','integer','min:0','max:100000000'],
                    'maxDiscount' => ['nullable','integer','min:1','max:100000000'],
                    'usageLimit' => ['nullable','integer','min:1','max:100000000'],
                ]);
                $this->normalizePeriod($input);
                if ($input['discountType'] === 'percentage' && (int) $input['discountValue'] > 100) {
                    throw new RuntimeException('Persentase diskon maksimal 100%.');
                }
                $input['description'] = (string) ($input['description'] ?? '');
                $input['maxDiscount'] = $input['maxDiscount'] ?? null;
                $input['usageLimit'] = $input['usageLimit'] ?? null;
                $id = $promotions->saveVoucher($input, isset($input['id']) ? (int) $input['id'] : null);

                return response()->json(['ok' => true, 'id' => $id], empty($input['id']) ? 201 : 200);
            }

            if ($kind === 'flash') {
                $input = $request->validate([
                    ...$common,
                    'kind' => ['required','in:flash'],
                    'productSlug' => ['required','regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/','max:80'],
                    'packageSku' => ['required','string','min:2','max:100'],
                    'salePrice' => ['required','integer','min:1','max:100000000'],
                    'badge' => ['required','string','min:2','max:30'],
                    'stockLimit' => ['nullable','integer','min:1','max:100000000'],
                ]);
                $this->normalizePeriod($input);
                $input['stockLimit'] = $input['stockLimit'] ?? null;
                $id = $promotions->saveFlashSale($input, isset($input['id']) ? (int) $input['id'] : null);

                return response()->json(['ok' => true, 'id' => $id], empty($input['id']) ? 201 : 200);
            }

            throw new RuntimeException('Jenis promo tidak valid.');
        } catch (ValidationException $error) {
            return response()->json(['error' => $error->validator->errors()->first()], 400);
        } catch (RuntimeException $error) {
            if ($this->isAccessError($error)) return $this->accessError($error);
            return response()->json(['error' => $error->getMessage()], 400);
        } catch (Throwable $error) {
            return response()->json(['error' => $error->getMessage() ?: 'Promo gagal disimpan.'], 400);
        }
    }

    public function destroy(
        Request $request,
        AdminAuthService $auth,
        AdminPromotionService $promotions,
        SecurityGuard $security,
    ): JsonResponse {
        $security->assertSameOrigin($request);
        try {
            $auth->require($request, 'admin');
            $kind = (string) $request->query('kind', '');
            $id = (int) $request->query('id', 0);
            if (!in_array($kind, ['voucher','flash'], true) || $id < 1) {
                throw new RuntimeException('Promo tidak valid.');
            }
            $promotions->delete($kind, $id);

            return response()->json(['ok' => true]);
        } catch (RuntimeException $error) {
            if ($this->isAccessError($error)) return $this->accessError($error);
            return response()->json(['error' => $error->getMessage()], 400);
        }
    }

    /** @param array<string,mixed> $input */
    private function normalizePeriod(array &$input): void
    {
        $starts = CarbonImmutable::parse((string) $input['startsAt'])->utc();
        $ends = CarbonImmutable::parse((string) $input['endsAt'])->utc();
        if (!$ends->greaterThan($starts)) {
            throw new RuntimeException('Waktu berakhir harus setelah waktu mulai.');
        }
        $input['startsAt'] = $starts->toDateTimeString();
        $input['endsAt'] = $ends->toDateTimeString();
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
