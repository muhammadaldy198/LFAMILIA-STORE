<?php

namespace App\Http\Controllers;

use App\Services\AdminAuthService;
use App\Services\SecurityGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class AdminSupportController extends Controller
{
    public function index(Request $request, AdminAuthService $auth): JsonResponse
    {
        try {
            $access = $auth->require($request, 'staff');

            $rows = DB::table('customer_support_requests as r')
                ->join('customer_users as c', 'c.id', '=', 'r.customer_id')
                ->orderByRaw("CASE r.status WHEN 'open' THEN 0 WHEN 'in_progress' THEN 1 ELSE 2 END")
                ->orderByDesc('r.updated_at')
                ->limit(100)
                ->get([
                    'r.id', 'r.kind', 'r.order_reference', 'r.subject', 'r.message',
                    'r.status', 'r.staff_reply', 'r.created_at', 'r.updated_at',
                    'c.name as customer_name', 'c.email as customer_email',
                ]);

            return response()->json([
                'requests' => $rows,
                'role' => $access['role'],
            ], 200, ['Cache-Control' => 'no-store']);
        } catch (RuntimeException $error) {
            return $this->accessError($error);
        } catch (Throwable $error) {
            return response()->json([
                'error' => $error->getMessage() ?: 'Bantuan gagal dimuat.',
            ], 503);
        }
    }

    public function update(
        Request $request,
        AdminAuthService $auth,
        SecurityGuard $security,
    ): JsonResponse {
        $security->assertSameOrigin($request);

        try {
            $access = $auth->require($request, 'staff');
            $input = $request->validate([
                'id' => ['required', 'uuid'],
                'status' => ['required', 'in:open,in_progress,resolved,rejected'],
                'reply' => ['nullable', 'string', 'max:2000'],
            ]);

            $changed = DB::table('customer_support_requests')
                ->where('id', $input['id'])
                ->update([
                    'status' => $input['status'],
                    'staff_reply' => trim((string) ($input['reply'] ?? '')) ?: null,
                    'handled_by' => $access['email'],
                    'handled_at' => now(),
                    'updated_at' => now(),
                ]);

            if ($changed !== 1 && !DB::table('customer_support_requests')->where('id', $input['id'])->exists()) {
                return response()->json(['error' => 'Permintaan bantuan tidak ditemukan.'], 404);
            }

            return response()->json(['ok' => true]);
        } catch (ValidationException $error) {
            return response()->json(['error' => $error->validator->errors()->first()], 400);
        } catch (RuntimeException $error) {
            return $this->accessError($error);
        } catch (Throwable $error) {
            return response()->json([
                'error' => $error->getMessage() ?: 'Balasan gagal disimpan.',
            ], 400);
        }
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
