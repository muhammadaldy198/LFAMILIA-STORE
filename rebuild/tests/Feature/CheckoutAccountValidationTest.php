<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\IntegrationCredential;
use App\Models\NicknameGameCode;
use App\Models\Product;
use App\Services\AccountValidationConfig;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CheckoutAccountValidationTest extends TestCase
{
    use DatabaseTransactions;

    private function product(array $overrides = []): Product
    {
        $category = Category::where('slug', 'game')->firstOrFail();

        if (($overrides['nickname_check_enabled'] ?? false) === true && ! empty($overrides['nickname_game_code'])) {
            NicknameGameCode::where('code', $overrides['nickname_game_code'])
                ->update(['supports_nickname_check' => true]);
        }

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Account Validation '.bin2hex(random_bytes(3)),
            'slug' => 'account-validation-'.bin2hex(random_bytes(4)),
            'margin_percent' => 10,
            'fulfillment_mode' => 'AUTO_PROVIDER',
            'is_active' => true,
            ...$overrides,
        ]);

        $product->fields()->create([
            'field_key' => 'user_id',
            'label' => 'User ID',
            'type' => 'text',
            'is_required' => true,
            'sort_order' => 0,
        ]);
        $product->fields()->create([
            'field_key' => 'zone_id',
            'label' => 'Zone ID',
            'type' => 'text',
            'is_required' => false,
            'sort_order' => 1,
        ]);

        return $product;
    }

    public function test_testing_dns_fallback_is_restricted_to_explicit_fixture_host(): void
    {
        IntegrationCredential::create([
            'code' => 'kokinpay',
            'config_ciphertext' => [
                'api_key' => 'test-secret',
                'base_url' => 'https://no-dns-record.invalid',
                'nickname_path' => '/nickname',
                'region_path' => '/region',
                'pln_path' => '/pln',
            ],
            'is_active' => true,
        ]);

        $this->assertNull(app(AccountValidationConfig::class)->active());
    }

    public function test_account_validation_rejects_missing_required_field(): void
    {
        $product = $this->product();

        $this->postJson('/checkout/nickname', [
            'product_id' => $product->id,
            'customer_input' => ['user_id' => '', 'zone_id' => ''],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['customer_input.user_id']);
    }

    public function test_account_validation_rejects_unknown_field(): void
    {
        $product = $this->product();

        $this->postJson('/checkout/nickname', [
            'product_id' => $product->id,
            'customer_input' => ['user_id' => '123456', 'unknown' => 'forged'],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['customer_input.unknown']);
    }

    public function test_mobile_legends_requires_server_zone_before_upstream_check(): void
    {
        $product = $this->product([
            'nickname_check_enabled' => true,
            'nickname_game_code' => 'mobile-legends',
            'nickname_user_field_key' => 'user_id',
            'nickname_server_field_key' => 'zone_id',
        ]);

        $this->postJson('/checkout/nickname', [
            'product_id' => $product->id,
            'customer_input' => ['user_id' => '123456', 'zone_id' => ''],
        ])->assertOk()
            ->assertJsonPath('verified', false);
    }

    public function test_verified_account_response_is_private_and_provider_agnostic(): void
    {
        $product = $this->product([
            'nickname_check_enabled' => true,
            'nickname_game_code' => 'free-fire',
            'nickname_user_field_key' => 'user_id',
        ]);
        config()->set('lfamilia.h2h.base_url', 'https://h2h.example.test');
        config()->set('lfamilia.h2h.api_key', 'test-key');

        Http::fake([
            'https://h2h.example.test/api/v1/check' => Http::response([
                'status' => 'VALID',
                'nickname' => 'AldayPlayer',
                'region' => 'ID',
            ]),
        ]);

        $response = $this->postJson('/checkout/nickname', [
            'product_id' => $product->id,
            'customer_input' => ['user_id' => '123456', 'zone_id' => ''],
        ])->assertOk()
            ->assertJsonPath('supported', true)
            ->assertJsonPath('verified', true)
            ->assertJsonPath('nickname', 'AldayPlayer')
            ->assertJsonPath('country', 'ID');

        $this->assertStringContainsString('no-store', strtolower((string) $response->headers->get('Cache-Control')));
        $this->assertStringNotContainsString('test-key', $response->getContent());
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://h2h.example.test/api/v1/check');
    }

    public function test_verified_invalid_account_is_reported_on_the_user_id_field(): void
    {
        $product = $this->product([
            'nickname_check_enabled' => true,
            'nickname_game_code' => 'free-fire',
            'nickname_user_field_key' => 'user_id',
        ]);

        IntegrationCredential::create([
            'code' => 'kokinpay',
            'config_ciphertext' => [
                'api_key' => 'test-secret',
                'base_url' => 'https://example.com',
                'nickname_path' => '/v1/check-nickname',
                'region_path' => '/v1/check-region',
                'pln_path' => '/v1/check-pln',
            ],
            'is_active' => true,
        ]);

        Http::fake([
            'https://example.com/*' => Http::response(['status' => false], 400),
        ]);

        $this->postJson('/checkout/nickname', [
            'product_id' => $product->id,
            'customer_input' => ['user_id' => 'invalid-id', 'zone_id' => ''],
        ])->assertOk()
            ->assertJsonPath('verified', false);
    }

    public function test_server_requirement_is_read_from_editable_game_code_record(): void
    {
        NicknameGameCode::where('code', 'free-fire')->update(['requires_server' => true]);

        $product = $this->product([
            'nickname_check_enabled' => true,
            'nickname_game_code' => 'free-fire',
            'nickname_user_field_key' => 'user_id',
            'nickname_server_field_key' => 'zone_id',
        ]);

        $this->postJson('/checkout/nickname', [
            'product_id' => $product->id,
            'customer_input' => ['user_id' => '123456', 'zone_id' => ''],
        ])->assertOk()
            ->assertJsonPath('verified', false);
    }

    public function test_inactive_game_code_disables_upstream_validation_without_code_change(): void
    {
        NicknameGameCode::where('code', 'free-fire')->update(['is_active' => false]);

        $product = $this->product([
            'nickname_check_enabled' => true,
            'nickname_game_code' => 'free-fire',
            'nickname_user_field_key' => 'user_id',
        ]);

        Http::fake();

        $this->postJson('/checkout/nickname', [
            'product_id' => $product->id,
            'customer_input' => ['user_id' => '123456', 'zone_id' => ''],
        ])->assertOk()
            ->assertJsonPath('supported', false)
            ->assertJsonPath('verified', false);

        Http::assertNothingSent();
    }

    public function test_validation_no_longer_calls_kokinpay_even_when_configured(): void
    {
        $product = $this->product([
            'nickname_check_enabled' => true,
            'nickname_game_code' => 'free-fire',
            'nickname_user_field_key' => 'user_id',
        ]);

        IntegrationCredential::create([
            'code' => 'kokinpay',
            'config_ciphertext' => [
                'api_key' => 'test-secret',
                'base_url' => 'https://example.com',
                'nickname_path' => '/custom/nickname',
                'region_path' => '/custom/region',
                'pln_path' => '/custom/pln',
            ],
            'is_active' => true,
        ]);

        config()->set('lfamilia.h2h.base_url', 'https://h2h.example.test');
        config()->set('lfamilia.h2h.api_key', 'test-key');
        Http::fake([
            'https://h2h.example.test/*' => Http::response([
                'status' => 'VALID', 'nickname' => 'H2hPlayer', 'region' => 'ID',
            ]),
            'https://example.com/*' => Http::response(['status' => true, 'data' => ['nickname' => 'LegacyName']]),
        ]);

        $this->postJson('/checkout/nickname', [
            'product_id' => $product->id,
            'customer_input' => ['user_id' => '123456', 'zone_id' => ''],
        ])->assertOk()
            ->assertJsonPath('nickname', 'H2hPlayer');

        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'example.com'));
    }

    public function test_supported_game_with_empty_nickname_is_rejected(): void
    {
        $product = $this->product([
            'nickname_check_enabled' => true,
            'nickname_game_code' => 'free-fire',
            'nickname_user_field_key' => 'user_id',
        ]);

        IntegrationCredential::create([
            'code' => 'kokinpay',
            'config_ciphertext' => [
                'api_key' => 'test-secret',
                'base_url' => 'https://example.com',
                'nickname_path' => '/v1/check-nickname',
                'region_path' => '/v1/check-region',
                'pln_path' => '/v1/check-pln',
            ],
            'is_active' => true,
        ]);

        Http::fake([
            'https://example.com/*' => Http::response([
                'status' => true,
                'data' => [],
            ]),
        ]);

        $this->postJson('/checkout/nickname', [
            'product_id' => $product->id,
            'customer_input' => ['user_id' => '123456', 'zone_id' => ''],
        ])->assertOk()
            ->assertJsonPath('verified', false);
    }

    public function test_game_without_nickname_support_skips_upstream_lookup(): void
    {
        NicknameGameCode::where('code', 'free-fire')->update([
            'supports_nickname_check' => false,
            'requires_server' => false,
            'requires_region_check' => false,
            'is_active' => true,
        ]);

        $product = $this->product([
            'nickname_check_enabled' => true,
            'nickname_game_code' => 'free-fire',
            'nickname_user_field_key' => 'user_id',
        ]);

        NicknameGameCode::where('code', 'free-fire')->update([
            'supports_nickname_check' => false,
        ]);

        Http::fake();

        $this->postJson('/checkout/nickname', [
            'product_id' => $product->id,
            'customer_input' => ['user_id' => '123456', 'zone_id' => ''],
        ])->assertOk()
            ->assertJsonPath('supported', false)
            ->assertJsonPath('verified', false)
            ->assertJsonPath('nickname', null);

        Http::assertNothingSent();
    }
}
