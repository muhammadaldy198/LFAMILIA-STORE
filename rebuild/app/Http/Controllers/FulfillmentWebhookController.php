<?php

namespace App\Http\Controllers;

use App\Services\Fulfillment\DigiflazzClient;
use App\Services\FulfillmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FulfillmentWebhookController
{
    public function digiflazz(
        Request $request,
        DigiflazzClient $digiflazz,
        FulfillmentService $fulfillment,
    ): JsonResponse {
        $raw = $request->getContent();
        $signature = (string) $request->header('X-Hub-Signature', '');
        $event = strtolower((string) $request->header('X-Digiflazz-Event', ''));
        $userAgent = (string) $request->userAgent();

        abort_unless(in_array($event, ['create', 'update'], true), 400);
        abort_unless($userAgent === 'Digiflazz-Hookshot', 400);

        $expected = 'sha1='.hash_hmac('sha1', $raw, $digiflazz->webhookSecret());
        abort_unless($signature !== '' && hash_equals($expected, $signature), 401);

        $payload = $request->json()->all();
        $data = $payload['data'] ?? null;
        abort_unless(is_array($data) && isset($data['ref_id']) && is_scalar($data['ref_id']), 400);

        $attempt = DB::table('fulfillment_attempts')
            ->where('external_reference', (string) $data['ref_id'])->first();
        abort_unless($attempt, 404);

        $eventId = hash('sha256', $event.'|'.$raw);
        $claimed = DB::table('fulfillment_callbacks')->insertOrIgnore([
            'fulfillment_attempt_id' => $attempt->id,
            'provider_code' => 'DIGIFLAZZ',
            'event_id' => $eventId,
            'payload_hash' => hash('sha256', $raw),
            'result' => 'PROCESSING',
            'received_at' => now(),
        ]) === 1;

        if (! $claimed) {
            return response()->json(['status' => 'duplicate']);
        }

        try {
            $fulfillment->applyProviderResult((int) $attempt->id, $data, 'webhook');
            DB::table('fulfillment_callbacks')
                ->where('provider_code', 'DIGIFLAZZ')->where('event_id', $eventId)
                ->update(['result' => 'APPLIED']);
        } catch (\Throwable $exception) {
            DB::table('fulfillment_callbacks')
                ->where('provider_code', 'DIGIFLAZZ')->where('event_id', $eventId)
                ->delete();
            throw $exception;
        }

        return response()->json(['status' => 'ok']);
    }
}
