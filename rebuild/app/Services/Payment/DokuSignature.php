<?php

namespace App\Services\Payment;

use Carbon\CarbonImmutable;
use Throwable;

class DokuSignature
{
    public function digest(string $body): string
    {
        return base64_encode(hash('sha256', $body, true));
    }

    public function sign(
        string $clientId,
        string $requestId,
        string $timestamp,
        string $target,
        string $body,
        string $secretKey,
    ): string {
        $component = implode("\n", [
            'Client-Id:'.$clientId,
            'Request-Id:'.$requestId,
            'Request-Timestamp:'.$timestamp,
            'Request-Target:'.$target,
            'Digest:'.$this->digest($body),
        ]);

        return 'HMACSHA256='.base64_encode(hash_hmac('sha256', $component, $secretKey, true));
    }

    public function signResponse(
        string $clientId,
        string $requestId,
        string $timestamp,
        string $target,
        string $body,
        string $secretKey,
    ): string {
        $component = implode("\n", [
            'Client-Id:'.$clientId,
            'Request-Id:'.$requestId,
            'Response-Timestamp:'.$timestamp,
            'Request-Target:'.$target,
            'Digest:'.$this->digest($body),
        ]);

        return 'HMACSHA256='.base64_encode(hash_hmac('sha256', $component, $secretKey, true));
    }

    public function verifyResponse(
        string $signature,
        string $clientId,
        string $requestId,
        string $timestamp,
        string $target,
        string $body,
        string $secretKey,
    ): bool {
        return hash_equals(
            $this->signResponse($clientId, $requestId, $timestamp, $target, $body, $secretKey),
            $signature
        );
    }

    public function isFreshTimestamp(string $timestamp, int $windowSeconds = 300): bool
    {
        try {
            $parsed = CarbonImmutable::parse($timestamp);
        } catch (Throwable) {
            return false;
        }

        return abs(now('UTC')->getTimestamp() - $parsed->getTimestamp()) <= $windowSeconds;
    }

    public function verify(
        string $signature,
        string $clientId,
        string $requestId,
        string $timestamp,
        string $target,
        string $body,
        string $secretKey,
    ): bool {
        return hash_equals(
            $this->sign($clientId, $requestId, $timestamp, $target, $body, $secretKey),
            $signature
        );
    }
}
