<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\OrderSensitiveInput;
use App\Models\Product;
use App\Services\CheckoutInputValidator;
use App\Services\CheckoutService;
use App\Services\Fulfillment\FulfillmentTargetBuilder;
use App\Services\FulfillmentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProductSelectPasswordTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Create a product fixture with optional via-login credentials.
     */
    private function product(bool $withPassword = false): Product
    {
        $product = Product::create([
            'category_id' => Category::where('slug', 'game')->firstOrFail()->id,
            'name' => 'Genshin fixture '.bin2hex(random_bytes(3)),
            'slug' => 'genshin-fixture-'.bin2hex(random_bytes(4)),
            'margin_percent' => 10,
            'fulfillment_mode' => 'AUTO_PROVIDER',
            'customer_no_template' => '{{user_id}}|{{server}}',
            'is_active' => true,
        ]);
        $product->fields()->create([
            'field_key' => 'user_id', 'label' => 'UID', 'type' => 'text',
            'is_required' => true, 'sort_order' => 0,
        ]);
        $product->fields()->create([
            'field_key' => 'server', 'label' => 'Server', 'type' => 'select',
            'options' => [
                ['value' => 'os_asia', 'label' => 'Asia'],
                ['value' => 'os_usa', 'label' => 'America'],
                ['value' => 'os_euro', 'label' => 'Europe'],
                ['value' => 'os_cht', 'label' => 'TW, HK, MO'],
            ],
            'is_required' => true, 'sort_order' => 1,
        ]);
        if ($withPassword) {
            $product->fields()->create([
                'field_key' => 'password', 'label' => 'Password via-login',
                'type' => 'password', 'is_required' => true, 'sort_order' => 2,
            ]);
        }

        return $product;
    }

    /**
     * Prepare a buyable Digiflazz package for checkout tests.
     */
    private function fixtureCheckout(Product $product): array
    {
        DB::table('providers')->where('code', 'DIGIFLAZZ')->update(['is_active' => true]);
        DB::table('payment_gateways')->where('code', 'MANUAL_QRIS')->update(['is_active' => true]);
        DB::table('payment_channels')->where('code', 'manual_qris')->update(['is_active' => true]);
        $package = $product->packages()->create([
            'code' => 'GS'.bin2hex(random_bytes(3)), 'name' => '60 Crystals',
            'nominal_value' => 60, 'is_active' => true,
            'pricing_mode' => 'PERCENT', 'margin_percent' => 10,
        ]);
        $mappingId = DB::table('provider_mappings')->insertGetId([
            'product_package_id' => $package->id,
            'provider_id' => DB::table('providers')->where('code', 'DIGIFLAZZ')->value('id'),
            'external_sku' => 'GS-'.bin2hex(random_bytes(4)),
            'cost_idr' => 10000, 'max_price_idr' => 12000,
            'priority' => 1, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->seedCheckoutDigiflazzCatalog($package->id);

        return ['package_id' => $package->id, 'mapping_id' => $mappingId];
    }

    /**
     * Create a guest order through the real checkout service.
     */
    private function checkout(int $packageId, array $input): object
    {
        $key = 'genshin-fields-'.bin2hex(random_bytes(10));
        app(CheckoutService::class)->create([
            'package_id' => $packageId,
            'payment_channel_code' => 'manual_qris',
            'customer_input' => $input,
            'guest_email' => 'genshin@example.test',
            'guest_phone' => '081234567890',
            'idempotency_key' => $key,
        ], null);

        return DB::table('orders')->where('idempotency_key', $key)->firstOrFail();
    }

    /**
     * Ensure select inputs use exact allowed codes.
     */
    public function test_select_requires_a_valid_code_and_keeps_the_value_not_label(): void
    {
        $product = $this->product();
        $validator = app(CheckoutInputValidator::class);

        foreach (['', 'Asia', 'os_invalid'] as $server) {
            try {
                $validator->validate($product, ['user_id' => '123456789', 'server' => $server]);
                $this->fail('Server tidak sah harus ditolak.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('customer_input.server', $exception->errors());
            }
        }

        $input = $validator->validate($product, ['user_id' => '123456789', 'server' => 'os_asia']);
        $this->assertSame('os_asia', $input['server']);
        $this->assertSame('123456789|os_asia', app(FulfillmentTargetBuilder::class)->customerNo(
            $input, ['customer_no_template' => '{{user_id}}|{{server}}']
        ));
    }

    /**
     * Keep the Genshin server code unchanged through order fulfillment.
     */
    public function test_genshin_checkout_snapshot_and_provider_target_use_exact_server_code(): void
    {
        $product = $this->product();
        $fixture = $this->fixtureCheckout($product);
        $order = $this->checkout($fixture['package_id'], [
            'user_id' => '123456789', 'server' => 'os_asia',
        ]);
        $snapshot = json_decode($order->snapshot, true, 512, JSON_THROW_ON_ERROR);
        $mapping = DB::table('provider_mappings')->where('id', $fixture['mapping_id'])->firstOrFail();

        $this->assertSame('os_asia', $snapshot['customer_input']['server']);
        $this->assertSame('{{user_id}}|{{server}}', $snapshot['provider']['customer_no_template']);
        $this->assertSame('123456789|os_asia', app(FulfillmentService::class)->digiflazzCustomerNo(
            $order, $mapping, $snapshot
        ));
    }

    /**
     * Persist secrets separately without exposing them in public order records.
     */
    public function test_password_is_encrypted_and_absent_from_public_order_json(): void
    {
        $product = $this->product(true);
        $fixture = $this->fixtureCheckout($product);
        $plainPassword = 'ExampleSecret-'.bin2hex(random_bytes(8));
        $input = ['user_id' => '123456789', 'server' => 'os_asia', 'password' => $plainPassword];
        $validator = app(CheckoutInputValidator::class);
        $this->assertArrayNotHasKey('password', $validator->publicInput($product, $input));
        $this->assertArrayNotHasKey('password', $validator->validate($product, $input, true));

        $order = $this->checkout($fixture['package_id'], $input);
        $snapshot = json_decode($order->snapshot, true, 512, JSON_THROW_ON_ERROR);
        $record = DB::table('order_sensitive_inputs')->where('order_id', $order->id)->firstOrFail();
        $secret = OrderSensitiveInput::where('order_id', $order->id)->firstOrFail();

        $this->assertStringNotContainsString($plainPassword, (string) $order->customer_input);
        $this->assertStringNotContainsString($plainPassword, (string) $order->snapshot);
        $this->assertStringStartsWith('hmac-sha256:', $snapshot['checkout_fingerprint']);
        $this->assertStringNotContainsString($plainPassword, (string) $record->value);
        $this->assertSame($plainPassword, $secret->value);
        $this->assertArrayNotHasKey('password', $snapshot['customer_input']);
        $this->assertArrayNotHasKey('password', $secret->toArray());

        $mapping = DB::table('provider_mappings')->where('id', $fixture['mapping_id'])->firstOrFail();
        $this->assertSame('123456789|os_asia', app(FulfillmentService::class)->digiflazzCustomerNo(
            $order, $mapping, $snapshot
        ));
    }

    /**
     * Redact failed-provider diagnostics before persistence.
     */
    public function test_failed_provider_response_never_persists_plaintext_password(): void
    {
        Queue::fake();
        $product = $this->product(true);
        $product->update(['customer_no_template' => '{{user_id}}|{{password}}']);
        $fixture = $this->fixtureCheckout($product);
        $password = 'VeryPrivate-'.bin2hex(random_bytes(6));
        $order = $this->checkout($fixture['package_id'], [
            'user_id' => '123456789', 'server' => 'os_asia', 'password' => $password,
        ]);
        DB::table('orders')->where('id', $order->id)->update(['status' => 'PAID', 'paid_at' => now()]);

        $service = app(FulfillmentService::class);
        $service->startOrder((int) $order->id);
        $attempt = DB::table('fulfillment_attempts')->where('order_id', $order->id)->firstOrFail();
        $sku = DB::table('provider_mappings')->where('id', $fixture['mapping_id'])->value('external_sku');
        $request = [
            'ref_id' => $attempt->external_reference,
            'buyer_sku_code' => $sku,
            'customer_no' => '123456789|'.$password,
        ];
        $encrypted = Crypt::encryptString(json_encode($request, JSON_THROW_ON_ERROR));
        DB::table('fulfillment_attempts')->where('id', $attempt->id)->update([
            'status' => 'SENDING',
            'request_payload' => json_encode(['encrypted' => $encrypted], JSON_THROW_ON_ERROR),
        ]);

        $service->applyProviderResult((int) $attempt->id, [
            ...$request,
            'status' => 'Gagal',
            'rc' => '02',
            'message' => 'Login ditolak untuk '.$request['customer_no'],
        ], 'provider_response');

        $stored = DB::table('fulfillment_attempts')->where('id', $attempt->id)->firstOrFail();
        $this->assertSame('FAILED_CONFIRMED', $stored->status);
        $this->assertStringNotContainsString($password, (string) $stored->last_error);
        $this->assertStringNotContainsString($password, (string) $stored->response_payload);
        $this->assertStringNotContainsString($password, (string) $stored->serial_number);
        $this->assertSame('BLOCKED', DB::table('orders')->where('id', $order->id)->value('status'));
    }

    /**
     * Preserve idempotent retries for older, unkeyed fingerprints.
     */
    public function test_existing_legacy_idempotency_fingerprints_remain_accepted(): void
    {
        $product = $this->product();
        $fixture = $this->fixtureCheckout($product);
        $key = 'genshin-legacy-'.bin2hex(random_bytes(8));
        $data = [
            'package_id' => $fixture['package_id'],
            'payment_channel_code' => 'manual_qris',
            'customer_input' => ['user_id' => '123456789', 'server' => 'os_asia'],
            'guest_email' => 'genshin@example.test',
            'guest_phone' => '081234567890',
            'idempotency_key' => $key,
        ];
        $service = app(CheckoutService::class);
        $service->create($data, null);
        $order = DB::table('orders')->where('idempotency_key', $key)->firstOrFail();
        $snapshot = json_decode($order->snapshot, true, 512, JSON_THROW_ON_ERROR);
        $payload = [
            'actor' => ['type' => 'guest', 'email' => 'genshin@example.test', 'phone' => '081234567890'],
            'package_id' => $fixture['package_id'],
            'payment_channel_code' => 'manual_qris',
            'voucher_code' => '',
            'customer_input' => ['server' => 'os_asia', 'user_id' => '123456789'],
        ];
        $snapshot['checkout_fingerprint'] = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        DB::table('orders')->where('id', $order->id)->update(['snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR)]);
        $retried = $service->create($data, null);
        $this->assertFalse($retried['created']);
        $this->assertSame((int) $order->id, (int) $retried['order']->id);
    }

    /**
     * Only substitute secret values in explicitly configured templates.
     */
    public function test_password_can_reach_provider_only_when_template_explicitly_uses_it(): void
    {
        $product = $this->product(true);
        $product->update(['customer_no_template' => '{{user_id}}|{{password}}']);
        $fixture = $this->fixtureCheckout($product);
        $password = 'PrivateViaLogin-'.bin2hex(random_bytes(6));
        $order = $this->checkout($fixture['package_id'], [
            'user_id' => '7654321', 'server' => 'os_euro', 'password' => $password,
        ]);
        $snapshot = json_decode($order->snapshot, true, 512, JSON_THROW_ON_ERROR);
        $mapping = DB::table('provider_mappings')->where('id', $fixture['mapping_id'])->firstOrFail();
        $this->assertSame('7654321|'.$password, app(FulfillmentService::class)->digiflazzCustomerNo(
            $order, $mapping, $snapshot
        ));
        $this->assertStringNotContainsString($password, $order->snapshot);

        // Leading/trailing spaces are part of the password, not input formatting.
        $spaced = '  another secret  ';
        $validator = app(CheckoutInputValidator::class);
        $this->assertSame($spaced, $validator->validate($product, [
            'user_id' => '7654321', 'server' => 'os_euro', 'password' => $spaced,
        ])['password']);
        $spacedOrder = $this->checkout($fixture['package_id'], [
            'user_id' => '7654321', 'server' => 'os_euro', 'password' => $spaced,
        ]);
        $this->assertSame($spaced, OrderSensitiveInput::where('order_id', $spacedOrder->id)->firstOrFail()->value);
        $this->assertSame('7654321|'.$spaced, app(FulfillmentService::class)->digiflazzCustomerNo(
            $spacedOrder, $mapping, json_decode($spacedOrder->snapshot, true, 512, JSON_THROW_ON_ERROR)
        ));
    }

    /**
     * Password inputs are capped independently without reducing normal text limits.
     */
    public function test_password_maximum_length_is_enforced_only_for_password_fields(): void
    {
        $product = $this->product(true);
        $validator = app(CheckoutInputValidator::class);
        $longUserId = str_repeat('1', 75);
        $valid = $validator->validate($product, [
            'user_id' => $longUserId, 'server' => 'os_asia', 'password' => str_repeat('p', 64),
        ]);
        $this->assertSame($longUserId, $valid['user_id']);

        try {
            $validator->validate($product, [
                'user_id' => $longUserId, 'server' => 'os_asia', 'password' => str_repeat('p', 65),
            ]);
            $this->fail('Password yang lebih panjang dari 64 karakter harus ditolak.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('customer_input.password', $exception->errors());
        }
    }

    /**
     * Reject impossible Digiflazz targets before creating a payable order.
     */
    public function test_checkout_rejects_destination_over_100_characters_before_payment(): void
    {
        $product = $this->product(true);
        $product->update(['customer_no_template' => '{{user_id}}|{{password}}']);
        $fixture = $this->fixtureCheckout($product);
        $before = DB::table('orders')->where('product_id', $product->id)->count();

        try {
            $this->checkout($fixture['package_id'], [
                'user_id' => str_repeat('1', 70),
                'server' => 'os_asia',
                'password' => str_repeat('p', 40),
            ]);
            $this->fail('Target provider terlalu panjang harus ditolak sebelum order dibuat.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('fulfillment', $exception->errors());
        }

        $this->assertSame($before, DB::table('orders')->where('product_id', $product->id)->count());
    }

    /**
     * Bad encrypted payloads return no request; valid and plain JSON still decode.
     */
    public function test_corrupt_encrypted_fulfillment_payload_fails_closed(): void
    {
        $service = app(FulfillmentService::class);
        $method = new \ReflectionMethod(FulfillmentService::class, 'json');

        $this->assertSame([], $method->invoke(
            $service, json_encode(['encrypted' => 'invalid-ciphertext'], JSON_THROW_ON_ERROR)
        ));
        $plain = ['ref_id' => 'FUL-TEST', 'buyer_sku_code' => 'GS-TEST'];
        $this->assertSame($plain, $method->invoke($service, json_encode($plain, JSON_THROW_ON_ERROR)));
        $this->assertSame($plain, $method->invoke(
            $service, json_encode(['encrypted' => Crypt::encryptString(json_encode($plain, JSON_THROW_ON_ERROR))], JSON_THROW_ON_ERROR)
        ));
    }

    /**
     * Credential-like braces must be treated as data, never template syntax.
     */
    public function test_password_with_braced_text_is_not_parsed_as_provider_placeholder(): void
    {
        $product = $this->product(true);
        $product->update(['customer_no_template' => '{{user_id}}|{{password}}']);
        $fixture = $this->fixtureCheckout($product);
        $password = 'p@ss{{phrase}}!';
        $order = $this->checkout($fixture['package_id'], [
            'user_id' => '123456789', 'server' => 'os_asia', 'password' => $password,
        ]);
        $mapping = DB::table('provider_mappings')->where('id', $fixture['mapping_id'])->firstOrFail();

        $this->assertSame('123456789|'.$password, app(FulfillmentService::class)->digiflazzCustomerNo(
            $order, $mapping, json_decode($order->snapshot, true, 512, JSON_THROW_ON_ERROR)
        ));
    }

    /**
     * Provider protocol statuses and response codes remain usable for manual retry.
     */
    public function test_password_redaction_keeps_digiflazz_failure_status_and_rc(): void
    {
        Queue::fake();
        $product = $this->product(true);
        $product->update(['customer_no_template' => '{{user_id}}|{{password}}']);
        $fixture = $this->fixtureCheckout($product);
        $sku = DB::table('provider_mappings')->where('id', $fixture['mapping_id'])->value('external_sku');

        foreach (['Gagal', '02', 'a'] as $password) {
            $order = $this->checkout($fixture['package_id'], [
                'user_id' => '123456789', 'server' => 'os_asia', 'password' => $password,
            ]);
            DB::table('orders')->where('id', $order->id)->update(['status' => 'PAID', 'paid_at' => now()]);
            $service = app(FulfillmentService::class);
            $service->startOrder((int) $order->id);
            $attempt = DB::table('fulfillment_attempts')->where('order_id', $order->id)->firstOrFail();
            $request = [
                'ref_id' => $attempt->external_reference,
                'buyer_sku_code' => $sku,
                'customer_no' => '123456789|'.$password,
            ];
            DB::table('fulfillment_attempts')->where('id', $attempt->id)->update([
                'status' => 'SENDING',
                'request_payload' => json_encode([
                    'encrypted' => Crypt::encryptString(json_encode($request, JSON_THROW_ON_ERROR)),
                ], JSON_THROW_ON_ERROR),
            ]);
            $service->applyProviderResult((int) $attempt->id, [
                ...$request, 'status' => 'Gagal', 'rc' => '02',
                'message' => 'Akun ditolak: '.$request['customer_no'],
            ], 'provider_response');

            $stored = DB::table('fulfillment_attempts')->where('id', $attempt->id)->firstOrFail();
            $this->assertSame('Gagal', $stored->provider_status);
            $this->assertSame('02', $stored->provider_rc);
            $this->assertTrue(FulfillmentService::isDefinitiveProviderFailure($stored->provider_status, $stored->provider_rc));
            $savedResponse = json_decode($stored->response_payload, true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('Gagal', $savedResponse['status']);
            $this->assertSame('02', $savedResponse['rc']);
            $this->assertSame('FAILED_CONFIRMED', $stored->status);
        }
    }
}
