<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Services\PaymentStateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Throwable;

class WalletConcurrencyM11Test extends TestCase
{
    public function test_two_simultaneous_wallet_payments_cannot_overdraw_the_same_wallet(): void
    {
        $this->assertTrue(function_exists('pcntl_fork'), 'M11 wallet race test requires pcntl.');
        Queue::fake();

        $user = User::create([
            'name' => 'M11 Race Buyer',
            'email' => 'm11-race-'.bin2hex(random_bytes(4)).'@example.test',
            'phone' => '08'.random_int(1000000000, 9999999999),
            'password' => Hash::make('StrongPassword123!'),
            'email_verified_at' => now(),
        ]);
        $walletId = (int) DB::table('wallets')->where('user_id', $user->id)->value('id');
        DB::table('wallets')->where('id', $walletId)->update(['balance_idr' => 15000]);

        $categoryId = (int) DB::table('categories')->where('slug', 'game')->value('id');
        $product = Product::create([
            'category_id' => $categoryId,
            'name' => 'M11 Wallet Race '.bin2hex(random_bytes(3)),
            'slug' => 'm11-wallet-race-'.bin2hex(random_bytes(5)),
            'margin_percent' => 10,
            'fulfillment_mode' => 'AUTO_PROVIDER',
            'is_active' => true,
        ]);
        $package = $product->packages()->create([
            'code' => 'M11RACE'.bin2hex(random_bytes(3)),
            'name' => 'Race Package',
            'nominal_value' => 100,
            'is_active' => true,
        ]);

        $orderIds = [];
        $paymentIds = [];

        try {
            foreach ([1, 2] as $slot) {
                $orderId = DB::table('orders')->insertGetId([
                    'order_number' => 'ORD-M11-RACE-'.$slot.'-'.bin2hex(random_bytes(4)),
                    'user_id' => $user->id,
                    'product_id' => $product->id,
                    'product_package_id' => $package->id,
                    'status' => 'PENDING_PAYMENT',
                    'currency' => 'IDR',
                    'customer_input' => json_encode(['user_id' => 'RACE-'.$slot], JSON_THROW_ON_ERROR),
                    'snapshot' => json_encode(['m11' => 'wallet-race'], JSON_THROW_ON_ERROR),
                    'cost_idr' => 10000,
                    'margin_idr' => 1000,
                    'total_idr' => 11000,
                    'idempotency_key' => 'm11-wallet-race-order-'.$slot.'-'.bin2hex(random_bytes(4)),
                    'expires_at' => now()->addMinutes(30),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $orderIds[] = $orderId;

                $paymentIds[] = DB::table('payment_transactions')->insertGetId([
                    'order_id' => $orderId,
                    'gateway_code' => 'WALLET',
                    'channel_code' => 'saldo',
                    'amount_idr' => 11000,
                    'status' => 'CREATING',
                    'idempotency_key' => 'm11-wallet-race-payment-'.$slot.'-'.bin2hex(random_bytes(4)),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $prefix = sys_get_temp_dir().'/lfamilia-m11-race-'.bin2hex(random_bytes(5));
            $startFile = $prefix.'-start';
            $readyFiles = [$prefix.'-ready-0', $prefix.'-ready-1'];
            $resultFiles = [$prefix.'-result-0', $prefix.'-result-1'];
            $pids = [];

            DB::disconnect();

            foreach ($paymentIds as $index => $paymentId) {
                $pid = pcntl_fork();
                $this->assertNotSame(-1, $pid, 'Unable to fork M11 wallet race worker.');

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

                        app(PaymentStateService::class)->payOrderWithWallet((int) $paymentId, (int) $user->id);
                        file_put_contents($resultFiles[$index], 'paid');
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

            $this->assertSame(4000, (int) DB::table('wallets')->where('id', $walletId)->value('balance_idr'));
            $this->assertSame(1, DB::table('wallet_ledger')
                ->where('wallet_id', $walletId)
                ->where('source', 'CHECKOUT')
                ->count());
            $this->assertSame(1, DB::table('orders')->whereIn('id', $orderIds)->where('status', 'PAID')->count());
            $this->assertSame(1, DB::table('orders')->whereIn('id', $orderIds)->where('status', 'PENDING_PAYMENT')->count());

            foreach ([$startFile, ...$readyFiles, ...$resultFiles] as $file) {
                @unlink($file);
            }
        } finally {
            DB::reconnect();
            DB::table('order_events')->whereIn('order_id', $orderIds)->delete();
            DB::table('payment_transactions')->whereIn('id', $paymentIds)->delete();
            DB::table('orders')->whereIn('id', $orderIds)->delete();
            DB::table('wallet_ledger')->where('wallet_id', $walletId)->delete();
            DB::table('product_packages')->where('id', $package->id)->delete();
            DB::table('products')->where('id', $product->id)->delete();
            DB::table('wallets')->where('id', $walletId)->delete();
            DB::table('users')->where('id', $user->id)->delete();
        }
    }
}
