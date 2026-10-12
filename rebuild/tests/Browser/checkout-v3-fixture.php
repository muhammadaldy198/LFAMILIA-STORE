<?php

use App\Models\Category;
use App\Models\IntegrationCredential;
use App\Models\PaymentChannel;
use App\Models\Product;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment(['local', 'testing']) || getenv('LFAMILIA_VISUAL_QA') !== '1' || config('database.connections.mysql.database') !== 'lfamilia_checkout_preview_v3' || ! in_array(config('database.connections.mysql.host'), ['127.0.0.1', 'localhost'], true)) {
    throw new RuntimeException('Isolated checkout preview database and explicit QA opt-in required.');
}
Http::preventStrayRequests();
Queue::fake();
$db = DB::class;
// Separate media paths from the independent browser smoke database.
$db::statement('ALTER TABLE media AUTO_INCREMENT=100000');
$p = Product::updateOrCreate(['slug' => 'mobile-legends'], ['category_id' => Category::where('slug', 'game')->firstOrFail()->id, 'name' => 'Mobile Legends', 'publisher' => 'Moonton', 'description' => 'Data dummy untuk review visual checkout PR #266. Tidak memproses transaksi nyata.', 'initials' => 'ML', 'accent_color' => '#168dc9', 'fulfillment_mode' => 'MANUAL', 'margin_percent' => 10, 'is_active' => true, 'popular' => true, 'nickname_check_enabled' => false, 'package_tabs_enabled' => true, 'package_tabs' => ['Diamonds', 'Weekly Pass']]);
foreach (['user_id' => 'User ID', 'zone_id' => 'Zone ID'] as $key => $label) {
    $p->fields()->updateOrCreate(['field_key' => $key], ['label' => $label, 'placeholder' => 'Masukkan '.$label, 'type' => 'text', 'is_required' => true, 'sort_order' => $key === 'user_id' ? 0 : 1]);
}
$db::table('providers')->where('code', 'MANUAL')->update(['is_active' => true]);
$provider = $db::table('providers')->where('code', 'MANUAL')->value('id');
foreach ([[86, 20000], [172, 39000], [257, 58000], [344, 77000], [514, 115000], [706, 155000], [2195, 470000], [0, 28000]] as $i => [$amount,$price]) {
    $pass = $amount === 0;
    $pkg = $p->packages()->updateOrCreate(['code' => 'PREVIEW-ML-'.$amount], ['name' => $pass ? 'Weekly Diamond Pass' : ($amount === 2195 ? '2195 Diamonds + Bonus / Paket Percobaan Nama Panjang untuk QA' : $amount.' Diamonds'), 'nominal_value' => $amount, 'group_name' => $pass ? 'Weekly Pass' : 'Diamonds', 'sort_order' => $i, 'is_active' => true, 'pricing_mode' => 'SELL_PRICE', 'sell_price_idr' => $price, 'is_promo' => $i < 2, 'original_price' => $i < 2 ? $price + 5000 : null, 'points' => max(1, intdiv($price, 1000)), 'detail_text' => $pass ? 'Diamond harian selama 7 hari. Contoh untuk preview.' : 'Top up '.$amount.' Diamonds. Data dummy untuk verifikasi visual.']);
    $db::table('provider_mappings')->updateOrInsert(['product_package_id' => $pkg->id, 'provider_id' => $provider], ['external_sku' => 'PREVIEW-ML-'.$amount, 'cost_idr' => intdiv($price * 9, 10), 'priority' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
}
$db::table('payment_gateways')->where('code', 'MANUAL_QRIS')->update(['is_active' => true]);
$db::table('payment_channels')->where('code', 'manual_qris')->update(['is_active' => true]);
echo 'Mobile Legends preview seeded: '.$p->packages()->count().' packages'.PHP_EOL;

@mkdir(storage_path('framework/testing'), 0775, true);
foreach (['image' => [256, 256, 'ML / QA'], 'banner' => [1440, 448, 'LFAMILIA / CHECKOUT QA']] as $collection => [$w,$h,$label]) {
    if (! $p->getFirstMedia($collection)) {
        $im = imagecreatetruecolor($w, $h);
        $bg = imagecolorallocate($im, 20, 40, 65);
        imagefill($im, 0, 0, $bg);
        $accent = imagecolorallocate($im, 167, 223, 43);
        imagefilledrectangle($im, 0, $h - 25, $w, $h, $accent);
        imagestring($im, 5, 24, (int) ($h / 2), $label, $accent);
        $path = storage_path('framework/testing/checkout-v3-'.$collection.'.png');
        imagepng($im, $path);
        imagedestroy($im);
        $p->addMedia($path)->preservingOriginal()->toMediaCollection($collection);
    }
}

$db::table('vouchers')->updateOrInsert(['code' => 'CLOUDQA1000'], ['discount_type' => 'FIXED', 'discount_value' => 1000, 'is_active' => true, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
$channel = PaymentChannel::where('code', 'manual_qris')->firstOrFail();
if (! $channel->getFirstMedia('logo')) {
    $im = imagecreatetruecolor(120, 48);
    imagefill($im, 0, 0, imagecolorallocate($im, 240, 240, 240));
    imagestring($im, 5, 10, 15, 'QA QRIS', imagecolorallocate($im, 20, 40, 65));
    $path = storage_path('framework/testing/checkout-v3-qris.png');
    imagepng($im, $path);
    imagedestroy($im);
    $channel->addMedia($path)->preservingOriginal()->toMediaCollection('logo');
}

// These unusable sandbox values only expose presentation choices. Browser QA
// blocks order/payment mutations and every non-loopback request.
IntegrationCredential::updateOrCreate(['code' => 'midtrans'], [
    'config_ciphertext' => ['environment' => 'sandbox', 'profiles' => ['sandbox' => ['server_key' => 'cloud-qa-not-a-real-key']]],
    'is_active' => true,
]);
$db::table('payment_gateways')->where('code', 'MIDTRANS')->update(['is_active' => true]);
$channelIds = $db::table('payment_channels')->whereIn('code', ['qris', 'ewallet', 'va_bni', 'va_bri'])->pluck('id');
$db::table('payment_channels')->whereIn('id', $channelIds)->update(['is_active' => true]);
$db::table('payment_routes')->whereIn('payment_channel_id', $channelIds)->where('payment_gateway_id', $db::table('payment_gateways')->where('code', 'MIDTRANS')->value('id'))->update(['is_active' => true]);
