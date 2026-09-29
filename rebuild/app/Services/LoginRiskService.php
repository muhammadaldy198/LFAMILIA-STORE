<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class LoginRiskService
{
    private const THRESHOLD = 3;

    public function requiresChallenge(string $scope, ?string $ip, ?string $identity = null): bool
    {
        return $this->failures($scope, $ip, $identity) >= self::THRESHOLD;
    }

    public function recordFailure(string $scope, ?string $ip, ?string $identity = null): int
    {
        $key = $this->key($scope, $ip, $identity);
        Cache::add($key, 0, now()->addMinutes(30));

        return (int) Cache::increment($key);
    }

    public function clear(string $scope, ?string $ip, ?string $identity = null): void
    {
        Cache::forget($this->key($scope, $ip, $identity));
    }

    public function failures(string $scope, ?string $ip, ?string $identity = null): int
    {
        return (int) Cache::get($this->key($scope, $ip, $identity), 0);
    }

    private function key(string $scope, ?string $ip, ?string $identity): string
    {
        $safeScope = preg_replace('/[^a-z0-9_-]/i', '', $scope);
        $normalizedIdentity = strtolower(trim((string) $identity));

        return 'login-risk:'.$safeScope.':'.hash('sha256', $normalizedIdentity.'|'.(string) $ip);
    }
}
