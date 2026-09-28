<?php

namespace Tests;

use App\Services\IntegrationConfigService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** @param array<string,string> $values */
    protected function saveIntegrationProfile(string $provider, string $mode, string $environment, array $values): void
    {
        config()->set('lfamilia.integration_encryption_key', str_repeat('t', 32));
        $service = app(IntegrationConfigService::class);
        if (in_array($provider, ['doku', 'midtrans'], true)) {
            $service->savePaymentProfile($provider, $environment, $values);
            return;
        }
        $service->saveIntegrationProfile($provider, $mode, $environment, $values);
    }

    protected function saveIntegrationSetting(string $key, string $value): void
    {
        app(IntegrationConfigService::class)->saveSetting($key, $value);
    }
}
