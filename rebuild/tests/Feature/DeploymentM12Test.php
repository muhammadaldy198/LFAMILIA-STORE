<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use LogicException;
use Tests\TestCase;

class DeploymentM12Test extends TestCase
{
    public function test_trusted_proxy_uses_forwarded_https_and_customer_ip(): void
    {
        Route::get('/_m12/proxy-check', function (Request $request) {
            return response()->json([
                'secure' => $request->isSecure(),
                'ip' => $request->ip(),
            ]);
        });

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->withHeaders([
                'X-Forwarded-For' => '198.51.100.25',
                'X-Forwarded-Proto' => 'https',
            ])
            ->get('/_m12/proxy-check')
            ->assertOk()
            ->assertJson([
                'secure' => true,
                'ip' => '198.51.100.25',
            ]);
    }

    /**
     * NV-16/NV-22: with trusted proxies restricted to the loopback hops, a
     * request whose direct peer is not a trusted proxy must not be able to
     * name its own client IP through X-Forwarded-For - every IP-keyed
     * limiter in the application reads $request->ip().
     */
    public function test_untrusted_remote_cannot_spoof_client_ip_with_forwarded_for(): void
    {
        TrustProxies::flushState();
        TrustProxies::at(['127.0.0.1', '::1']);
        TrustProxies::withHeaders(
            Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO
        );

        Route::get('/_m12/proxy-restricted', function (Request $request) {
            return response()->json(['ip' => $request->ip()]);
        });

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->withHeaders(['X-Forwarded-For' => '198.51.100.25'])
            ->get('/_m12/proxy-restricted')
            ->assertOk()
            ->assertJson(['ip' => '203.0.113.10']);
    }

