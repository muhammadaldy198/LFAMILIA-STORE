<?php

namespace App\Http\Controllers;

use App\Models\NicknameGameCode;
use App\Services\AccountValidationConfig;
use App\Services\AdminAuditService;
use App\Services\AdminNicknameToolsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class AdminNicknameController
{
    public function index(
        AdminNicknameToolsService $tools,
        AccountValidationConfig $config,
    ): Response {
        return Inertia::render('Admin/NicknameTools', [
            'gameCodes' => $tools->gameCodes(),
            'validationConfig' => $config->summary(),
        ]);
    }

    public function check(Request $request, AdminNicknameToolsService $tools): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['game', 'region', 'pln'])],
            'game_code' => ['nullable', 'string', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'max:100'],
            'user_id' => ['nullable', 'string', 'min:2', 'max:80'],
            'server' => ['nullable', 'string', 'max:40'],
            'customer_number' => ['nullable', 'regex:/^\d{11,12}$/'],
        ]);

        try {
            if ($data['action'] === 'game') {
                validator($data, [
                    'game_code' => ['required'],
                    'user_id' => ['required'],
                ])->validate();

                $result = $tools->checkGame(
                    $data['game_code'],
                    $data['user_id'],
                    $data['server'] ?? null,
                );

                return response()->json(['ok' => true, ...$result])
                    ->header('Cache-Control', 'no-store');
            }

            if ($data['action'] === 'region') {
                validator($data, [
                    'game_code' => ['required'],
                    'user_id' => ['required'],
                    'server' => ['required'],
                ])->validate();

                return response()->json([
                    'ok' => true,
                    ...$tools->checkRegion($data['game_code'], $data['user_id'], $data['server']),
                ])->header('Cache-Control', 'no-store');
            }

            validator($data, ['customer_number' => ['required']])->validate();

            return response()->json([
                'ok' => true,
                'customer_name' => $tools->checkPln($data['customer_number']),
            ])->header('Cache-Control', 'no-store');
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 503)
                ->header('Cache-Control', 'no-store');
        }
    }

    public function storeGameCode(
        Request $request,
        AdminNicknameToolsService $tools,
        AdminAuditService $audit,
    ): JsonResponse {
        $data = $this->validatedGameCode($request);
        $data['sort_order'] = $data['sort_order']
            ?? ((int) NicknameGameCode::max('sort_order') + 1);

        $gameCode = NicknameGameCode::create($data);
        $audit->record(
            $request,
            'nickname_game_code.created',
            'nickname_game_code',
            $gameCode->id,
            null,
            $gameCode->toArray(),
        );

        return $this->gameCodesResponse($tools, 201);
    }

    public function updateGameCode(
        Request $request,
        NicknameGameCode $gameCode,
        AdminNicknameToolsService $tools,
        AdminAuditService $audit,
    ): JsonResponse {
        $before = $gameCode->toArray();
        $data = $this->validatedGameCode($request, $gameCode);
        $data['sort_order'] = $data['sort_order'] ?? $gameCode->sort_order;
        $oldCode = $gameCode->code;

        if ($gameCode->is_active && ! $data['is_active']) {
            $usage = DB::table('products')
                ->where('nickname_game_code', $oldCode)
                ->count();

            if ($usage > 0) {
                throw ValidationException::withMessages([
                    'is_active' => 'Kode game masih digunakan oleh '.$usage.' produk. Pindahkan atau nonaktifkan validasi pada produk terkait sebelum menonaktifkan kode game.',
                ]);
            }
        }

        DB::transaction(function () use ($gameCode, $data, $oldCode): void {
            $gameCode->fill($data);
            $gameCode->save();

            if ($oldCode !== $gameCode->code) {
                DB::table('products')
                    ->where('nickname_game_code', $oldCode)
                    ->update([
                        'nickname_game_code' => $gameCode->code,
                        'updated_at' => now(),
                    ]);
            }
        }, 3);

        $gameCode->refresh();
        $audit->record(
            $request,
            'nickname_game_code.updated',
            'nickname_game_code',
            $gameCode->id,
            $before,
            $gameCode->toArray(),
        );

        return $this->gameCodesResponse($tools);
    }

    public function destroyGameCode(
        Request $request,
        NicknameGameCode $gameCode,
        AdminNicknameToolsService $tools,
        AdminAuditService $audit,
    ): JsonResponse {
        $usage = DB::table('products')
            ->where('nickname_game_code', $gameCode->code)
            ->count();

        if ($usage > 0) {
            throw ValidationException::withMessages([
                'game_code' => 'Kode game masih digunakan oleh '.$usage.' produk. Nonaktifkan dulu atau pindahkan konfigurasi produk sebelum menghapus.',
            ]);
        }

        $before = $gameCode->toArray();
        $gameCode->delete();
        $audit->record(
            $request,
            'nickname_game_code.deleted',
            'nickname_game_code',
            $before['id'],
            $before,
            null,
        );

        return $this->gameCodesResponse($tools);
    }

    public function reorderGameCodes(
        Request $request,
        AdminNicknameToolsService $tools,
        AdminAuditService $audit,
    ): JsonResponse {
        $data = $request->validate([
            'ordered_ids' => ['required', 'array', 'min:1', 'max:500'],
            'ordered_ids.*' => ['required', 'integer', 'distinct', Rule::exists('nickname_game_codes', 'id')],
        ]);

        $orderedIds = array_map('intval', $data['ordered_ids']);
        $allIds = NicknameGameCode::query()->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $submittedIds = $orderedIds;
        sort($allIds);
        sort($submittedIds);

        if ($submittedIds !== $allIds) {
            throw ValidationException::withMessages([
                'ordered_ids' => 'Urutan harus memuat seluruh kode game yang tersedia.',
            ]);
        }

        $before = NicknameGameCode::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        DB::transaction(function () use ($orderedIds): void {
            foreach ($orderedIds as $index => $id) {
                NicknameGameCode::whereKey($id)->update([
                    'sort_order' => $index,
                    'updated_at' => now(),
                ]);
            }
        }, 3);

        $audit->record(
            $request,
            'nickname_game_code.reordered',
            'nickname_game_code',
            'collection',
            ['ordered_ids' => $before],
            ['ordered_ids' => $orderedIds],
        );

        return $this->gameCodesResponse($tools);
    }

    /**
     * @return array{name:string,code:string,supports_nickname_check:bool,requires_server:bool,requires_region_check:bool,is_active:bool,sort_order?:int}
     */
    private function validatedGameCode(Request $request, ?NicknameGameCode $gameCode = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => [
                'required',
                'string',
                'max:100',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('nickname_game_codes', 'code')->ignore($gameCode?->id),
            ],
            'supports_nickname_check' => ['required', 'boolean'],
            'requires_server' => ['required', 'boolean'],
            'requires_region_check' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);

        $data['name'] = trim($data['name']);
        $data['code'] = strtolower(trim($data['code']));
        $data['supports_nickname_check'] = (bool) $data['supports_nickname_check'];
        $data['requires_region_check'] = $data['supports_nickname_check']
            && (bool) $data['requires_region_check'];
        $data['requires_server'] = $data['supports_nickname_check']
            && ((bool) $data['requires_server'] || $data['requires_region_check']);
        $data['is_active'] = (bool) $data['is_active'];

        return $data;
    }

    private function gameCodesResponse(AdminNicknameToolsService $tools, int $status = 200): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'game_codes' => $tools->gameCodes(),
        ], $status)->header('Cache-Control', 'no-store');
    }
}
