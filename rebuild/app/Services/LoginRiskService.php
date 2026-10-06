<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class LoginRiskService
{
    private const THRESHOLD = 3;
    private const LOCKOUT_THRESHOLD = 10;
    private const LOCKOUT_MINUTES = 15;

    public function requiresChallenge(string $scope, ?string $ip, ?string $identity = null): bool
    {
        return $this->failures($scope, $ip, $identity) >= self::THRESHOLD;
    }

    public function recordFailure(string $scope, ?string $ip, ?string $identity = null): int
    {
        $key = $this->key($scope, $ip, $identity);
        Cache::add($key, 0, now()->addMinutes(30));

        $failures = (int) Cache::increment($key);

        // Progressive lockout: 10 failures = 15 min lockout
        if ($failures >= self::LOCKOUT_THRESHOLD) {
            Cache::put($this->lockoutKey($scope, $ip, $identity), true, now()->addMinutes(self::LOCKOUT_MINUTES));
        }

        return $failures;
    }

    public function clear(string $scope, ?string $ip, ?string $identity = null): void
    {
        Cache::forget($this->key($scope, $ip, $identity));
        Cache::forget($this->lockoutKey($scope, $ip, $identity));
    }

    public function failures(string $scope, ?string $ip, ?string $identity = null): int
    {
        return (int) Cache::get($this->key($scope, $ip, $identity), 0);
    }

    public function isLockedOut(string $scope, ?string $ip, ?string $identity = null): bool
    {
        return (bool) Cache::get($this->lockoutKey($scope, $ip, $identity), false);
    }

    private function key(string $scope, ?string $ip, ?string $identity): string
    {
        $safeScope = preg_replace('/[^a-z0-9_-]/i', '', $scope);
        $normalizedIdentity = strtolower(trim((string) $identity));

        return 'login-risk:'.$safeScope.':'.hash('sha256', $normalizedIdentity.'|'.(string) $ip);
    }

    private function lockoutKey(string $scope, ?string $ip, ?string $identity): string
    {
        return $this->key($scope, $ip, $identity).':locked';
    }
}
