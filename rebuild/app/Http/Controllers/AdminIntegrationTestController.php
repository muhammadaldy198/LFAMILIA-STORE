<?php

namespace App\Http\Controllers;

use App\Jobs\SendTransactionalEmailJob;
use App\Models\IntegrationCredential;
use App\Services\AdminAuditService;
use App\Services\AdminNotificationService;
use App\Services\IntegrationConnectionService;
use App\Services\IntegrationRegistry;
use App\Services\IntegrationRuntimeConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Mengetes integrasi dari panel admin: mencoba koneksi memakai konfigurasi
 * yang tersimpan dan mengirim email percobaan lewat Resend. Hanya super
 * admin. Class ini tidak menyimpan perubahan apa pun.
 */
class AdminIntegrationTestController
{
    public function test(
        Request $request,
        string $code,
        IntegrationRegistry $registry,
        IntegrationRuntimeConfig $runtime,
        IntegrationConnectionService $connections,
        AdminAuditService $audit,
        AdminNotificationService $notifications,
    ): JsonResponse {
        $this->ensureSuperAdmin($request);

        $definition = $registry->get($code);
        abort_unless($definition, 404);

        $resolved = $runtime->resolve($code, false);
        $config = $resolved['config'] ?? [];
        $environment = $resolved['environment'] ?? null;

        $missing = collect($registry->requiredFields($code))
            ->filter(function (string $field) use ($config): bool {
                $value = $config[$field] ?? null;

                return $value === null || $value === '' || $value === [];
            })
            ->values();

        $result = $missing->isNotEmpty()
            ? [
                'status' => 'NOT_CONFIGURED',
                'message' => 'Lengkapi credential wajib untuk environment yang dipilih sebelum menjalankan Tes Koneksi.',
                'reason' => 'CREDENTIAL_MISSING',
                'verified' => false,
            ]
            : $connections->test($code, $config, $environment);

        $health = [
            ...$result,
            'environment' => $environment,
            'tested_at' => now()->toIso8601String(),
        ];

        DB::table('system_settings')->updateOrInsert(
            ['key' => 'integration.health.'.$code],
            [
                'value' => json_encode($health, JSON_THROW_ON_ERROR),
                'updated_by_admin_id' => $request->user('admin')->id,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        $audit->record($request, 'integration.connection.tested', 'integration_credential', $code, null, $health);

        if ($result['status'] === 'DOWN') {
            $notifications->record(
                'integration.error',
                'Integrasi bermasalah',
                $definition['name'].' gagal melewati Tes Koneksi.',
                'ERROR',
                'integration_credential',
                $code
            );
        }

        return response()->json($health)
            ->header('Cache-Control', 'no-store, private');
    }

    public function sendTestEmail(Request $request, AdminAuditService $audit): JsonResponse
    {
        $this->ensureSuperAdmin($request);
        $validated = $request->validate([
            'recipient' => ['required', 'email:rfc', 'max:254'],
        ]);

        $record = IntegrationCredential::where('code', 'resend')->where('is_active', true)->first();
        $config = $record?->config_ciphertext;
        if (! is_array($config) || empty($config['api_key']) || empty($config['from_email'])) {
            return response()->json(['message' => 'Resend belum aktif atau belum lengkap.'], 422);
        }

        try {
            (new SendTransactionalEmailJob(
                strtolower($validated['recipient']),
                'Tes Email LFAMILIA STORE',
                "Halo!\n\nIni adalah email uji dari halaman Integrasi Resend LFAMILIA STORE.\n\nJika email ini diterima, layanan pengiriman email telah menerima permintaan uji.\n\nJangan balas pesan otomatis ini."
            ))->handle();
        } catch (Throwable $exception) {
            $audit->record($request, 'integration.resend.test_email.failed', 'integration_credential', 'resend', null, [
                'recipient' => strtolower($validated['recipient']),
                'error_type' => class_basename($exception),
            ]);

            return response()->json(['message' => 'Pengiriman ditolak atau gagal. Periksa izin Sending Access, alamat pengirim, dan log server.'], 502);
        }

        $audit->record($request, 'integration.resend.test_email.sent', 'integration_credential', 'resend', null, [
            'recipient' => strtolower($validated['recipient']),
        ]);

        return response()->json([
            'status' => 'OK',
            'message' => 'Resend menerima permintaan pengiriman email uji. Periksa kotak masuk dan folder spam.',
        ])->header('Cache-Control', 'no-store, private');
    }

    private function ensureSuperAdmin(Request $request): void
    {
        abort_unless($request->user('admin')?->role === 'SUPER_ADMIN', 403);
    }
}
