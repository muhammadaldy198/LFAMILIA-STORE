<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\NicknameGameCode;
use App\Models\Product;
use App\Models\ProductPackage;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CheckoutH2hValidationTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Create a nickname-enabled game product with optional region enforcement.
     */
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

    /**
     * Create a purchasable package and its Digiflazz provider mapping.
     */
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

    /**
     * Configure one fake H2H response for the nickname endpoint.
     */
    private function h2h(array $result): void
    {
        config()->set('lfamilia.h2h.base_url', 'http://127.0.0.1:8090');
        config()->set('lfamilia.h2h.api_key', 'test-store-partner-key');
        Http::fake([
            'http://127.0.0.1:8090/api/v1/check' => Http::response($result),
        ]);
    }

    /**
     * Queue fake H2H responses for successive nickname requests.
     */
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

    /**
     * Send a nickname-check request for the product and account input.
     */
    private function nickname(Product $product, string $userId = '123456'): TestResponse
    {
        return $this->postJson('/checkout/nickname', [
            'product_id' => $product->id,
            'customer_input' => ['user_id' => $userId, 'zone_id' => '9876'],
        ]);
    }

    /**
     * Build a checkout payload carrying the given encrypted region proof.
     */
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

    /**
     * Valid responses disable only packages with eligible-to-block active mappings.
     */
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
        Http::assertSent(fn ($request): bool => $request->data()['zone_id'] === '9876'
            && ! array_key_exists('zone', $request->data()));
        Http::assertNotSent(fn ($request): bool => str_contains(strtolower($request->url()), 'kokinpay'));
    }

    /**
     * Real H2H envelopes expose nickname and first-purchase eligibility in nested objects.
     */
    public function test_nested_valid_envelope_verifies_nickname_and_disables_four_first_purchase_tiers(): void
    {
        $product = $this->game();
        $packages = [];
        foreach ([50 => 'MLFS100', 150 => 'MLFS300', 250 => 'MLFS500', 500 => 'MLFS1000'] as $tier => $sku) {
            $packages[] = $this->package($product, $sku, 'First Recharge '.$tier);
        }
        $this->h2h([
            'status' => 'VALID',
            'game' => 'mobile-legends',
            'account' => ['id' => '123456', 'zone' => '9876', 'nickname' => 'PlayerAce', 'region' => 'id'],
            'eligibility' => [
                'first_purchase' => [
                    '50' => ['eligible' => false, 'status' => 'ineligible'],
                    '150' => ['eligible' => false, 'status' => 'ineligible'],
                    '250' => ['eligible' => false, 'status' => 'ineligible'],
                    '500' => ['eligible' => false, 'status' => 'ineligible'],
                ],
                'first_purchase_eligible' => false,
                'wdp_limit_remaining' => null,
                'wdp_eligible' => true,
                'verification' => 'partial',
            ],
            'provider' => 'mobapay',
            'message' => null,
            'request_id' => 'h2h-envelope-test',
        ]);

        $response = $this->nickname($product)->assertOk()
            ->assertJsonPath('status', 'VALID')
            ->assertJsonPath('verified', true)
            ->assertJsonPath('nickname', 'PlayerAce')
            ->assertJsonPath('region', 'ID')
            ->assertJsonPath('wdp_eligible', true)
            ->assertJsonPath('first_purchase.50.eligible', false);

        $ids = $response->json('disabled_package_ids');
        $this->assertEqualsCanonicalizing(array_map(fn ($package) => $package->id, $packages), $ids);
    }

    /**
     * Nested NOT_FOUND results preserve the specific advisory warning.
     */
    public function test_nested_not_found_envelope_remains_unverified(): void
    {
        $product = $this->game();
        $this->h2h([
            'status' => 'NOT_FOUND',
            'game' => 'mobile-legends',
            'account' => ['id' => '123456', 'zone' => '9876', 'nickname' => null, 'region' => null],
            'eligibility' => ['first_purchase' => null, 'wdp_eligible' => null],
            'provider' => 'mobapay',
            'message' => null,
            'request_id' => 'h2h-not-found-test',
        ]);

        $this->nickname($product)->assertOk()
            ->assertJsonPath('status', 'NOT_FOUND')
            ->assertJsonPath('verified', false)
            ->assertJsonPath('warning', 'Nickname tidak ditemukan. Periksa kembali data akun.')
            ->assertJsonPath('disabled_package_ids', []);
    }

    /**
     * An incomplete VALID lookup must not make a nickname or package decision.
     */
    public function test_nested_valid_envelope_without_nickname_remains_advisory(): void
    {
        $product = $this->game();
        $this->package($product, 'MLFS100', 'First Recharge 50');
        $this->package($product, 'WDP-TEST', 'Weekly Diamond Pass');
        $this->h2h([
            'status' => 'VALID',
            'game' => 'mobile-legends',
            'account' => ['id' => '123456', 'zone' => '9876', 'nickname' => null, 'region' => null],
            'eligibility' => [
                'first_purchase' => ['50' => ['eligible' => false, 'status' => 'ineligible']],
                'wdp_eligible' => false,
                'verification' => 'partial',
            ],
            'provider' => 'mobapay',
            'message' => null,
            'request_id' => 'h2h-partial-test',
        ]);

        $this->nickname($product)->assertOk()
            ->assertJsonPath('verified', false)
            ->assertJsonPath('warning', 'Nickname belum dapat diverifikasi. Pastikan ID tujuan benar.')
            ->assertJsonPath('disabled_package_ids', []);
    }

    /**
     * WDP eligibility in the real nested envelope disables the pass only.
     */
    public function test_nested_wdp_ineligible_envelope_disables_weekly_diamond_pass(): void
    {
        $product = $this->game();
        $regular = $this->package($product, 'MLFS100', '100 Diamonds');
        $wdp = $this->package($product, 'WDP-TEST', 'Weekly Diamond Pass');
        $this->h2h([
            'status' => 'VALID',
            'game' => 'mobile-legends',
            'account' => ['id' => '123456', 'zone' => '9876', 'nickname' => 'PlayerAce', 'region' => 'id'],
            'eligibility' => ['first_purchase' => [], 'wdp_eligible' => false, 'verification' => 'partial'],
            'provider' => 'mobapay',
            'message' => null,
            'request_id' => 'h2h-wdp-test',
        ]);

        $response = $this->nickname($product)->assertOk()
            ->assertJsonPath('verified', true)
            ->assertJsonPath('wdp_eligible', false);
        $this->assertSame([$wdp->id], $response->json('disabled_package_ids'));
        $this->assertNotContains($regular->id, $response->json('disabled_package_ids'));
    }

    /**
     * Legacy flat and data-wrapped payloads still work, with top-level status authoritative.
     */
    public function test_legacy_flat_and_data_wrapped_h2h_envelopes_remain_supported(): void
    {
        $product = $this->game();
        $this->h2hSequence([
            ['status' => 'VALID', 'data' => ['status' => 'NOT_FOUND', 'nickname' => 'WrappedPlayer', 'region' => 'id']],
            ['status' => 'VALID', 'nickname' => 'FlatPlayer', 'region' => 'id'],
        ]);

        $this->nickname($product)->assertOk()
            ->assertJsonPath('status', 'VALID')
            ->assertJsonPath('verified', true)
            ->assertJsonPath('nickname', 'WrappedPlayer');
        $this->nickname($product)->assertOk()
            ->assertJsonPath('status', 'VALID')
            ->assertJsonPath('verified', true)
            ->assertJsonPath('nickname', 'FlatPlayer');
    }

    /**
     * Magic Chess first-purchase restrictions map to its own provider SKUs.
     */
    public function test_magic_chess_first_recharge_mapping(): void
    {
        $product = $this->game('magic-chess-go-go');
        $package = $this->package($product, 'MC50_A', 'First Purchase 50');
        $this->h2h(['status' => 'VALID', 'nickname' => 'MC Player', 'region' => 'ID',
            'first_purchase' => [['tier' => 50, 'eligible' => false]]]);

        $r = $this->nickname($product)->assertOk();
        $this->assertSame([$package->id], $r->json('disabled_package_ids'));
        Http::assertSent(fn ($request): bool => $request->data()['zone_id'] === '9876'
            && ! array_key_exists('zone', $request->data()));
    }

    /**
     * Verify the outbound H2H payload contract for games with and without a zone.
     */
    public function test_h2h_zone_id_is_sent_only_for_games_with_a_zone(): void
    {
        $mobileLegends = $this->game('mobile-legends');
        $magicChess = $this->game('magic-chess-go-go');
        $freeFire = $this->game('free-fire');
        $this->h2h(['status' => 'NOT_FOUND']);

        foreach ([$mobileLegends, $magicChess, $freeFire] as $product) {
            $this->nickname($product)->assertOk()->assertJsonPath('status', 'NOT_FOUND');
        }

        Http::assertSentCount(3);
        foreach (['mobile-legends', 'magic-chess-go-go'] as $code) {
            Http::assertSent(fn ($request): bool => $request->url() === 'http://127.0.0.1:8090/api/v1/check'
                && ($request->data()['game'] ?? null) === $code
                && ($request->data()['user_id'] ?? null) === '123456'
                && ($request->data()['zone_id'] ?? null) === '9876'
                && ! array_key_exists('zone', $request->data()));
        }
        Http::assertSent(fn ($request): bool => ($request->data()['game'] ?? null) === 'free-fire'
            && ($request->data()['user_id'] ?? null) === '123456'
            && ! array_key_exists('zone_id', $request->data())
            && ! array_key_exists('zone', $request->data()));
    }

    /**
     * Missing accounts and mismatched regions produce distinct advisory responses.
     */
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

    /**
     * Missing H2H configuration does not block customers or issue network requests.
     */
    public function test_missing_configuration_fails_open_without_any_request(): void
    {
        config()->set('lfamilia.h2h.base_url', null);
        config()->set('lfamilia.h2h.api_key', null);
        Http::fake();
        $this->nickname($this->game('free-fire'))->assertOk()
            ->assertJsonPath('supported', true)->assertJsonPath('verified', false);
        Http::assertNothingSent();
    }

    /**
     * Non-loopback HTTP endpoints are rejected before a network request is made.
     */
    public function test_http_remote_destination_is_rejected_before_request(): void
    {
        config()->set('lfamilia.h2h.base_url', 'http://remote.example.test');
        config()->set('lfamilia.h2h.api_key', 'test-key');
        Http::fake();
        $this->nickname($this->game('free-fire'))->assertOk()->assertJsonPath('verified', false);
        Http::assertNothingSent();
    }

    /**
     * H2H route failures and timeouts remain advisory rather than blocking checkout.
     */
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

    /**
     * Incomplete provider data cannot verify a nickname or disable packages.
     */
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

    /**
     * A confirmed region mismatch cannot be bypassed with a forged checkout proof.
     */
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

    /**
     * A matching encrypted proof permits checkout without another H2H request.
     */
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

    /**
     * Unknown region status preserves fail-open behavior with a valid proof.
     */
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

    /**
     * Changing account input invalidates its earlier proof until rechecked.
     */
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

    /**
     * The additional game codes are seeded as active nickname-check options.
     */
    public function test_three_new_game_codes_are_available_to_admin_dropdown(): void
    {
        foreach (['blood-strike', 'blood-strike-max', 'delta-force-garena'] as $code) {
            $this->assertTrue((bool) NicknameGameCode::where('code', $code)
                ->where('is_active', true)->where('supports_nickname_check', true)->exists());
        }
    }

    /**
     * NV-07: an H2H first-purchase verdict now issues a checkout proof even
     * when the product has no required region, so eligibility can be bound.
     */
    public function test_valid_eligibility_verdict_issues_proof_without_required_region(): void
    {
        $product = $this->game();
        $this->package($product, 'MLFS100', 'First Recharge 50');
        $this->h2h([
            'status' => 'VALID', 'nickname' => 'PlayerAce', 'region' => 'id',
            'first_purchase' => [['tier' => 50, 'eligible' => false]],
            'wdp_eligible' => true,
        ]);

        $this->nickname($product)->assertOk()
            ->assertJsonPath('region_proof', fn ($proof) => is_string($proof) && $proof !== '');
    }

    /**
     * NV-07: a first-purchase package H2H marked ineligible is rejected at
     * order creation, both with the genuine proof and with no proof at all.
     */
    public function test_disabled_first_purchase_package_is_rejected_with_and_without_proof(): void
    {
        $product = $this->game();
        $first = $this->package($product, 'MLFS100', 'First Recharge 50');
        $this->h2h([
            'status' => 'VALID', 'nickname' => 'PlayerAce', 'region' => 'id',
            'first_purchase' => [['tier' => 50, 'eligible' => false]],
            'wdp_eligible' => true,
        ]);
        $proof = $this->nickname($product)->assertOk()->json('region_proof');

        $this->postJson('/checkout/orders', $this->order($first, $proof))
            ->assertUnprocessable()->assertJsonValidationErrors(['package_id']);
        $this->postJson('/checkout/orders', $this->order($first, null))
            ->assertUnprocessable()->assertJsonValidationErrors(['package_id']);
        $this->assertDatabaseCount('orders', 0);
    }

    /**
     * NV-07: the same first-purchase package stays orderable when H2H
     * reports the account eligible and the order carries the proof.
     */
    public function test_eligible_first_purchase_package_can_be_ordered_with_proof(): void
    {
        $product = $this->game();
        $first = $this->package($product, 'MLFS100', 'First Recharge 50');
        $this->h2h([
            'status' => 'VALID', 'nickname' => 'PlayerAce', 'region' => 'id',
            'first_purchase' => [['tier' => 50, 'eligible' => true]],
            'wdp_eligible' => true,
        ]);
        $proof = $this->nickname($product)->assertOk()->json('region_proof');

        $this->postJson('/checkout/orders', $this->order($first, $proof))->assertCreated();
    }

    /**
     * NV-07: packages outside the first-purchase / weekly-pass family keep
     * the existing contract and need no proof at all.
     */
    public function test_regular_package_still_needs_no_proof(): void
    {
        $product = $this->game();
        $regular = $this->package($product, 'MLREG100', 'Diamond 100');

        $this->postJson('/checkout/orders', $this->order($regular, null))->assertCreated();
    }

    /**
     * NV-07: a Weekly Diamond Pass is rejected when H2H reports the account
     * ineligible for it, with or without a proof.
     */
    public function test_weekly_diamond_pass_rejected_when_h2h_reports_ineligible(): void
    {
        $product = $this->game();
        $wdp = $this->package($product, 'WDP-TEST', 'Weekly Diamond Pass');
        $this->h2h([
            'status' => 'VALID', 'nickname' => 'PlayerAce', 'region' => 'id',
            'first_purchase' => [],
            'wdp_eligible' => false,
        ]);
        $proof = $this->nickname($product)->assertOk()->json('region_proof');

        $this->postJson('/checkout/orders', $this->order($wdp, $proof))
            ->assertUnprocessable()->assertJsonValidationErrors(['package_id']);
        $this->postJson('/checkout/orders', $this->order($wdp, null))
            ->assertUnprocessable()->assertJsonValidationErrors(['package_id']);
        $this->assertDatabaseCount('orders', 0);
    }

    /**
     * NV-07: Magic Chess first-purchase packages are enforced the same way.
     */
    public function test_magic_chess_first_purchase_package_rejected_when_ineligible(): void
    {
        $product = $this->game('magic-chess-go-go');
        $first = $this->package($product, 'MC50_A', 'First Recharge 50');
        $this->h2h([
            'status' => 'VALID', 'nickname' => 'ChessAce', 'region' => 'id',
            'first_purchase' => [['tier' => 50, 'eligible' => false]],
            'wdp_eligible' => null,
        ]);
        $proof = $this->nickname($product)->assertOk()->json('region_proof');

        $this->postJson('/checkout/orders', $this->order($first, $proof))
            ->assertUnprocessable()->assertJsonValidationErrors(['package_id']);
        $this->postJson('/checkout/orders', $this->order($first, null))
            ->assertUnprocessable()->assertJsonValidationErrors(['package_id']);
        $this->assertDatabaseCount('orders', 0);
    }

    /**
     * NV-07: the eligibility proof is bound to the exact account input it
     * was issued for; another account cannot reuse it.
     */
    public function test_eligibility_proof_is_bound_to_the_checked_account_input(): void
    {
        $product = $this->game();
        $first = $this->package($product, 'MLFS100', 'First Recharge 50');
        $this->h2h([
            'status' => 'VALID', 'nickname' => 'PlayerAce', 'region' => 'id',
            'first_purchase' => [['tier' => 50, 'eligible' => true]],
            'wdp_eligible' => true,
        ]);
        $proof = $this->nickname($product, '123456')->assertOk()->json('region_proof');

        $this->postJson('/checkout/orders', $this->order($first, $proof, '654321'))
            ->assertUnprocessable()->assertJsonValidationErrors(['package_id']);
        $this->assertDatabaseCount('orders', 0);
    }

    /**
     * NV-07: a region-required product enforces eligibility from the same
     * proof that carries its region verdict.
     */
    public function test_region_product_also_enforces_eligibility_from_the_same_proof(): void
    {
        $product = $this->game('mobile-legends', 'ID');
        $first = $this->package($product, 'MLFS100', 'First Recharge 50');
        $this->h2h([
            'status' => 'VALID', 'nickname' => 'PlayerAce', 'region' => 'ID',
            'first_purchase' => [['tier' => 50, 'eligible' => false]],
            'wdp_eligible' => true,
        ]);
        $proof = $this->nickname($product)->assertOk()->json('region_proof');

        $this->postJson('/checkout/orders', $this->order($first, $proof))
            ->assertUnprocessable()->assertJsonValidationErrors(['package_id']);
        $this->assertDatabaseCount('orders', 0);
    }

    /**
     * NV-07 (Codex P1): when H2H returns VALID but omits both eligibility
     * verdicts, the client normalizes them to null; that must not count
     * as evaluated, so no proof is issued and family packages reject.
     */
    public function test_family_packages_rejected_when_h2h_omits_eligibility_verdicts(): void
    {
        $product = $this->game();
        $first = $this->package($product, 'MLFS100', 'First Recharge 50');
        $wdp = $this->package($product, 'WDP-TEST', 'Weekly Diamond Pass');
        $this->h2h([
            'status' => 'VALID', 'nickname' => 'PlayerAce', 'region' => 'id',
        ]);

        $this->nickname($product)->assertOk()->assertJsonPath('region_proof', null);
        $this->postJson('/checkout/orders', $this->order($first, null))
            ->assertUnprocessable()->assertJsonValidationErrors(['package_id']);
        $this->postJson('/checkout/orders', $this->order($wdp, null))
            ->assertUnprocessable()->assertJsonValidationErrors(['package_id']);
        $this->assertDatabaseCount('orders', 0);
    }

    /**
     * NV-07 (Codex P1): verdicts are per-family. A proof attesting only
     * the first-purchase verdict opens first-purchase packages but must
     * not open a Weekly Diamond Pass whose verdict is missing.
     */
    public function test_weekly_pass_rejected_when_only_first_purchase_was_evaluated(): void
    {
        $product = $this->game();
        $first = $this->package($product, 'MLFS100', 'First Recharge 50');
        $wdp = $this->package($product, 'WDP-TEST', 'Weekly Diamond Pass');
        $this->h2h([
            'status' => 'VALID', 'nickname' => 'PlayerAce', 'region' => 'id',
            'first_purchase' => [['tier' => 50, 'eligible' => true]],
        ]);
        $proof = $this->nickname($product)->assertOk()->json('region_proof');
        $this->assertNotEmpty($proof);

        $this->postJson('/checkout/orders', $this->order($first, $proof))->assertCreated();
        $this->postJson('/checkout/orders', $this->order($wdp, $proof))
            ->assertUnprocessable()->assertJsonValidationErrors(['package_id']);
        $this->assertDatabaseCount('orders', 1);
    }

    /**
     * NV-07 (Codex P1): the mirror case — a proof attesting only the
     * weekly-pass verdict must not open first-purchase packages.
     */
    public function test_first_purchase_rejected_when_only_weekly_pass_was_evaluated(): void
    {
        $product = $this->game();
        $first = $this->package($product, 'MLFS100', 'First Recharge 50');
        $wdp = $this->package($product, 'WDP-TEST', 'Weekly Diamond Pass');
        $this->h2h([
            'status' => 'VALID', 'nickname' => 'PlayerAce', 'region' => 'id',
            'wdp_eligible' => true,
        ]);
        $proof = $this->nickname($product)->assertOk()->json('region_proof');
        $this->assertNotEmpty($proof);

        $this->postJson('/checkout/orders', $this->order($wdp, $proof))->assertCreated();
        $this->postJson('/checkout/orders', $this->order($first, $proof))
            ->assertUnprocessable()->assertJsonValidationErrors(['package_id']);
        $this->assertDatabaseCount('orders', 1);
    }

    /**
     * NV-07 (Codex P2, round 3): first-purchase verdicts are per tier. A
     * verdict covering only tier 150 opens tier 150 but must not open a
     * tier-50 package the verdict never mentioned.
     */
    public function test_first_purchase_tier_without_a_verdict_is_rejected(): void
    {
        $product = $this->game();
        $tier50 = $this->package($product, 'MLFS100', 'First Recharge 50');
        $tier150 = $this->package($product, 'MLFS300', 'First Recharge 150');
        $this->h2h([
            'status' => 'VALID', 'nickname' => 'PlayerAce', 'region' => 'id',
            'first_purchase' => [['tier' => 150, 'eligible' => true]],
            'wdp_eligible' => true,
        ]);
        $proof = $this->nickname($product)->assertOk()->json('region_proof');
        $this->assertNotEmpty($proof);

        $this->postJson('/checkout/orders', $this->order($tier150, $proof))->assertCreated();
        $this->postJson('/checkout/orders', $this->order($tier50, $proof))
            ->assertUnprocessable()->assertJsonValidationErrors(['package_id']);
        $this->assertDatabaseCount('orders', 1);
    }

    /**
     * NV-07 (Codex P2, round 3): an empty first-purchase verdict array
     * attests no tier at all, so (without any other verdict) no proof is
     * issued and the package rejects.
     */
    public function test_first_purchase_empty_verdict_array_issues_no_proof(): void
    {
        $product = $this->game();
        $first = $this->package($product, 'MLFS100', 'First Recharge 50');
        $this->h2h([
            'status' => 'VALID', 'nickname' => 'PlayerAce', 'region' => 'id',
            'first_purchase' => [],
            'wdp_eligible' => null,
        ]);

        $this->nickname($product)->assertOk()->assertJsonPath('region_proof', null);
        $this->postJson('/checkout/orders', $this->order($first, null))
            ->assertUnprocessable()->assertJsonValidationErrors(['package_id']);
        $this->assertDatabaseCount('orders', 0);
    }

    /**
     * NV-07 (Codex P2, round 5): the product page must tell the client which
     * packages are eligibility-gated, so a missing proof can be refreshed
     * before submit instead of only failing at the server guard.
     */
    public function test_product_page_exposes_eligibility_gated_package_ids(): void
    {
        $product = $this->game();
        $first = $this->package($product, 'MLFS100', 'First Recharge 50');
        $weekly = $this->package($product, 'WDP1', 'Weekly Diamond Pass');
        $this->package($product, 'ML86', '86 Diamonds');

        $expected = [$first->id, $weekly->id];
        sort($expected);
        $this->get('/catalog/'.$product->slug)->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Catalog/Show')
                ->where('eligibilityPackageIds', function ($ids) use ($expected) {
                    $actual = collect($ids)->map(fn ($id) => (int) $id)->sort()->values()->all();

                    return $actual === $expected;
                })
                ->etc());
    }

    /**
     * NV-07: without any explicit VALID eligibility verdict (account not
     * found), a first-purchase package cannot be ordered either.
     */
    public function test_first_purchase_package_requires_a_valid_eligibility_verdict(): void
    {
        $product = $this->game();
        $first = $this->package($product, 'MLFS100', 'First Recharge 50');
        $this->h2h(['status' => 'NOT_FOUND']);

        $this->nickname($product)->assertOk()->assertJsonPath('region_proof', null);
        $this->postJson('/checkout/orders', $this->order($first, null))
            ->assertUnprocessable()->assertJsonValidationErrors(['package_id']);
        $this->assertDatabaseCount('orders', 0);
    }
}
