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
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProductSelectPasswordTest extends TestCase
{
    use DatabaseTransactions;

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
        $this->assertStringNotContainsString($plainPassword, (string) $record->value);
        $this->assertSame($plainPassword, $secret->value);
        $this->assertArrayNotHasKey('password', $snapshot['customer_input']);
        $this->assertArrayNotHasKey('password', $secret->toArray());

        $mapping = DB::table('provider_mappings')->where('id', $fixture['mapping_id'])->firstOrFail();
        $this->assertSame('123456789|os_asia', app(FulfillmentService::class)->digiflazzCustomerNo(
            $order, $mapping, $snapshot
        ));
    }

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
    }
}
