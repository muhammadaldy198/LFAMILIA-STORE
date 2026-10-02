<?php

namespace App\Http\Controllers;

use App\Services\AdminNicknameToolsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class AdminNicknameController
{
    public function index(AdminNicknameToolsService $tools): Response
    {
        return Inertia::render('Admin/NicknameTools', [
            'gameCodes' => $tools->gameCodes(),
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
                    'user_id' => ['required'],
                    'server' => ['required'],
                ])->validate();

                return response()->json([
                    'ok' => true,
                    ...$tools->checkRegion($data['user_id'], $data['server']),
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
}
