<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\NicknameGameCode;
use App\Models\Product;
use App\Models\ProductPackage;
use App\Models\ProviderMapping;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CheckoutH2hValidationTest extends TestCase
{
    use DatabaseTransactions;

    private string $key = 'h2h_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'lfamilia.h2h.base_url' => 'http://127.0.0.1:8090',
            'lfamilia.h2h.api_key' => $this->key,
            'lfamilia.h2h.timeout' => 6,
        ]);
    }

    private function product(): Product
    {
        NicknameGameCode::where('code', 'mobile-legends')
            ->update(['supports_nickname_check' => true]);

        $product = Product::create([
            'category_id' => Category::where('slug', 'game')->firstOrFail()->id,
            'name' => 'H2H Validation '.bin2hex(random_bytes(3)),
            'slug' => 'h2h-validation-'.bin2hex(random_bytes(4)),
            'margin_percent' => 10,
            'fulfillment_mode' => 'AUTO_PROVIDER',
            'is_active' => true,
            'nickname_check_enabled' => true,
            'nickname_game_code' => 'mobile-legends',
            'nickname_user_field_key' => 'user_id',
            'nickname_server_field_key' => 'zone_id',
        ]);
        $product->fields()->create(['field_key' => 'user_id', 'label' => 'User ID', 'type' => 'text', 'is_required' => true, 'sort_order' => 0]);
        $product->fields()->create(['field_key' => 'zone_id', 'label' => 'Zone ID', 'type' => 'text', 'is_required' => false, 'sort_order' => 1]);

        return $product;
    }

    private function packageWithSku(Product $product, string $name, ?string $sku): ProductPackage
    {
        $package = ProductPackage::create([
            'product_id' => $product->id,
            'code' => 'pkg-'.bin2hex(random_bytes(4)),
            'name' => $name,
            'is_active' => true,
        ]);
        if ($sku !== null) {
            ProviderMapping::create([
                'product_package_id' => $package->id,
                'provider_id' => 1,
                'external_sku' => $sku,
                'cost_idr' => 1000,
                'is_active' => true,
            ]);
        }

        return $package;
    }

    private function fakeH2h(array $response, int $status = 200): void
    {
        Http::fake(['http://127.0.0.1:8090/api/v1/check' => Http::response($response, $status)]);
    }

    private function payload(Product $product): array
    {
        return ['product_id' => $product->id, 'customer_input' => ['user_id' => '123456789', 'zone_id' => '1234']];
    }

    public function test_valid_account_returns_nickname_region_and_hides_used_first_recharge_tier(): void
    {
        $product = $this->product();
        $frUsed = $this->packageWithSku($product, '50+50 Diamond', 'MLFS100');
        $frOpen = $this->packageWithSku($product, '150+150 Diamond', 'MLFS300');
        $wdp = $this->packageWithSku($product, 'Weekly Diamond Pass', 'MLWDP1');

        $this->fakeH2h([
            'status' => 'VALID',
            'game' => 'mobile-legends',
            'account' => ['id' => '123456789', 'zone' => '1234', 'nickname' => 'PemainSatu', 'region' => 'id'],
            'eligibility' => [
                'first_purchase' => [
                    '50' => ['eligible' => false, 'status' => 'used'],
                    '150' => ['eligible' => true, 'status' => 'available'],
                ],
                'first_purchase_eligible' => true,
                'wdp_limit_remaining' => null,
                'wdp_eligible' => true,
            ],
            'provider' => 'mobapay',
        ]);

        $response = $this->postJson('/checkout/nickname', $this->payload($product));

        $response->assertOk()
            ->assertJsonPath('verified', true)
            ->assertJsonPath('nickname', 'PemainSatu')
            ->assertJsonPath('country', 'ID')
            ->assertJsonPath('hidden_package_ids', [$frUsed->id]);

        Http::assertSent(fn ($request) => $request->url() === 'http://127.0.0.1:8090/api/v1/check'
            && $request->hasHeader('X-API-Key', $this->key)
            && $request['game'] === 'mobile-legends'
            && $request['user_id'] === '123456789'
            && $request['zone_id'] === '1234'
            && $request['expected_region'] === 'ID');
    }

    public function test_wdp_ineligible_hides_weekly_diamond_pass_packages(): void
    {
        $product = $this->product();
        $wdp = $this->packageWithSku($product, 'Weekly Diamond Pass', 'MLWDP1');
        $regular = $this->packageWithSku($product, '86 Diamond', 'ML86');

        $this->fakeH2h([
            'status' => 'VALID',
            'account' => ['nickname' => 'PemainSatu', 'region' => 'id'],
            'eligibility' => ['first_purchase' => [], 'wdp_eligible' => false],
        ]);

        $this->postJson('/checkout/nickname', $this->payload($product))
            ->assertOk()
            ->assertJsonPath('hidden_package_ids', [$wdp->id]);
    }

    public function test_not_found_degrades_to_warning(): void
    {
        $product = $this->product();
        $this->fakeH2h(['status' => 'NOT_FOUND', 'account' => ['nickname' => null]]);

        $this->postJson('/checkout/nickname', $this->payload($product))
            ->assertOk()
            ->assertJsonPath('verified', false)
            ->assertJsonPath('warning', 'Nickname tidak ditemukan. Periksa kembali data akun.');
    }

    public function test_region_mismatch_degrades_to_warning(): void
    {
        $product = $this->product();
        $this->fakeH2h(['status' => 'REGION_MISMATCH', 'account' => ['region' => 'sg']]);

        $this->postJson('/checkout/nickname', $this->payload($product))
            ->assertOk()
            ->assertJsonPath('verified', false)
            ->assertJsonPath('warning', 'Region akun tidak cocok. Periksa kembali Server / Zone ID.');
    }

    public function test_route_error_degrades_to_warning(): void
    {
        $product = $this->product();
        $this->fakeH2h(['status' => 'ROUTE_ERROR']);

        $this->postJson('/checkout/nickname', $this->payload($product))
            ->assertOk()
            ->assertJsonPath('verified', false)
            ->assertJsonPath('warning', 'Nickname tidak berhasil diverifikasi. Pastikan ID tujuan sudah benar.');
    }

    public function test_service_unreachable_degrades_to_warning(): void
    {
        $product = $this->product();
        Http::fake(fn () => throw new ConnectionException('connection refused'));

        $this->postJson('/checkout/nickname', $this->payload($product))
            ->assertOk()
            ->assertJsonPath('verified', false)
            ->assertJsonPath('warning', 'Nickname tidak berhasil diverifikasi karena layanan pengecekan sedang tidak tersedia. Pastikan ID sudah benar.');
    }

    public function test_unknown_eligibility_hides_nothing(): void
    {
        $product = $this->product();
        $this->packageWithSku($product, '50+50 Diamond', 'MLFS100');
        $this->packageWithSku($product, 'Weekly Diamond Pass', 'MLWDP1');

        $this->fakeH2h([
            'status' => 'VALID',
            'account' => ['nickname' => 'PemainSatu', 'region' => 'id'],
            'eligibility' => [
                'first_purchase' => ['50' => ['eligible' => null, 'status' => 'unknown']],
                'wdp_eligible' => null,
            ],
        ]);

        $this->postJson('/checkout/nickname', $this->payload($product))
            ->assertOk()
            ->assertJsonPath('hidden_package_ids', []);
    }

    public function test_valid_without_nickname_is_not_marked_verified(): void
    {
        $product = $this->product();
        $this->fakeH2h(['status' => 'VALID', 'account' => ['nickname' => null, 'region' => 'id']]);

        $this->postJson('/checkout/nickname', $this->payload($product))
            ->assertOk()
            ->assertJsonPath('verified', false)
            ->assertJsonPath('warning', 'Nickname tidak berhasil diverifikasi. Pastikan ID tujuan sudah benar.');
    }

    public function test_plain_http_remote_base_url_is_rejected(): void
    {
        config(['lfamilia.h2h.base_url' => 'http://203.0.113.10:8090']);
        $product = $this->product();
        Http::fake();

        $this->postJson('/checkout/nickname', $this->payload($product))
            ->assertOk()
            ->assertJsonPath('verified', false);

        Http::assertNothingSent();
    }

    public function test_h2h_not_configured_does_not_call_h2h(): void
    {
        config(['lfamilia.h2h.api_key' => '']);
        $product = $this->product();
        Http::fake();

        $this->postJson('/checkout/nickname', $this->payload($product))
            ->assertOk()
            ->assertJsonPath('verified', false);

        Http::assertNothingSent();
    }
}
