<?php

namespace App\Http\Controllers;

use App\Services\AdminAuthService;
use App\Services\AdminCustomerService;
use App\Services\SecurityGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class AdminCustomerController extends Controller
{
    public function members(Request $request, AdminAuthService $auth, AdminCustomerService $customers): JsonResponse
    {
        try {
            $access = $auth->require($request, 'admin');
            $owner = $access['role'] === 'super_admin';
            $payload = ['members' => $customers->members($owner)];
            if ($owner) {
                $payload['settings'] = $customers->tierSettings();
            }

            return response()->json($payload, 200, ['Cache-Control' => 'no-store']);
        } catch (RuntimeException $error) {
            return $this->accessError($error);
        }
    }

    public function saveMemberSettings(
        Request $request,
        AdminAuthService $auth,
        AdminCustomerService $customers,
        SecurityGuard $security,
    ): JsonResponse {
        $security->assertSameOrigin($request);
        try {
            $auth->require($request, 'owner');
            $input = $request->validate([
                'settings' => ['required','array','size:4'],
                'settings.*.tier' => ['required','in:basic,gold,diamond,platinum'],
                'settings.*.discountPercent' => ['required','numeric','min:0','max:100'],
                'settings.*.benefits' => ['nullable','string','max:1000'],
            ]);
            $seen = array_unique(array_column($input['settings'], 'tier'));
            if (count($seen) !== 4) {
                throw new RuntimeException('Semua tier member wajib dikirim satu kali.');
            }
            $settings = array_map(fn (array $item) => [
                'tier' => $item['tier'],
                'discountPercent' => (float) $item['discountPercent'],
                'benefits' => (string) ($item['benefits'] ?? ''),
            ], $input['settings']);

            return response()->json(['ok' => true, 'settings' => $customers->saveTierSettings($settings)]);
        } catch (ValidationException $error) {
            return response()->json(['error' => $error->validator->errors()->first()], 400);
        } catch (RuntimeException $error) {
            if ($this->isAccessError($error)) return $this->accessError($error);
            return response()->json(['error' => $error->getMessage()], 400);
        }
    }

    public function updateMember(
        Request $request,
        AdminAuthService $auth,
        AdminCustomerService $customers,
        SecurityGuard $security,
    ): JsonResponse {
        $security->assertSameOrigin($request);
        try {
            $access = $auth->require($request, 'owner');
            $input = $request->validate([
                'customerId' => ['required','uuid'],
                'role' => ['required','in:automatic,basic,gold,diamond,platinum'],
                'addBalance' => ['nullable','integer','min:0','max:100000000'],
                'reason' => ['nullable','string','max:300'],
            ]);

            $customers->updateMember(
                (string) $input['customerId'],
                (string) $input['role'],
                (int) ($input['addBalance'] ?? 0),
                (string) $access['email'],
                isset($input['reason']) ? (string) $input['reason'] : null,
            );

            return response()->json([
                'ok' => true,
                'members' => $customers->members(true),
            ]);
        } catch (ValidationException $error) {
            return response()->json(['error' => $error->validator->errors()->first()], 400);
        } catch (RuntimeException $error) {
            if ($this->isAccessError($error)) return $this->accessError($error);
            return response()->json(['error' => $error->getMessage()], 400);
        }
    }

    public function deleteMember(
        Request $request,
        AdminAuthService $auth,
        AdminCustomerService $customers,
        SecurityGuard $security,
    ): JsonResponse {
        $security->assertSameOrigin($request);
        try {
            $auth->require($request, 'owner');
            $customerId = trim((string) $request->query('id', ''));
            $validated = validator(['id' => $customerId], ['id' => ['required','uuid']])->validate();
            $result = $customers->deleteEmptyCustomer((string) $validated['id']);

            if ($result['deleted']) {
                return response()->json(['ok' => true, 'members' => $customers->members(true)]);
            }
            if ($result['reason'] === 'not_found') {
                return response()->json(['error' => 'Pelanggan tidak ditemukan.'], 404);
            }

            $eligibility = $result['eligibility'];
            $reasons = [];
            if ((int) ($eligibility['balance'] ?? 0) !== 0) $reasons[] = 'saldo belum Rp0';
            if ((int) ($eligibility['orderCount'] ?? 0) > 0) $reasons[] = 'sudah memiliki pesanan';
            if ((int) ($eligibility['topupCount'] ?? 0) > 0) $reasons[] = 'sudah memiliki riwayat top up';
            if ((int) ($eligibility['walletTransactionCount'] ?? 0) > 0) $reasons[] = 'sudah memiliki riwayat saldo';

            return response()->json([
                'error' => 'Akun tidak boleh dihapus permanen karena '.($reasons ? implode(', ', $reasons) : 'status akun berubah').'. Riwayat transaksi harus tetap dipertahankan.',
                'eligibility' => $eligibility,
            ], 409);
        } catch (ValidationException $error) {
            return response()->json(['error' => $error->validator->errors()->first()], 400);
        } catch (RuntimeException $error) {
            if ($this->isAccessError($error)) return $this->accessError($error);
            return response()->json(['error' => $error->getMessage()], 400);
        }
    }

    public function balances(Request $request, AdminAuthService $auth, AdminCustomerService $customers): JsonResponse
    {
        try {
            $auth->require($request, 'owner');
            return response()->json($customers->balanceOverview(), 200, ['Cache-Control' => 'no-store']);
        } catch (RuntimeException $error) {
            return $this->accessError($error);
        } catch (Throwable $error) {
            return response()->json(['error' => $error->getMessage() ?: 'Data saldo gagal dimuat.'], 503);
        }
    }

    public function adjustBalance(
        Request $request,
        AdminAuthService $auth,
        AdminCustomerService $customers,
        SecurityGuard $security,
    ): JsonResponse {
        $security->assertSameOrigin($request);
        try {
            $access = $auth->require($request, 'owner');
            $input = $request->validate([
                'accountType' => ['required','in:customer,admin'],
                'targetId' => ['required','string','max:80'],
                'operation' => ['required','in:credit,debit'],
                'amount' => ['required','integer','min:1','max:100000000'],
                'reason' => ['required','string','min:3','max:300'],
            ]);

            $result = $customers->adjustBalance(
                (string) $input['accountType'],
                (string) $input['targetId'],
                (string) $input['operation'],
                (int) $input['amount'],
                (string) $input['reason'],
                (string) $access['email'],
            );

            return response()->json([
                'ok' => true,
                ...$result,
                ...$customers->balanceOverview(),
            ]);
        } catch (ValidationException $error) {
            return response()->json(['error' => $error->validator->errors()->first()], 400);
        } catch (RuntimeException $error) {
            if ($this->isAccessError($error)) return $this->accessError($error);
            return response()->json(['error' => $error->getMessage()], 400);
        } catch (Throwable $error) {
            return response()->json(['error' => $error->getMessage() ?: 'Saldo gagal diperbarui.'], 400);
        }
    }

    public function cleanupSettings(Request $request, AdminAuthService $auth, AdminCustomerService $customers): JsonResponse
    {
        try {
            $auth->require($request, 'owner');
            return response()->json(['settings' => $customers->cleanupSettings()], 200, ['Cache-Control' => 'no-store']);
        } catch (RuntimeException $error) {
            return $this->accessError($error);
        }
    }

    public function saveCleanupSettings(
        Request $request,
        AdminAuthService $auth,
        AdminCustomerService $customers,
        SecurityGuard $security,
    ): JsonResponse {
        $security->assertSameOrigin($request);
        try {
            $auth->require($request, 'owner');
            $input = $request->validate([
                'enabled' => ['required','boolean'],
                'inactivityDays' => ['required','integer','min:7','max:365'],
            ]);

            return response()->json([
                'ok' => true,
                'settings' => $customers->saveCleanupSettings((bool) $input['enabled'], (int) $input['inactivityDays']),
            ]);
        } catch (ValidationException $error) {
            return response()->json(['error' => $error->validator->errors()->first()], 400);
        } catch (RuntimeException $error) {
            if ($this->isAccessError($error)) return $this->accessError($error);
            return response()->json(['error' => $error->getMessage()], 400);
        }
    }

    public function runCleanup(
        Request $request,
        AdminAuthService $auth,
        AdminCustomerService $customers,
        SecurityGuard $security,
    ): JsonResponse {
        $security->assertSameOrigin($request);
        try {
            $auth->require($request, 'owner');
            return response()->json(['ok' => true, ...$customers->cleanupDormant(true)]);
        } catch (RuntimeException $error) {
            if ($this->isAccessError($error)) return $this->accessError($error);
            return response()->json(['error' => $error->getMessage()], 400);
        } catch (Throwable $error) {
            return response()->json(['error' => $error->getMessage() ?: 'Pembersihan akun gagal dijalankan.'], 503);
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
