<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\SavedGameAccount;
use App\Services\CheckoutInputValidator;
use App\Services\NicknameService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SavedGameAccountController
{
    public function store(
        Request $request,
        CheckoutInputValidator $validator,
        NicknameService $nickname,
    ): JsonResponse {
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'label' => ['required', 'string', 'max:100'],
            'customer_input' => ['required', 'array', 'max:20'],
            'customer_input.*' => ['nullable', 'string', 'max:255'],
        ]);

        $product = Product::with('fields')->where('id', $data['product_id'])->where('is_active', true)->firstOrFail();
        $input = $validator->validate($product, $data['customer_input']);
        $checked = $product->nickname_check_enabled ? $nickname->check($product, $input) : null;

        $saved = SavedGameAccount::create([
            'user_id' => $request->user()->id,
            'product_id' => $product->id,
            'label' => trim($data['label']),
            'customer_input' => $input,
            'nickname' => is_array($checked) && ($checked['verified'] ?? false) ? ($checked['nickname'] ?? null) : null,
        ]);

        return response()->json([
            'id' => $saved->id,
            'label' => $saved->label,
            'customer_input' => $saved->customer_input,
            'nickname' => $saved->nickname,
        ], 201);
    }

    public function destroy(Request $request, SavedGameAccount $savedGameAccount): JsonResponse
    {
        abort_unless((int) $savedGameAccount->user_id === (int) $request->user()->id, 404);
        $savedGameAccount->delete();

        return response()->json(['deleted' => true]);
    }
}
