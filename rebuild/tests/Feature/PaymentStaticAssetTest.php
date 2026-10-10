<?php

namespace Tests\Feature;

use Tests\TestCase;

class PaymentStaticAssetTest extends TestCase
{
    public function test_payment_route_is_not_shadowed_by_static_directory(): void
    {
        // Nginx try_files prioritizes a physical public/payment directory over GET /payment.
        $this->assertDirectoryDoesNotExist(public_path('payment'));
        $this->assertFileExists(public_path('assets/payment/lfamilia-cash.webp'));

        $catalog = file_get_contents(resource_path('js/Pages/Catalog/Show.vue'));
        $this->assertNotFalse($catalog);
        $this->assertStringContainsString('/assets/payment/lfamilia-cash.webp', $catalog);
        $this->assertStringNotContainsString("'/payment/lfamilia-cash.webp'", $catalog);
    }
}
