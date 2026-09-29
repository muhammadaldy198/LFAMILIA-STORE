<?php

namespace App\Http\Controllers;

use App\Services\DigiflazzFulfillmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DigiflazzCallbackController extends Controller
{
    public function handle(Request $request, DigiflazzFulfillmentService $fulfillment): JsonResponse
    {
        $rawBody = $request->getContent();
        if (!$fulfillment->verifyWebhook($rawBody, $request->header('x-hub-signature'))) {
            return response()->json(['error' => 'Signature webhook tidak valid.'], 401);
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            return response()->json(['error' => 'Payload webhook tidak valid.'], 400);
        }

        $event = strtolower(trim((string) $request->header('x-digiflazz-event', '')));
        $isPing = $event === 'ping'
            || (!isset($payload['data']) && isset($payload['hook_id']) && isset($payload['hook']));
        if ($isPing) {
            return response()->json(['ok' => true, 'event' => 'ping']);
        }

        $data = $payload['data'] ?? null;
        if (!is_array($data) || trim((string) ($data['ref_id'] ?? '')) === '') {
            return response()->json(['error' => 'Ref ID DigiFlazz tidak ada.'], 400);
        }

        $refId = trim((string) $data['ref_id']);
        $normalizedStatus = strtolower(trim((string) ($data['status'] ?? '')));
        $status = $fulfillment->mapStatus($normalizedStatus, (string) ($data['rc'] ?? ''));
        $message = trim((string) ($data['message'] ?? ''))
            ?: 'Status DigiFlazz: '.((string) ($data['status'] ?? 'pending'));
        $serialNumber = trim((string) ($data['sn'] ?? '')) ?: null;

        $semantic = json_encode([
            'refId' => $refId,
            'status' => $normalizedStatus,
            'message' => $message,
            'serialNumber' => $serialNumber,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $eventId = 'digiflazz-event-'.hash('sha256', $semantic);

        $fulfillment->applyWebhook($refId, $eventId, [
            'externalId' => $refId,
            'status' => $status,
            'message' => $message,
            'serialNumber' => $serialNumber,
            'raw' => $payload,
        ]);

        return response()->json(['ok' => true]);
    }
}
