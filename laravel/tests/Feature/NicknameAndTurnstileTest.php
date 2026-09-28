<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NicknameAndTurnstileTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh');
    }

    public function test_turnstile_is_optional_when_both_keys_are_unconfigured(): void
    {
        $this->getJson('/api/security/turnstile')
            ->assertOk()
            ->assertJson([
                'enabled' => false,
                'siteKey' => null,
            ]);
    }

    public function test_partial_turnstile_configuration_fails_closed(): void
    {
        $this->saveIntegrationProfile('turnstile', 'service', 'global', ['siteKey' => 'site-key']);

        $this->getJson('/api/security/turnstile')
            ->assertStatus(503);
    }

    public function test_nickname_returns_unsupported_when_product_has_no_game_code(): void
    {
        DB::table('products')->insert([
            'slug' => 'manual-voucher',
            'name' => 'Voucher',
            'publisher' => '',
            'category' => 'voucher',
            'initials' => 'V',
            'accent' => 'lime',
            'input_label' => 'ID',
            'input_placeholder' => 'ID',
            'needs_server' => 0,
            'popular' => 0,
            'instant' => 0,
            'fulfillment_type' => 'manual',
            'target_template' => '{{destination}}',
            'manual_timezone' => 'Asia/Jakarta',
            'package_tabs_enabled' => 0,
            'package_tabs_json' => '[]',
            'is_active' => 1,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/nickname', [
            'game' => 'manual-voucher',
            'userId' => '123456',
        ])->assertOk()->assertJson([
            'supported' => false,
            'nickname' => null,
            'country' => null,
        ]);
    }

    public function test_kokinpay_nickname_uses_runtime_configuration_without_hardcoded_secret(): void
    {
        $this->saveIntegrationProfile('kokinpay', 'service', 'global', [
            'apiKey' => 'test-api-key',
            'baseUrl' => 'https://kokinpay.invalid',
        ]);

        DB::table('products')->insert([
            'slug' => 'free-fire',
            'name' => 'Free Fire',
            'publisher' => '',
            'category' => 'game',
            'initials' => 'FF',
            'accent' => 'lime',
            'input_label' => 'ID',
            'input_placeholder' => 'ID',
            'nickname_game_code' => 'free-fire',
            'needs_server' => 0,
            'popular' => 1,
            'instant' => 1,
            'fulfillment_type' => 'automatic',
            'target_template' => '{{destination}}',
            'manual_timezone' => 'Asia/Jakarta',
            'package_tabs_enabled' => 0,
            'package_tabs_json' => '[]',
            'is_active' => 1,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Http::fake([
            'https://kokinpay.invalid/v1/check-nickname' => Http::response([
                'status' => true,
                'data' => ['nickname' => 'Player One', 'country' => 'ID'],
            ], 200),
        ]);

        $this->postJson('/api/nickname', [
            'game' => 'free-fire',
            'userId' => '12345678',
        ])->assertOk()->assertJson([
            'supported' => true,
            'nickname' => 'Player One',
            'country' => 'ID',
        ]);

        Http::assertSent(fn ($request) =>
            $request->url() === 'https://kokinpay.invalid/v1/check-nickname'
            && $request['api_key'] === 'test-api-key'
        );
    }
}
