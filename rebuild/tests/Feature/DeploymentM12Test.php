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

    public function test_production_boot_rejects_wildcard_entry_in_trusted_proxies_list(): void
    {
        config([
            'app.debug' => false,
            'app.url' => 'https://example.test',
            'session.encrypt' => true,
            'session.secure' => true,
            'lfamilia.trusted_hosts' => ['example.test'],
            'lfamilia.trusted_proxies' => ['127.0.0.1', '*'],
        ]);
        $this->app->detectEnvironment(fn (): string => 'production');

        try {
            (new AppServiceProvider($this->app))->boot();
            $this->fail('Production boot must reject a wildcard entry in the trusted-proxies list.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('wildcard', $exception->getMessage());
        }
    }

    public function test_production_boot_rejects_malformed_trusted_proxy_entries(): void
    {
        config([
            'app.debug' => false,
            'app.url' => 'https://example.test',
            'session.encrypt' => true,
            'session.secure' => true,
            'lfamilia.trusted_hosts' => ['example.test'],
            'lfamilia.trusted_proxies' => ['bogus'],
        ]);
        $this->app->detectEnvironment(fn (): string => 'production');

        try {
            (new AppServiceProvider($this->app))->boot();
            $this->fail('Production boot must reject a malformed trusted-proxy entry.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('TRUSTED_PROXIES', $exception->getMessage());
            $this->assertStringContainsString('CIDR', $exception->getMessage());
        }

        config(['lfamilia.trusted_proxies' => ['10.0.0.0/0']]);
        try {
            (new AppServiceProvider($this->app))->boot();
            $this->fail('Production boot must reject a catch-all trusted-proxy CIDR.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('CIDR', $exception->getMessage());
        }

        config(['lfamilia.trusted_proxies' => ['10.0.0.0/8', '2001:db8::/32']]);
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
        $this->assertStringContainsString('%%[[:space:]]#*', $preflight, 'Preflight must strip dotenv comments (adjacent or spaced) before judging the proxy value.');
        $this->assertStringContainsString('must be written literally', $preflight, 'Preflight must reject dotenv variable references for the proxy value.');
        $this->assertStringContainsString('cloudpanel-vhost-snippet.conf', $preflight);
        $this->assertStringContainsString('== "lfamilia.com"', $preflight, 'Preflight must scope the nginx deny check to a server block whose server_name contains the exact lfamilia.com hostname token.');
        $this->assertStringContainsString('deny all;', $preflight, 'Preflight must verify the deny directive inside each location, not only the location pattern.');
        $this->assertStringContainsString('if (c == "#") break', $preflight, 'Preflight must ignore commented-out nginx directives when verifying the vhost.');
        $this->assertStringContainsString('allow [^;]*;', $preflight, 'Preflight must not credit a deny rule that an earlier allow rule overrides.');
        $this->assertStringContainsString('multiline values are not allowed', $preflight, 'Preflight must reject multiline TRUSTED_PROXIES values instead of examining only the assignment line.');
        $this->assertStringContainsString('must name at least one proxy', $preflight, 'Preflight must reject an empty TRUSTED_PROXIES value; the app resolves it to no proxies and the boot guard throws during deploy.');
        $this->assertStringContainsString('must not contain a wildcard entry', $preflight, 'Preflight must reject a wildcard entry anywhere in the TRUSTED_PROXIES list, not only as the whole value.');
        $this->assertStringContainsString('catch-all CIDR', $preflight, 'Preflight must reject catch-all proxy CIDRs as well as the literal wildcard.');
        $this->assertStringContainsString('function finalize(', $preflight, 'Preflight must verify the nginx deny rules in each matching server block independently.');
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
        $this->assertSame(2, substr_count($deploy, 'bash "${DEPLOY_DIR}/preflight.sh"'), 'Deploy must run preflight twice: from the installed tree before checkout, and from the target tree after the merge.');
        $targetPreflightAt = strrpos($deploy, 'bash "${DEPLOY_DIR}/preflight.sh"');
        $downAt = strpos($deploy, '"${PHP_BIN}" artisan down');
        $this->assertNotFalse($downAt);
        $this->assertGreaterThan($mergeAt, $targetPreflightAt, 'The target preflight must run after the checkout merge.');
        $this->assertLessThan($downAt, $targetPreflightAt, 'The target preflight must run before artisan down boots the target code.');
        $this->assertStringContainsString('git_repo reset --hard "${previous_sha}"', $deploy, 'A failed target preflight must restore the previous tree.');
        $readme = file_get_contents(base_path('deploy/README.md'));
        $this->assertStringContainsString('Catatan bootstrap guard TRUSTED_PROXIES', $readme, 'The README must document the first-rollout bootstrap for the trusted-proxies boot guard.');
        $this->assertStringContainsString('migrasikan `TRUSTED_PROXIES` di `.env` live dari `*`', $readme, 'The bootstrap must require migrating the live TRUSTED_PROXIES value off the wildcard before the first guarded deploy.');
        $this->assertStringContainsString('SEBELUM menjalankan `deploy.sh`', $readme, 'The bootstrap must require the target preflight to pass before the first guarded deploy runs.');
        $this->assertStringContainsString('bootstrap/cache/config.php', $readme, 'The bootstrap must cover the frozen configuration cache, whose trusted-proxies value the application actually reads.');
        $this->assertStringContainsString('holds a TRUSTED_PROXIES value the application will reject at boot', $preflight, 'Preflight must validate the frozen cached trusted-proxies value, not only the .env value.');
        $this->assertStringContainsString('DEPLOY_EXPECTED_SHA=', $ops);
        $this->assertStringContainsString('MYSQL_DEFAULTS_FILE=', $ops);
        $this->assertStringContainsString('BACKUP_PASSPHRASE_FILE=', $ops);
        $this->assertStringContainsString('BACKUP_REQUIRE_REMOTE=true', $ops);
        $this->assertStringNotContainsString('password=', strtolower($ops));
    }

    /**
     * NV-16/NV-21/NV-22: the preflight proxy and nginx checks must behave,
     * not merely contain the right strings - these tests execute the
     * shipped check blocks from deploy/preflight.sh against fixtures.
     */
    public function test_preflight_proxy_guard_behaves_against_fixtures(): void
    {
        $preflight = file_get_contents(base_path('deploy/preflight.sh'));
        $loop = $this->extractPreflightBlock(
            $preflight,
            'while IFS= read -r proxies_line; do',
            'done < <(grep -E \'^[[:space:]]*(export[[:space:]]+)?TRUSTED_PROXIES[[:space:]]*=\' "${ENV_FILE}" || true)'
        );

        $cases = [
            'loopback list passes' => ["TRUSTED_PROXIES=127.0.0.1,::1\n", 0],
            'bare wildcard fails' => ["TRUSTED_PROXIES=*\n", 1],
            'catch-all CIDRs fail' => ["TRUSTED_PROXIES=0.0.0.0/0,::/0\n", 1],
            'noncanonical zero-prefix CIDR fails' => ["TRUSTED_PROXIES=192.0.2.1/0\n", 1],
            'variable reference fails' => ["TRUSTED_PROXIES=\"\${PROXY_SETTING}\"\n", 1],
            'multiline value fails' => ["TRUSTED_PROXIES=\"127.0.0.1,\n*\"\n", 1],
            'empty quoted value fails' => ["TRUSTED_PROXIES=\"\"\n", 1],
            'wildcard entry among valid proxies fails' => ["TRUSTED_PROXIES=127.0.0.1,*\n", 1],
            'export-prefixed wildcard entry fails' => ["export TRUSTED_PROXIES=127.0.0.1,*\n", 1],
            'export-prefixed loopback list passes' => ["export TRUSTED_PROXIES=127.0.0.1,::1\n", 0],
            'leading-space wildcard fails' => [" TRUSTED_PROXIES=*\n", 1],
            'spaced-equals wildcard entry fails' => ["TRUSTED_PROXIES =127.0.0.1,*\n", 1],
            'spaced-equals loopback list passes' => ["TRUSTED_PROXIES = 127.0.0.1,::1\n", 0],
            'quoted hash does not hide a wildcard entry' => ["TRUSTED_PROXIES=\"127.0.0.1#note,*\"\n", 1],
            'unquoted hash without a space does not hide a wildcard entry' => ["TRUSTED_PROXIES=127.0.0.1#note,*\n", 1],
            'space hash comment still passes for loopback' => ["TRUSTED_PROXIES=127.0.0.1,::1 # note\n", 0],
            'malformed entry fails' => ["TRUSTED_PROXIES=bogus\n", 1],
            'malformed entry among valid proxies fails' => ["TRUSTED_PROXIES=127.0.0.1,bogus\n", 1],
            'out-of-range octet fails' => ["TRUSTED_PROXIES=999.1.1.1\n", 1],
            'leading-zero octet fails' => ["TRUSTED_PROXIES=010.0.0.1\n", 1],
            'oversized ipv4 prefix fails' => ["TRUSTED_PROXIES=10.0.0.0/33\n", 1],
            'valid ipv4 cidr passes' => ["TRUSTED_PROXIES=10.0.0.0/8\n", 0],
            'valid ipv6 cidr passes' => ["TRUSTED_PROXIES=2001:db8::/32\n", 0],
            'double-compressed ipv6 fails' => ["TRUSTED_PROXIES=1::2::3\n", 1],
            'oversized ipv6 prefix fails' => ["TRUSTED_PROXIES=::1/129\n", 1],
            'trailing comma still passes' => ["TRUSTED_PROXIES=127.0.0.1,\n", 0],
        ];

        foreach ($cases as $label => [$env, $expected]) {
            $dir = $this->makePreflightFixtureDir(['env.txt' => $env]);
            file_put_contents("{$dir}/run.sh", "ENV_FILE={$dir}/env.txt\n{$loop}\n");
            $this->assertSame($expected, $this->runBashScript("{$dir}/run.sh"), "Preflight proxy guard: {$label}.");
        }
    }

    public function test_preflight_cached_config_proxy_guard_behaves_against_fixtures(): void
    {
        $preflight = file_get_contents(base_path('deploy/preflight.sh'));
        $block = $this->extractPreflightBlock(
            $preflight,
            'proxies_cache_file="${APP_DIR}/bootstrap/cache/config.php"',
            '# end of cached-config proxy check'
        );
        $cache = fn (string $value): string => "<?php return ['lfamilia' => ['trusted_proxies' => {$value}]];\n";

        $cases = [
            'no cache file passes' => [null, 0],
            'cached loopback list passes' => [$cache("['127.0.0.1', '::1']"), 0],
            'cached valid cidrs pass' => [$cache("['10.0.0.0/8', '2001:db8::/32']"), 0],
            'cached wildcard fails' => [$cache("'*'"), 1],
            'cached wildcard entry fails' => [$cache("['127.0.0.1', '*']"), 1],
            'cached malformed entry fails' => [$cache("['bogus']"), 1],
            'cached catch-all cidr fails' => [$cache("['10.0.0.0/0']"), 1],
            'cached missing key fails' => ["<?php return ['app' => ['timezone' => 'Asia/Jakarta']];\n", 1],
            'cached empty list fails' => [$cache('[]'), 1],
        ];

        foreach ($cases as $label => [$contents, $expected]) {
            $dir = $this->makePreflightFixtureDir([]);
            if ($contents !== null) {
                mkdir("{$dir}/bootstrap/cache", 0777, true);
                file_put_contents("{$dir}/bootstrap/cache/config.php", $contents);
            }
            file_put_contents("{$dir}/run.sh", "APP_DIR={$dir}\nPHP_BIN=".PHP_BINARY."\n{$block}\n");
            $this->assertSame($expected, $this->runBashScript("{$dir}/run.sh"), "Preflight cached-config proxy guard: {$label}.");
        }
    }

    public function test_preflight_nginx_check_behaves_against_fixtures(): void
    {
        $preflight = file_get_contents(base_path('deploy/preflight.sh'));
        $start = strpos($preflight, 'if command -v nginx >/dev/null 2>&1; then');
        $this->assertNotFalse($start);
        $elseAt = strpos($preflight, "\nelse\n", $start);
        $this->assertNotFalse($elseAt);
        $endMarker = 'the vhost deny rules could not be verified." >&2';
        $end = strpos($preflight, $endMarker, $elseAt);
        $this->assertNotFalse($end);
        $block = substr($preflight, $start, $end - $start + strlen($endMarker))."\nfi\n";

        $location = static fn (string $pattern, string $directive = 'deny all;'): string => "    {$pattern} {\n        {$directive}\n    }";
        $p1 = 'location ~ /\.(?!well-known).*';
        $p2 = 'location ~* \.(?:env|log|sql|bak|ini)$';
        $p3 = 'location ~* ^/(?:storage/logs|bootstrap/cache)/';
        $complete = implode("\n", [$location($p1), $location($p2), $location($p3)]);
        $overridden = implode("\n", [$location($p1), $location($p2, "allow all;\n        deny all;"), $location($p3)]);
        $spacedTerminator = implode("\n", [$location($p1), $location($p2, "allow all ;\n        deny all;"), $location($p3)]);
        $spacedDeny = implode("\n", [$location($p1, 'deny all ;'), $location($p2, 'deny all ;'), $location($p3, 'deny all ;')]);
        $cidrOverride = implode("\n", [$location($p1), $location($p2, "allow 203.0.113.0/24;\n        deny all;"), $location($p3)]);
        $nestedDeny = implode("\n", ["    {$p1} {\n        limit_except POST {\n            deny all;\n        }\n    }", $location($p2), $location($p3)]);
        $shadowed = implode("\n", ["    location ~* \\.env$ {\n        allow all;\n    }", $location($p1), $location($p2), $location($p3)]);
        $nestedAllow = implode("\n", ["    {$p1} {\n        limit_except POST {\n            allow 203.0.113.0/24;\n        }\n        deny all;\n    }", $location($p2), $location($p3)]);
        $caretShadow = implode("\n", ["    location ^~ /storage/ {\n    }", $location($p1), $location($p2), $location($p3)]);
        $exactShadow = implode("\n", ["    location = /.env {\n        allow all;\n    }", $location($p1), $location($p2), $location($p3)]);
        $quotedDeny = implode("\n", [$location($p1, 'return 200 "deny all;";'), $location($p2), $location($p3)]);
        $pcreShadow = implode("\n", ["    location ~* \\.(?:env|log)$ {\n        allow all;\n    }", $location($p1), $location($p2), $location($p3)]);
        $exactAfter = implode("\n", [$location($p1), $location($p2), $location($p3), "    location = /.env {\n        allow all;\n    }"]);
        $caretAfter = implode("\n", [$location($p1), $location($p2), $location($p3), "    location ^~ /storage/ {\n    }"]);
        $nestedAllowAfter = implode("\n", ["    {$p1} {\n        deny all;\n        limit_except POST {\n            allow all;\n        }\n    }", $location($p2), $location($p3)]);
        $lookaheadShadow = implode("\n", ["    location ~* \\.(?=(?:env|log)$) {\n        allow all;\n    }", $location($p1), $location($p2), $location($p3)]);
        $escapedParenShadow = implode("\n", ["    location ~* \\.(?=(?:env\\)|env)$) {\n        allow all;\n    }", $location($p1), $location($p2), $location($p3)]);
        $inlineModifier = implode("\n", ["    location ~ (?i)\\.ENV$ {\n        allow all;\n    }", $location($p1), $location($p2), $location($p3)]);
        $backrefShadow = implode("\n", ["    location ~* (?<empty>)\\k<empty>\\.env$ {\n        allow all;\n    }", $location($p1), $location($p2), $location($p3)]);
        $inClassWord = implode("\n", ["    location ~* ^/files/[\\w]+\\.env$ {\n        allow all;\n    }", $location($p1), $location($p2), $location($p3)]);
        $posixClassWord = implode("\n", ["    location ~* ^/files/[[:alpha:]\\w]+\\.env$ {\n        allow all;\n    }", $location($p1), $location($p2), $location($p3)]);
        $quotedBrace = implode("\n", ["    location /x {\n        return 200 \"}\";\n    }", $location($p1), $location($p2), $location($p3)]);
        $braceNextLine = implode("\n", ["    {$p1}\n    {\n        deny all;\n    }", "    {$p2}\n    {\n        deny all;\n    }", "    {$p3}\n    {\n        deny all;\n    }"]);
        $splitDeny = implode("\n", [$location($p1), "    {$p2} {\n        deny\n        all;\n    }", $location($p3)]);
        $phpShadow = implode("\n", ["    location ~ \\.php$ {\n        allow all;\n    }", $location($p1), $location($p2), $location($p3)]);
        $envLocalShadow = implode("\n", ["    location ~* \\.env\\.local$ {\n        allow all;\n    }", $location($p1), $location($p2), $location($p3)]);
        $envProdShadow = implode("\n", ["    location ~* \\.env\\.production$ {\n        allow all;\n    }", $location($p1), $location($p2), $location($p3)]);
        $uploadsSqlShadow = implode("\n", ["    location ~ ^/uploads/.*\\.sql$ {\n        allow all;\n    }", $location($p1), $location($p2), $location($p3)]);
        $upperEnvShadow = implode("\n", ["    location ~ \\.ENV$ {\n        allow all;\n    }", $location($p1), $location($p2), $location($p3)]);
        $storageCaseShadow = implode("\n", ["    location ~ ^/Storage/Logs/ {\n        allow all;\n    }", $location($p1), $location($p2), $location($p3)]);
        $upperIniShadow = implode("\n", ["    location ~ \\.INI$ {\n        allow all;\n    }", $location($p1), $location($p2), $location($p3)]);
        $upperEnvLocalShadow = implode("\n", ["    location ~ \\.ENV\\.LOCAL$ {\n        allow all;\n    }", $location($p1), $location($p2), $location($p3)]);
        $uploadsIniShadow = implode("\n", ["    location ~ ^/uploads/.*\\.ini$ {\n        allow all;\n    }", $location($p1), $location($p2), $location($p3)]);
        $privateBakShadow = implode("\n", ["    location ~ ^/private/.*\\.bak$ {\n        allow all;\n    }", $location($p1), $location($p2), $location($p3)]);
        $groupedEscapedShadow = implode("\n", ["    location ~ ^(/private/\\.env\\.bak)$ {\n        allow all;\n    }", $location($p1), $location($p2), $location($p3)]);
        $groupedReadme = implode("\n", ["    location ~ ^(/private/readme)$ {\n        allow all;\n    }", $location($p1), $location($p2), $location($p3)]);
        $quotedEnvShadow = implode("\n", ["    location ~* \"\\.env$\" {\n        allow all;\n    }", $location($p1), $location($p2), $location($p3)]);
        $exactBackupSql = implode("\n", ["    location = /uploads/backup.sql {\n        allow all;\n    }", $location($p1), $location($p2), $location($p3)]);
        $exactNoDot = implode("\n", ["    location = /uploads/readme {\n        allow all;\n    }", $location($p1), $location($p2), $location($p3)]);
        $caretPrivate = implode("\n", ["    location ^~ /private/ {\n        allow all;\n    }", $location($p1), $location($p2), $location($p3)]);
        $exactWkGit = implode("\n", ["    location = /.well-known/.git/config {\n        allow all;\n    }", $location($p1), $location($p2), $location($p3)]);
        $caretWkGit = implode("\n", ["    location ^~ /.well-known/.git/ {\n        allow all;\n    }", $location($p1), $location($p2), $location($p3)]);
        $exactAcme = implode("\n", ["    location = /.well-known/acme.txt {\n        allow all;\n    }", $location($p1), $location($p2), $location($p3)]);
        $inClassNegated = implode("\n", ["    location ~* ^/files/[\\D]+\\.env$ {\n        allow all;\n    }", $location($p1), $location($p2), $location($p3)]);
        $numericBackref = implode("\n", ["    location ~* \\.(env|log)\\1$ {\n        allow all;\n    }", $location($p1), $location($p2), $location($p3)]);
        $wordClass = implode("\n", ["    location ~* ^/files/\\w+\\.env$ {\n        allow all;\n    }", $location($p1), $location($p2), $location($p3)]);
        $scopedModifier = implode("\n", ["    location ~ \\.(?i:ENV|LOG)$ {\n        allow all;\n    }", $location($p1), $location($p2), $location($p3)]);
        $classParens = implode("\n", ["    location ~* \\.((env)|[(]log[)])$ {\n        allow all;\n    }", $location($p1), $location($p2), $location($p3)]);
        $server = static fn (string $name, string $inner = ''): string => "server {\n    listen 443 ssl;\n    server_name {$name};\n{$inner}\n}";
        $noSpaceSplit = "server{\n    listen 443 ssl;\n    server_name lfamilia.com;\n{$complete}\n}\n".$server('www.lfamilia.com');

        $cases = [
            'complete vhost stays silent' => [$server('lfamilia.com', $complete), 0],
            'bare vhost warns for every rule' => [$server('lfamilia.com'), 3],
            'complete block does not cover a bare one' => [$server('lfamilia.com', $complete)."\n".$server('www.lfamilia.com'), 3],
            'impostor host does not count' => [$server('other-lfamilia.com', $complete), 1],
            'overridden deny warns' => [$server('lfamilia.com', $overridden), 1],
            'space before the semicolon still overrides' => [$server('lfamilia.com', $spacedTerminator), 1],
            'spaced deny terminators stay silent' => [$server('lfamilia.com', $spacedDeny), 0],
            'CIDR allow overrides the deny' => [$server('lfamilia.com', $cidrOverride), 1],
            'deny nested in limit_except does not protect' => [$server('lfamilia.com', $nestedDeny), 1],
            'earlier overlapping regex location shadows two rules' => [$server('lfamilia.com', $shadowed), 2],
            'allow nested in limit_except before the deny warns' => [$server('lfamilia.com', $nestedAllow), 1],
            'earlier caret prefix location shadows all three rules' => [$server('lfamilia.com', $caretShadow), 3],
            'earlier exact-match location shadows both rules for that file' => [$server('lfamilia.com', $exactShadow), 2],
            'quoted deny string earns no credit' => [$server('lfamilia.com', $quotedDeny), 1],
            'PCRE group location shadows all three rules' => [$server('lfamilia.com', $pcreShadow), 3],
            'exact match after the rules still shadows both rules for that file' => [$server('lfamilia.com', $exactAfter), 2],
            'caret prefix after the rules still shadows all three rules' => [$server('lfamilia.com', $caretAfter), 3],
            'allow nested after the deny warns' => [$server('lfamilia.com', $nestedAllowAfter), 1],
            'lookahead group location shadows all three rules' => [$server('lfamilia.com', $lookaheadShadow), 3],
            'escaped paren inside the lookahead still shadows all three rules' => [$server('lfamilia.com', $escapedParenShadow), 3],
            'inline modifier makes the earlier allow location case-insensitive' => [$server('lfamilia.com', $inlineModifier), 2],
            'named backreference counts as overlapping' => [$server('lfamilia.com', $backrefShadow), 3],
            'word shorthand inside a character class is translated as class content' => [$server('lfamilia.com', $inClassWord), 1],
            'posix class does not end the character class early' => [$server('lfamilia.com', $posixClassWord), 1],
            'quoted brace in an earlier location does not break parsing' => [$server('lfamilia.com', $quotedBrace), 0],
            'opening brace on the next line still counts' => [$server('lfamilia.com', $braceNextLine), 0],
            'deny split across lines still counts' => [$server('lfamilia.com', $splitDeny), 0],
            'earlier php regex shadows the cache rule' => [$server('lfamilia.com', $phpShadow), 1],
            'earlier env.local regex shadows the dotfile rule' => [$server('lfamilia.com', $envLocalShadow), 1],
            'earlier env.production regex shadows the dotfile rule' => [$server('lfamilia.com', $envProdShadow), 1],
            'uploads-scoped sql regex shadows the extension rule' => [$server('lfamilia.com', $uploadsSqlShadow), 1],
            'uppercase env regex shadows the dotfile and extension rules' => [$server('lfamilia.com', $upperEnvShadow), 2],
            'capitalized storage prefix shadows all three rules' => [$server('lfamilia.com', $storageCaseShadow), 3],
            'uppercase ini regex shadows the extension rule' => [$server('lfamilia.com', $upperIniShadow), 1],
            'uppercase env.local regex shadows the dotfile rule' => [$server('lfamilia.com', $upperEnvLocalShadow), 1],
            'uploads-scoped ini regex shadows the extension rule' => [$server('lfamilia.com', $uploadsIniShadow), 1],
            'private-prefixed bak regex shadows the extension rule via derived probes' => [$server('lfamilia.com', $privateBakShadow), 1],
            'grouped escaped literal shadows the dotfile and extension rules' => [$server('lfamilia.com', $groupedEscapedShadow), 2],
            'grouped literal matching nothing protected stays clean' => [$server('lfamilia.com', $groupedReadme), 0],
            'quoted env regex shadows the dotfile and extension rules' => [$server('lfamilia.com', $quotedEnvShadow), 2],
            'exact backup.sql shadows the extension rule' => [$server('lfamilia.com', $exactBackupSql), 1],
            'exact dotless uri shadows nothing' => [$server('lfamilia.com', $exactNoDot), 0],
            'caret prefix on an unsampled directory shadows the file rules' => [$server('lfamilia.com', $caretPrivate), 2],
            'exact uri under well-known still shadows the dotfile rule via its git component' => [$server('lfamilia.com', $exactWkGit), 1],
            'caret prefix under well-known still shadows the file rules via its git component' => [$server('lfamilia.com', $caretWkGit), 2],
            'acme challenge file under well-known shadows nothing' => [$server('lfamilia.com', $exactAcme), 0],
            'negated shorthand inside a character class counts as overlapping' => [$server('lfamilia.com', $inClassNegated), 3],
            'numeric backreference counts as overlapping' => [$server('lfamilia.com', $numericBackref), 3],
            'word-class escape is translated for matching' => [$server('lfamilia.com', $wordClass), 1],
            'scoped modifier group shadows all three rules' => [$server('lfamilia.com', $scopedModifier), 3],
            'parentheses inside character classes stay literal' => [$server('lfamilia.com', $classParens), 2],
            'server keyword without a space still splits blocks' => [$noSpaceSplit, 3],
        ];

        foreach ($cases as $label => [$body, $expected]) {
            $dir = $this->makePreflightFixtureDir(['dump.txt' => "events { worker_connections 1024; }\nhttp {\n{$body}\n}\n"]);
            file_put_contents("{$dir}/run.sh", "nginx() { cat {$dir}/dump.txt; }\n{$block}");
            $this->runBashScript("{$dir}/run.sh", "{$dir}/err.txt");
            $warnings = substr_count((string) file_get_contents("{$dir}/err.txt"), 'WARNING');
            $this->assertSame($expected, $warnings, "Preflight nginx check: {$label}.");
        }
    }

    private function extractPreflightBlock(string $haystack, string $startMarker, string $endMarker): string
    {
        $start = strpos($haystack, $startMarker);
        $this->assertNotFalse($start, "Preflight block start not found: {$startMarker}");
        $end = strpos($haystack, $endMarker, $start);
        $this->assertNotFalse($end, "Preflight block end not found: {$endMarker}");

        return substr($haystack, $start, $end - $start + strlen($endMarker));
    }

    private function makePreflightFixtureDir(array $files): string
    {
        $dir = sys_get_temp_dir().'/m12-preflight-'.getmypid().'-'.uniqid();
        mkdir($dir, 0777, true);
        foreach ($files as $name => $contents) {
            file_put_contents("{$dir}/{$name}", $contents);
        }

        return $dir;
    }

    private function runBashScript(string $scriptPath, ?string $stderrPath = null): int
    {
        $redirect = $stderrPath === null ? '' : ' 2>'.escapeshellarg($stderrPath);
        $output = [];
        $code = 0;
        exec('bash '.escapeshellarg($scriptPath).$redirect, $output, $code);

        return $code;
    }
}
