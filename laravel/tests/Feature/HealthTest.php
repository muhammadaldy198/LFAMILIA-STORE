<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HealthTest extends TestCase
{
    use RefreshDatabase;
    public function test_health_endpoint_is_available(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'service' => 'lfamilia-laravel',
            ]);
    }

    public function test_system_status_preserves_storefront_contract_on_vps_runtime(): void
    {
        $this->getJson('/api/system-status')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('runtime', 'laravel')
            ->assertJsonPath('database', 'ok')
            ->assertJsonStructure([
                'services' => [
                    '*' => ['id', 'name', 'state', 'detail'],
                ],
                'merchant' => ['legalName', 'registrationId', 'address'],
                'updatedAt',
            ])
            ->assertJsonCount(4, 'services')
            ->assertJsonPath('services.0.id', 'catalog')
            ->assertJsonPath('services.3.id', 'support');
    }
}
