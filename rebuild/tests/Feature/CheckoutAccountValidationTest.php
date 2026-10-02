<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\IntegrationCredential;
use App\Models\NicknameGameCode;
use App\Models\Product;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CheckoutAccountValidationTest extends TestCase
{
    use DatabaseTransactions;

    private function product(array $overrides = []): Product
    {
        $category = Category::where('slug', 'game')->firstOrFail();

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
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['customer_input.zone_id']);
    }

    public function test_verified_account_response_is_private_and_provider_agnostic(): void
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
                'base_url' => 'https://nickname.example.test',
                'nickname_path' => '/v1/check-nickname',
                'region_path' => '/v1/check-region',
                'pln_path' => '/v1/check-pln',
            ],
            'is_active' => true,
        ]);

        Http::fake([
            'https://nickname.example.test/*' => Http::response([
                'status' => true,
                'data' => [
                    'nickname' => 'AldayPlayer',
                    'country' => 'ID',
                ],
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
        $body = strtolower($response->getContent());
        $this->assertStringNotContainsString('kokinpay', $body);
        $this->assertStringNotContainsString('test-secret', $body);
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
                'base_url' => 'https://nickname.example.test',
                'nickname_path' => '/v1/check-nickname',
                'region_path' => '/v1/check-region',
                'pln_path' => '/v1/check-pln',
            ],
            'is_active' => true,
        ]);

        Http::fake([
            'https://nickname.example.test/*' => Http::response(['status' => false], 400),
        ]);

        $this->postJson('/checkout/nickname', [
            'product_id' => $product->id,
            'customer_input' => ['user_id' => 'invalid-id', 'zone_id' => ''],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['customer_input.user_id']);
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
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['customer_input.zone_id']);
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

    public function test_validation_uses_editable_endpoint_paths_from_integration_config(): void
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
                'base_url' => 'https://nickname.example.test',
                'nickname_path' => '/custom/nickname',
                'region_path' => '/custom/region',
                'pln_path' => '/custom/pln',
            ],
            'is_active' => true,
        ]);

        Http::fake([
            'https://nickname.example.test/custom/nickname' => Http::response([
                'status' => true,
                'data' => ['nickname' => 'ConfigDriven'],
            ]),
        ]);

        $this->postJson('/checkout/nickname', [
            'product_id' => $product->id,
            'customer_input' => ['user_id' => '123456', 'zone_id' => ''],
        ])->assertOk()
            ->assertJsonPath('verified', true)
            ->assertJsonPath('nickname', 'ConfigDriven');

        Http::assertSent(fn ($request): bool =>
            $request->url() === 'https://nickname.example.test/custom/nickname'
        );
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
                'base_url' => 'https://nickname.example.test',
                'nickname_path' => '/v1/check-nickname',
                'region_path' => '/v1/check-region',
                'pln_path' => '/v1/check-pln',
            ],
            'is_active' => true,
        ]);

        Http::fake([
            'https://nickname.example.test/*' => Http::response([
                'status' => true,
                'data' => [],
            ]),
        ]);

        $this->postJson('/checkout/nickname', [
            'product_id' => $product->id,
            'customer_input' => ['user_id' => '123456', 'zone_id' => ''],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['customer_input.user_id']);
    }

}
