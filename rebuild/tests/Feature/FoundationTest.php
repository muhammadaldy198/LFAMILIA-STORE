<?php

namespace Tests\Feature;

use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FoundationTest extends TestCase
{
    public function test_home_uses_the_new_inertia_page(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Catalog/Index'));
    }

    public function test_readiness_requires_mysql_and_redis(): void
    {
        $this->get('/health/ready')->assertOk()->assertJson(['status' => 'healthy']);
    }
}
