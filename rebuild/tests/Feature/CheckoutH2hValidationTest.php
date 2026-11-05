<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\NicknameGameCode;
use App\Models\Product;
use App\Models\ProductPackage;
use App\Services\RegionCheckProof;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CheckoutH2hValidationTest extends TestCase
{
    use DatabaseTransactions;

    private function game(string $code = 'mobile-legends', ?string $region = null): Product
    {
        NicknameGameCode::where('code', $code)->update(['is_active' => true, 'supports_nickname_check' => true]);
        $product = Product::create([
            'category_id' => Category::where('slug', 'game')->firstOrFail()->id,
            'name' => 'H2H '.bin2hex(random_bytes(4)),
            'slug' => 'h2h-'.bin2hex(random_bytes(5)),
            'fulfillment_mode' => 'AUTO_PROVIDER',
            'margin_percent' => 10,
            'is_active' => true,
            'nickname_check_enabled' => true,
            'nickname_game_code' => $code,
            'nickname_user_field_key' => 'user_id',
            'nickname_server_field_key' => in_array($code, ['mobile-legends', 'magic-chess-go-go'], true) ? 'zone_id' : null,
            'nickname_required_region' => $region,
        ]);
        $product->fields()->create(['field_key' => 'user_id', 'label' => 'User ID', 'type' => 'text', 'is_required' => true, 'sort_order' => 0]);
        $product->fields()->create(['field_key' => 'zone_id', 'label' => 'Zone', 'type' => 'text', 'is_required' => false, 'sort_order' => 1]);

        return $product;
    }

    private function package(Product $product, string $sku, string $name, bool $activeMapping = true): ProductPackage
    {
        $package = $product->packages()->create([
            'code' => strtoupper(bin2hex(random_bytes(5))),
            'name' => $name,
            'pricing_mode' => 'PERCENT',
            'margin_percent' => 10,
            'is_active' => true,
        ]);
        $providerId = DB::table('providers')->where('code', 'DIGIFLAZZ')->value('id');
        DB::table('providers')->where('id', $providerId)->update(['is_active' => true]);
        DB::table('provider_mappings')->insert([
            'product_package_id' => $package->id,
            'provider_id' => $providerId,
            'external_sku' => $sku,
            'cost_idr' => 10000,
            'max_price_idr' => 10000,
            'priority' => 1,
            'is_active' => $activeMapping,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        if ($activeMapping) {
            $this->seedCheckoutDigiflazzCatalog($package->id);
        }

        return $package;
    }

    private function h2h(array $result): void
    {
        config()->set('lfamilia.h2h.base_url', 'http://127.0.0.1:8090');
        config()->set('lfamilia.h2h.api_key', 'test-store-partner-key');
        Http::fake([
            'http://127.0.0.1:8090/api/v1/check' => Http::response($result),
        ]);
    }

    private function h2hSequence(array $results): void
    {
        config()->set('lfamilia.h2h.base_url', 'http://127.0.0.1:8090');
        config()->set('lfamilia.h2h.api_key', 'test-store-partner-key');
        $sequence = Http::sequence();
        foreach ($results as $result) {
            $sequence->push($result);
        }
        Http::fake(['http://127.0.0.1:8090/api/v1/check' => $sequence]);
    }

    private function nickname(Product $product, string $userId = '123456'): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/checkout/nickname', [
            'product_id' => $product->id,
            'customer_input' => ['user_id' => $userId, 'zone_id' => '9876'],
        ]);
    }

    private function order(ProductPackage $package, ?string $proof, string $userId = '123456'): array
    {
        DB::table('payment_gateways')->where('code', 'MANUAL_QRIS')->update(['is_active' => true]);
        DB::table('payment_channels')->where('code', 'manual_qris')->update(['is_active' => true]);

        return [
            'package_id' => $package->id,
            'payment_channel_code' => 'manual_qris',
            'customer_input' => ['user_id' => $userId, 'zone_id' => '9876'],
            'guest_email' => 'h2h-buyer@example.test',
            'guest_phone' => '081234567890',
            'idempotency_key' => 'h2h-test-'.bin2hex(random_bytes(12)),
            'region_proof' => $proof,
        ];
    }

    public function test_valid_returns_nickname_region_and_only_ineligible_active_mapping_packages(): void
    {
        $product = $this->game();
        $first = $this->package($product, 'MLFS100', 'First Recharge 50');
        $inactive = $this->package($product, 'MLFS300', 'First Recharge 150', false);
        $wdp = $this->package($product, 'WDP-TEST', 'Weekly Diamond Pass');
        $this->h2h([
            'status' => 'VALID', 'nickname' => 'PlayerAce', 'region' => 'id',
            'first_purchase' => [
                ['tier' => 50, 'eligible' => false],
                ['tier' => 150, 'eligible' => false],
                ['tier' => 250, 'eligible' => true],
            ],
            'wdp_eligible' => false,
        ]);

        $r = $this->nickname($product)->assertOk()
            ->assertJsonPath('verified', true)
            ->assertJsonPath('nickname', 'PlayerAce')
            ->assertJsonPath('country', 'ID');
        $ids = $r->json('disabled_package_ids');
        $this->assertEqualsCanonicalizing([$first->id, $wdp->id], $ids);
        $this->assertNotContains($inactive->id, $ids);
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request): bool => str_contains(strtolower($request->url()), 'kokinpay'));
    }

    public function test_magic_chess_first_recharge_mapping(): void
    {
        $product = $this->game('magic-chess-go-go');
        $package = $this->package($product, 'MC50_A', 'First Purchase 50');
        $this->h2h(['status' => 'VALID', 'nickname' => 'MC Player', 'region' => 'ID',
            'first_purchase' => [['tier' => 50, 'eligible' => false]]]);

        $r = $this->nickname($product)->assertOk();
        $this->assertSame([$package->id], $r->json('disabled_package_ids'));
    }

    public function test_not_found_and_region_mismatch_return_specific_warnings(): void
    {
        $p = $this->game('free-fire');
        $this->h2hSequence([['status' => 'NOT_FOUND'],
            ['status' => 'REGION_MISMATCH', 'nickname' => 'Someone', 'region' => 'SG']]);
        $this->nickname($p)->assertOk()->assertJsonPath('verified', false)
            ->assertJsonPath('warning', 'Nickname tidak ditemukan. Periksa kembali data akun.');

        $this->nickname($p)->assertOk()->assertJsonPath('status', 'REGION_MISMATCH')
            ->assertJsonPath('warning', 'Region akun tidak cocok. Periksa kembali Server / Zone ID.');
    }

    public function test_missing_configuration_fails_open_without_any_request(): void
    {
        config()->set('lfamilia.h2h.base_url', null);
        config()->set('lfamilia.h2h.api_key', null);
        Http::fake();
        $this->nickname($this->game('free-fire'))->assertOk()
            ->assertJsonPath('supported', true)->assertJsonPath('verified', false);
        Http::assertNothingSent();
    }

    public function test_http_remote_destination_is_rejected_before_request(): void
    {
        config()->set('lfamilia.h2h.base_url', 'http://remote.example.test');
        config()->set('lfamilia.h2h.api_key', 'test-key');
        Http::fake();
        $this->nickname($this->game('free-fire'))->assertOk()->assertJsonPath('verified', false);
        Http::assertNothingSent();
    }

    public function test_route_error_and_timeout_remain_advisory(): void
    {
        $product = $this->game('free-fire');
        $this->h2h(['status' => 'ROUTE_ERROR']);
        $this->nickname($product)->assertOk()->assertJsonPath('verified', false)
            ->assertJsonPath('disabled_package_ids', []);

        config()->set('lfamilia.h2h.base_url', 'https://h2h.example.test');
        Http::fake(fn () => throw new ConnectionException('Connection timed out'));
        $this->nickname($product)->assertOk()->assertJsonPath('verified', false);
        // A transport exception may abort before Laravel records the request.
    }

    public function test_valid_without_nickname_and_malformed_first_purchase_never_verify_or_disable(): void
    {
        $p = $this->game();
        $this->h2hSequence([['status' => 'VALID', 'region' => 'ID', 'first_purchase' => 'broken', 'wdp_eligible' => null],
            ['status' => 'VALID', 'nickname' => 'Correct', 'region' => 'ID', 'first_purchase' => 'broken']]);
        $this->nickname($p)->assertOk()->assertJsonPath('verified', false)
            ->assertJsonPath('disabled_package_ids', []);

        $this->nickname($p)->assertOk()->assertJsonPath('verified', true)
            ->assertJsonPath('disabled_package_ids', []);
    }

    public function test_confirmed_mismatch_blocks_real_order_even_with_forged_region(): void
    {
        $product = $this->game('mobile-legends', 'ID');
        $package = $this->package($product, 'TEST-SKU-ORDER', '100 Diamonds');
        $this->h2h(['status' => 'VALID', 'nickname' => 'SingaporeAccount', 'region' => 'SG']);

        $checked = $this->nickname($product)->assertOk()
            ->assertJsonPath('status', 'REGION_MISMATCH')
            ->assertJsonPath('verified', false);
        $proof = $checked->json('region_proof');
        $this->assertNotEmpty($proof);
        $this->postJson('/checkout/orders', $this->order($package, $proof))
            ->assertUnprocessable()->assertJsonValidationErrors(['customer_input']);
        $this->postJson('/checkout/orders', $this->order($package, 'forged-proof'))
            ->assertUnprocessable()->assertJsonValidationErrors(['region_proof']);
        $this->postJson('/checkout/orders', $this->order($package, null))
            ->assertUnprocessable()->assertJsonValidationErrors(['region_proof']);
        $this->assertSame(0, DB::table('orders')->where('product_id', $product->id)->count());
        Http::assertSentCount(1);
    }

    public function test_matching_region_proof_allows_order_without_h2h_recheck(): void
    {
        $product = $this->game('mobile-legends', 'ID');
        $package = $this->package($product, 'TEST-SKU-ID', '100 Diamonds');
        $this->h2h(['status' => 'VALID', 'nickname' => 'IndonesiaPlayer', 'region' => 'ID']);

        $checked = $this->nickname($product)->assertOk()
            ->assertJsonPath('verified', true)->assertJsonPath('region', 'ID');
        $this->postJson('/checkout/orders', $this->order($package, $checked->json('region_proof')))
            ->assertCreated();
        Http::assertSentCount(1);
    }

    public function test_unknown_region_allows_order_with_advisory_proof(): void
    {
        $product = $this->game('mobile-legends', 'ID');
        $package = $this->package($product, 'TEST-SKU-UNKNOWN', '100 Diamonds');
        $this->h2h(['status' => 'ROUTE_ERROR']);

        $checked = $this->nickname($product)->assertOk()->assertJsonPath('verified', false);
        $this->postJson('/checkout/orders', $this->order($package, $checked->json('region_proof')))
            ->assertCreated();
        Http::assertSentCount(1);
    }

    public function test_changing_user_id_invalidates_proof_and_new_matching_check_allows_order(): void
    {
        $product = $this->game('mobile-legends', 'ID');
        $package = $this->package($product, 'TEST-SKU-CHANGE', '100 Diamonds');
        $this->h2hSequence([['status' => 'REGION_MISMATCH', 'region' => 'SG'],
            ['status' => 'VALID', 'nickname' => 'NewPlayer', 'region' => 'ID']]);
        $old = $this->nickname($product, '123456')->assertOk()->json('region_proof');

        $this->postJson('/checkout/orders', $this->order($package, $old, '987654'))
            ->assertUnprocessable()->assertJsonValidationErrors(['region_proof']);
        $new = $this->nickname($product, '987654')->assertOk()->json('region_proof');
        $this->postJson('/checkout/orders', $this->order($package, $new, '987654'))->assertCreated();
    }

    public function test_three_new_game_codes_are_available_to_admin_dropdown(): void
    {
        foreach (['blood-strike', 'blood-strike-max', 'delta-force-garena'] as $code) {
            $this->assertTrue((bool) NicknameGameCode::where('code', $code)
                ->where('is_active', true)->where('supports_nickname_check', true)->exists());
        }
    }
}
