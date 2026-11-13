<?php

namespace Tests\Feature;

use App\Http\Middleware\PublicAbuseProtection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PublicAbuseProtectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('public-abuse:reset-password:ip:198.51.100.42');
        RateLimiter::clear('public-abuse:reset-password:ip:198.51.100.43');
    }

    public function test_blocks_ip_spraying_different_emails(): void
    {
        $middleware = app(PublicAbuseProtection::class);
        $ip = '198.51.100.42';

        // Endpoint reset-password maxAttempts = 5. IP-level allows 5 * 4 = 20 attempts.
        for ($i = 0; $i < 20; $i++) {
            $request = Request::create('/reset-password', 'POST', [
                'email' => "user{$i}@example.com",
            ], server: ['REMOTE_ADDR' => $ip]);

            $response = $middleware->handle($request, fn () => response('ok'));
            $this->assertSame(200, $response->getStatusCode());
        }

        // 21st attempt from same IP must be blocked even with fresh email
        $this->expectException(ValidationException::class);

        $blockedRequest = Request::create('/reset-password', 'POST', [
            'email' => 'brand-new-user@example.com',
        ], server: ['REMOTE_ADDR' => $ip]);

        $middleware->handle($blockedRequest, fn () => response('ok'));
    }

    public function test_blocks_single_email_brute_force_from_same_ip(): void
    {
        $middleware = app(PublicAbuseProtection::class);
        $ip = '198.51.100.43';
        $email = 'target@example.com';

        for ($i = 0; $i < 5; $i++) {
            $request = Request::create('/reset-password', 'POST', [
                'email' => $email,
            ], server: ['REMOTE_ADDR' => $ip]);

            $response = $middleware->handle($request, fn () => response('ok'));
            $this->assertSame(200, $response->getStatusCode());
        }

        // 6th attempt to same email must be blocked
        $this->expectException(ValidationException::class);

        $blockedRequest = Request::create('/reset-password', 'POST', [
            'email' => $email,
        ], server: ['REMOTE_ADDR' => $ip]);

        $middleware->handle($blockedRequest, fn () => response('ok'));
    }
}