    /**
     * NV-16/NV-22: production boot must refuse the trusted-proxies
     * wildcard outright, not merely require a non-empty value.
     */
    public function test_production_boot_rejects_wildcard_trusted_proxies(): void
    {
        config([
            'app.debug' => false,
            'app.url' => 'https://example.test',
            'session.encrypt' => true,
            'session.secure' => true,
            'lfamilia.trusted_hosts' => ['example.test'],
            'lfamilia.trusted_proxies' => '*',
        ]);
        $this->app->detectEnvironment(fn (): string => 'production');

        try {
            (new AppServiceProvider($this->app))->boot();
            $this->fail('Production boot must reject the trusted-proxies wildcard.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('TRUSTED_PROXIES', $exception->getMessage());
            $this->assertStringContainsString('wildcard', $exception->getMessage());
        }

        config(['lfamilia.trusted_proxies' => ['127.0.0.1', '::1']]);
        (new AppServiceProvider($this->app))->boot();
        $this->addToAssertionCount(1);
    }

    public function test_application_uses_wib_and_deployment_guards_its_cached_configuration(): void
    {
        $this->assertSame('Asia/Jakarta', config('app.timezone'));
        // The SQL storage clock stays UTC so historical order expiries are
        // not reinterpreted seven hours early when the UI timezone is WIB.
        $this->assertSame('UTC', date_default_timezone_get());
        $this->assertSame('Asia/Jakarta', app('log')->getLogger()->getTimezone()->getName());

        $beforeExpiryUtc = new \DateTimeImmutable('2026-11-02 05:00:00', new \DateTimeZone('UTC'));
        $expiresAt = new \DateTimeImmutable('2026-11-02 05:30:00');
        $this->assertLessThan($expiresAt, $beforeExpiryUtc);

        $config = file_get_contents(base_path('config/app.php'));
        $preflight = file_get_contents(base_path('deploy/preflight.sh'));
        $deploy = file_get_contents(base_path('deploy/deploy.sh'));
        $rollback = file_get_contents(base_path('deploy/rollback-source.sh'));

        $this->assertStringContainsString("env('APP_TIMEZONE', 'Asia/Jakarta')", $config);
        $this->assertStringContainsString("'APP_TIMEZONE=Asia/Jakarta'", $preflight);
        $this->assertStringContainsString('artisan config:cache', $deploy);
        $this->assertStringContainsString('bootstrap/cache/config.php', $deploy);
        $this->assertStringContainsString('=== "Asia/Jakarta"', $deploy);
        $this->assertStringContainsString('bootstrap/cache/config.php', $rollback);
        $this->assertStringContainsString('rollback_timezone="UTC"', $rollback);
        $this->assertStringContainsString('[[ -f config/app.php ]]', $rollback);
        $this->assertStringContainsString('rollback_timezone="Asia/Jakarta"', $rollback);
        $this->assertStringContainsString('getenv("ROLLBACK_EXPECTED_TIMEZONE")', $rollback);
    }

    public function test_m12_production_templates_keep_secrets_out_of_git(): void
    {
        $production = file_get_contents(base_path('deploy/production.env.example'));
        $ops = file_get_contents(base_path('deploy/ops.env.example'));

        $this->assertStringContainsString("APP_ENV=production\n", $production);
        $this->assertStringContainsString("APP_DEBUG=false\n", $production);
        $this->assertStringContainsString("SESSION_SECURE_COOKIE=true\n", $production);
        $this->assertStringContainsString("TRUSTED_PROXIES=127.0.0.1,::1\n", $production);
        $this->assertStringContainsString("DB_PASSWORD=\n", $production);
        $this->assertStringContainsString("APP_KEY=\n", $production);

        $preflight = file_get_contents(base_path('deploy/preflight.sh'));
        $deploy = file_get_contents(base_path('deploy/deploy.sh'));
        $this->assertStringContainsString('TRUSTED_PROXIES must not be the wildcard', $preflight);
        $this->assertStringContainsString('proxies_value}" ==', $preflight, 'Preflight must compare the parsed effective proxy value, not a raw line pattern.');
        $this->assertStringContainsString('s/#.*//', $preflight, 'Preflight must strip dotenv comments (adjacent or spaced) before judging the proxy value.');
        $this->assertStringContainsString('must be written literally', $preflight, 'Preflight must reject dotenv variable references for the proxy value.');
        $this->assertStringContainsString('cloudpanel-vhost-snippet.conf', $preflight);
        $this->assertStringContainsString('lfamilia\.com', $preflight, 'Preflight must scope the nginx deny check to the lfamilia.com server block.');
        $this->assertStringContainsString('DEPLOY_EXPECTED_SHA', $deploy);
        $this->assertStringContainsString('does not match DEPLOY_EXPECTED_SHA', $deploy);
        $pinCheckAt = strpos($deploy, 'does not match DEPLOY_EXPECTED_SHA');
        $checkoutAt = strpos($deploy, 'git_repo checkout');
        $this->assertNotFalse($pinCheckAt);
        $this->assertNotFalse($checkoutAt);
        $this->assertLessThan($checkoutAt, $pinCheckAt, 'The expected-SHA pin must be checked before checkout mutates the live tree.');
        $this->assertSame(2, substr_count($deploy, 'does not match DEPLOY_EXPECTED_SHA'), 'The pin must be verified twice: fetched ref before checkout, and HEAD after the merge.');
        $mergeAt = strpos($deploy, 'merge --ff-only');
        $lastPinCheckAt = strrpos($deploy, 'does not match DEPLOY_EXPECTED_SHA');
        $this->assertNotFalse($mergeAt);
        $this->assertGreaterThan($mergeAt, $lastPinCheckAt, 'The post-merge HEAD must be re-verified against the pin.');
        $this->assertStringContainsString('DEPLOY_EXPECTED_SHA=', $ops);
        $this->assertStringContainsString('MYSQL_DEFAULTS_FILE=', $ops);
        $this->assertStringContainsString('BACKUP_PASSPHRASE_FILE=', $ops);
        $this->assertStringContainsString('BACKUP_REQUIRE_REMOTE=true', $ops);
        $this->assertStringNotContainsString('password=', strtolower($ops));
    }
}
