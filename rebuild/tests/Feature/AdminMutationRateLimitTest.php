<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdminMutationRateLimitTest extends TestCase
{
    public function test_catalog_mutation_routes_use_the_admin_mutation_limiter(): void
    {
        $route = app('router')->getRoutes()->match(request()->create('/admin/catalog/categories', 'POST'));

        $this->assertContains('throttle:admin-mutation', $route->gatherMiddleware());
    }

    public function test_bulk_supplier_import_is_not_limited_by_the_single_mutation_throttle(): void
    {
        $route = app('router')->getRoutes()->match(request()->create('/admin/catalog/products/123/import', 'POST'));

        $this->assertNotContains('throttle:admin-mutation', $route->gatherMiddleware());
    }

    public function test_admin_mutation_limiter_uses_the_admin_identity(): void
    {
        $request = request()->create('/admin/catalog/categories', 'POST');
        $request->setUserResolver(fn ($guard = null) => $guard === 'admin' ? (object) ['id' => 321] : null);

        $definition = app('Illuminate\\Cache\\RateLimiter')->limiter('admin-mutation');
        $limit = $definition($request);

        $this->assertSame(60, $limit->maxAttempts);
        $this->assertSame('admin-mutation:321', $limit->key);
    }

    public function test_regular_admin_writes_are_throttled_but_bulk_and_media_routes_are_exempt(): void
    {
        $router = app('router')->getRoutes();
        $cases = [
            ['POST', '/admin/catalog/categories', true],
            ['PUT', '/admin/catalog/products/123/packages/reorder', true],
            ['PUT', '/admin/content/theme', true],
            ['POST', '/admin/vouchers', true],
            ['PUT', '/admin/support/123', true],
            ['POST', '/admin/catalog/products/123/import', false],
            ['POST', '/admin/catalog/packages/123/voucher-stock', false],
            ['POST', '/admin/catalog/media/product/123', false],
        ];

        foreach ($cases as [$method, $uri, $expected]) {
            $route = $router->match(request()->create($uri, $method));
            $hasLimiter = in_array('throttle:admin-mutation', $route->gatherMiddleware(), true);

            $this->assertSame($expected, $hasLimiter, $method.' '.$uri);
        }
    }
}
