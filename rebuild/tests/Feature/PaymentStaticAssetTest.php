<?php

namespace Tests\Feature;

use Tests\TestCase;

class PaymentStaticAssetTest extends TestCase
{
    public function test_nginx_baseline_keeps_exact_match_payment_routes(): void
    {
        // A physical public/payment directory must never shadow Laravel /payment,
        // including after CloudPanel regenerates its vhost from the repository baseline.
        $path = base_path('deploy/nginx/cloudpanel-vhost-snippet.conf');
        $this->assertFileExists($path);
        $config = file_get_contents($path);
        $this->assertNotFalse($config);

        foreach (['/payment', '/payment/'] as $route) {
            $pattern = '~location\s*=\s*'.preg_quote($route, '~')
                .'\s*\{\s*rewrite\s+\^\s+/index\.php\s+last;\s*\}~';
            $this->assertSame(1, preg_match($pattern, $config), 'Missing exact-match Laravel safety net for '.$route);
        }
    }

    public function test_payment_route_is_not_shadowed_by_static_directory(): void
    {
        // Nginx try_files prioritizes a physical public/payment directory over GET /payment.
        $this->assertDirectoryDoesNotExist(public_path('payment'));
        $this->assertFileExists(public_path('assets/payment/lfamilia-cash.webp'));

        $catalog = file_get_contents(resource_path('js/Pages/Catalog/Show.vue'));
        $this->assertNotFalse($catalog);
        // The wallet payment option now uses the CSS-rendered LFAMILIA Coins mark.
        // Keep guarding against the old physical public/payment route collision.
        $this->assertStringContainsString('lf-payment-coins-mark', $catalog);
        $this->assertStringContainsString('LFAMILIA Coins', $catalog);
        $this->assertStringNotContainsString("'/payment/lfamilia-cash.webp'", $catalog);
    }
}
