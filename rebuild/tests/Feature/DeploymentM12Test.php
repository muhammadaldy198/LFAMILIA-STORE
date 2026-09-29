<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
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

    public function test_m12_production_templates_keep_secrets_out_of_git(): void
    {
        $production = file_get_contents(base_path('deploy/production.env.example'));
        $ops = file_get_contents(base_path('deploy/ops.env.example'));

        $this->assertStringContainsString("APP_ENV=production\n", $production);
        $this->assertStringContainsString("APP_DEBUG=false\n", $production);
        $this->assertStringContainsString("SESSION_SECURE_COOKIE=true\n", $production);
        $this->assertStringContainsString("TRUSTED_PROXIES=*\n", $production);
        $this->assertStringContainsString("DB_PASSWORD=\n", $production);
        $this->assertStringContainsString("APP_KEY=\n", $production);

        $this->assertStringContainsString('MYSQL_DEFAULTS_FILE=', $ops);
        $this->assertStringContainsString('BACKUP_PASSPHRASE_FILE=', $ops);
        $this->assertStringContainsString('BACKUP_REQUIRE_REMOTE=true', $ops);
        $this->assertStringNotContainsString('password=', strtolower($ops));
    }
}
