<?php

namespace Tests\Feature;

use App\Actions\Fortify\CreateNewUser;
use App\Models\Category;
use App\Models\Product;
use App\Services\CheckoutInputValidator;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Keputusan D17.1 (grand opening): nomor HP minimal 10 DIGIT.
 * "++++++" (6 karakter tanpa digit) dan nomor 8-9 digit ditolak di
 * semua titik validasi HP. Repo memakai PHPUnit (bukan Pest).
 */
class PhoneValidationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    private function quoteFixture(): int
    {
        $category = Category::where('slug', 'game')->firstOrFail();
        DB::table('providers')->where('code', 'DIGIFLAZZ')->update(['is_active' => true]);
        $providerId = DB::table('providers')->where('code', 'DIGIFLAZZ')->value('id');
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'HP Validation Game '.bin2hex(random_bytes(3)),
            'slug' => 'hp-validation-game-'.bin2hex(random_bytes(4)),
            'margin_percent' => 10,
            'fulfillment_mode' => 'AUTO_PROVIDER',
            'nickname_check_enabled' => false,
            'is_active' => true,
        ]);
        $package = $product->packages()->create([
            'code' => 'HPV'.bin2hex(random_bytes(3)),
            'name' => '100 Diamonds',
            'nominal_value' => 100,
            'is_active' => true,
        ]);
        DB::table('provider_mappings')->insertGetId([
            'product_package_id' => $package->id,
            'provider_id' => $providerId,
            'external_sku' => 'HPV-SKU-'.bin2hex(random_bytes(4)),
            'cost_idr' => 10000,
            'max_price_idr' => 12000,
            'fulfillment_config' => json_encode(['customer_no_template' => '{{user_id}}'], JSON_THROW_ON_ERROR),
            'priority' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->seedCheckoutDigiflazzCatalog($package->id);
        // QRIS manual (gateway MANUAL, bukan eksternal) — MIDTRANS tanpa
        // kredensial integrasi selalu masuk daftar gateway unavailable
        // sehingga route-nya terfilter dan quote gagal 422.
        DB::table('payment_gateways')->where('code', 'MANUAL_QRIS')->update([
            'is_active' => true, 'is_maintenance' => false, 'updated_at' => now(),
        ]);
        DB::table('payment_channels')->where('code', 'manual_qris')->update([
            'is_active' => true, 'updated_at' => now(),
        ]);

        return $package->id;
    }

    public function test_guest_phone_without_ten_digits_is_rejected(): void
    {
        foreach (['++++++', '081234567'] as $phone) {
            $this->postJson('/checkout/quote', [
                'package_id' => 1,
                'payment_channel_code' => 'qris',
                'guest_email' => 'hp@example.test',
                'guest_phone' => $phone,
            ])->assertUnprocessable()
                ->assertJsonValidationErrors('guest_phone');
        }
    }

    public function test_guest_phone_with_ten_digits_passes_validation(): void
    {
        $packageId = $this->quoteFixture();

        $this->postJson('/checkout/quote', [
            'package_id' => $packageId,
            'payment_channel_code' => 'manual_qris',
            'guest_email' => 'hp@example.test',
            'guest_phone' => '0812345678',
        ])->assertOk();
    }

    public function test_tel_customer_input_without_ten_digits_is_rejected(): void
    {
        $category = Category::where('slug', 'game')->firstOrFail();
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'HP Tel Game '.bin2hex(random_bytes(3)),
            'slug' => 'hp-tel-game-'.bin2hex(random_bytes(4)),
            'margin_percent' => 10,
            'is_active' => true,
        ]);
        $product->fields()->create([
            'field_key' => 'phone', 'label' => 'Nomor HP', 'type' => 'tel',
            'is_required' => true, 'sort_order' => 0,
        ]);

        $validator = app(CheckoutInputValidator::class);
        foreach (['++++++', '081234567'] as $phone) {
            try {
                $validator->validate($product, ['phone' => $phone]);
                $this->fail('Nomor HP '.$phone.' seharusnya ditolak.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('customer_input.phone', $exception->errors());
            }
        }
        $this->assertSame('0812345678', $validator->validate($product, ['phone' => '0812345678'])['phone']);
    }

    public function test_registration_phone_requires_ten_digits(): void
    {
        $creator = app(CreateNewUser::class);
        try {
            $creator->create([
                'name' => 'HP User',
                'email' => 'hp-reg-'.bin2hex(random_bytes(4)).'@example.test',
                'phone' => '08123456',
                'password' => 'StrongPassword123!',
                'password_confirmation' => 'StrongPassword123!',
            ]);
            $this->fail('Nomor HP 8 digit seharusnya ditolak saat registrasi.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('phone', $exception->errors());
        }

        $email = 'hp-reg-'.bin2hex(random_bytes(4)).'@example.test';
        $user = $creator->create([
            'name' => 'HP User',
            'email' => $email,
            'phone' => '0812345678',
            'password' => 'StrongPassword123!',
            'password_confirmation' => 'StrongPassword123!',
        ]);
        $this->assertSame($email, $user->email);
    }
}
