<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Services\CheckoutService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Throwable;

class VoucherConcurrencyM11Test extends TestCase
{
    public function test_two_simultaneous_orders_cannot_exceed_voucher_total_quota(): void
    {
        $this->assertTrue(function_exists('pcntl_fork'), 'M11 voucher race test requires pcntl.');
        Queue::fake();

        $category = Category::where('slug', 'game')->firstOrFail();

        DB::table('providers')->where('code', 'DIGIFLAZZ')->update(['is_active' => true]);
        DB::table('payment_gateways')->where('code', 'MANUAL_QRIS')->update(['is_active' => true]);
        DB::table('payment_channels')->where('code', 'manual_qris')->update(['is_active' => true]);
        $providerId = DB::table('providers')->where('code', 'DIGIFLAZZ')->value('id');

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'M11 Voucher Race '.bin2hex(random_bytes(3)),
            'slug' => 'm11-voucher-race-'.bin2hex(random_bytes(5)),
            'margin_percent' => 10,
            'fulfillment_mode' => 'AUTO_PROVIDER',
            'is_active' => true,
        ]);
        $field = $product->fields()->create([
            'field_key' => 'user_id',
            'label' => 'User ID',
            'type' => 'text',
            'is_required' => true,
            'sort_order' => 0,
        ]);
        $package = $product->packages()->create([
            'code' => 'M11VOUCH'.bin2hex(random_bytes(3)),
            'name' => 'Race Package',
            'nominal_value' => 100,
            'is_active' => true,
        ]);

        $externalSku = 'SKU-M11-'.bin2hex(random_bytes(4));
        $mappingId = DB::table('provider_mappings')->insertGetId([
            'product_package_id' => $package->id,
            'provider_id' => $providerId,
            'external_sku' => $externalSku,
            'cost_idr' => 10000,
            'max_price_idr' => 12000,
            'priority' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->seedCheckoutDigiflazzCatalog($package->id);

        $voucherCode = 'RACE'.strtoupper(bin2hex(random_bytes(3)));
        $voucherId = DB::table('vouchers')->insertGetId([
            'code' => $voucherCode,
            'discount_type' => 'FIXED',
            'discount_value' => 1000,
            'minimum_total_idr' => 0,
            'total_quota' => 1,
            'per_customer_limit' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payloads = [
            [
                'package_id' => $package->id,
                'payment_channel_code' => 'manual_qris',
                'customer_input' => ['user_id' => 'RACE-USER-1'],
                'voucher_code' => $voucherCode,
                'guest_email' => 'm11-buyer-1-'.bin2hex(random_bytes(3)).'@example.test',
                'guest_phone' => '0812'.random_int(10000000, 99999999),
                'idempotency_key' => 'm11-voucher-race-'.bin2hex(random_bytes(5)),
            ],
            [
                'package_id' => $package->id,
                'payment_channel_code' => 'manual_qris',
                'customer_input' => ['user_id' => 'RACE-USER-2'],
                'voucher_code' => $voucherCode,
                'guest_email' => 'm11-buyer-2-'.bin2hex(random_bytes(3)).'@example.test',
                'guest_phone' => '0813'.random_int(10000000, 99999999),
                'idempotency_key' => 'm11-voucher-race-'.bin2hex(random_bytes(5)),
            ],
        ];

        $prefix = sys_get_temp_dir().'/lfamilia-voucher-race-'.bin2hex(random_bytes(5));
        $startFile = $prefix.'-start';
        $readyFiles = [$prefix.'-ready-0', $prefix.'-ready-1'];
        $resultFiles = [$prefix.'-result-0', $prefix.'-result-1'];
        $pids = [];

        try {
            DB::disconnect();

            foreach ($payloads as $index => $payload) {
                $pid = pcntl_fork();
                $this->assertNotSame(-1, $pid, 'Unable to fork M11 voucher race worker.');

                if ($pid === 0) {
                    try {
                        DB::reconnect();
                        touch($readyFiles[$index]);

                        $deadline = microtime(true) + 10;
                        while (! file_exists($startFile) && microtime(true) < $deadline) {
                            usleep(10000);
                        }
                        if (! file_exists($startFile)) {
                            file_put_contents($resultFiles[$index], 'barrier-timeout');
                            exit(3);
                        }

                        $res = app(CheckoutService::class)->create($payload, null);
                        file_put_contents($resultFiles[$index], 'created:'.($res['order']->id ?? 'unknown'));
                        exit(0);
                    } catch (ValidationException $exception) {
                        file_put_contents($resultFiles[$index], 'rejected:'.$exception->getMessage());
                        exit(2);
                    } catch (Throwable $exception) {
                        file_put_contents($resultFiles[$index], 'error:'.$exception::class.':'.$exception->getMessage());
                        exit(3);
                    }
                }

                $pids[] = $pid;
            }

            $deadline = microtime(true) + 10;
            while ((! file_exists($readyFiles[0]) || ! file_exists($readyFiles[1])) && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertFileExists($readyFiles[0]);
            $this->assertFileExists($readyFiles[1]);
            touch($startFile);

            $exitCodes = [];
            foreach ($pids as $pid) {
                pcntl_waitpid($pid, $status);
                $exitCodes[] = pcntl_wexitstatus($status);
            }

            DB::reconnect();
            sort($exitCodes);
            $this->assertSame([0, 2], $exitCodes, implode(' | ', array_map(
                fn (string $file): string => file_exists($file) ? (string) file_get_contents($file) : 'missing-result',
                $resultFiles
            )));

            $this->assertSame(1, DB::table('voucher_redemptions')->where('voucher_id', $voucherId)->count());
            $this->assertSame(1, DB::table('orders')->where('voucher_id', $voucherId)->count());

            foreach ([$startFile, ...$readyFiles, ...$resultFiles] as $file) {
                @unlink($file);
            }
        } finally {
            DB::reconnect();
            $orderIds = DB::table('orders')->where('product_id', $product->id)->pluck('id')->all();
            if ($orderIds !== []) {
                DB::table('voucher_redemptions')->where('voucher_id', $voucherId)->delete();
                DB::table('order_events')->whereIn('order_id', $orderIds)->delete();
                DB::table('payment_transactions')->whereIn('order_id', $orderIds)->delete();
                DB::table('orders')->whereIn('id', $orderIds)->delete();
            }
            DB::table('vouchers')->where('id', $voucherId)->delete();
            DB::table('provider_mappings')->where('id', $mappingId)->delete();
            DB::table('digiflazz_catalog_items')->where('buyer_sku_code', $externalSku)->delete();
            DB::table('product_packages')->where('id', $package->id)->delete();
            DB::table('product_fields')->where('id', $field->id)->delete();
            DB::table('products')->where('id', $product->id)->delete();
        }
    }
}
