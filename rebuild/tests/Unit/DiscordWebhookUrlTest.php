<?php

namespace Tests\Unit;

use App\Services\DiscordWebhookUrl;
use PHPUnit\Framework\TestCase;

class DiscordWebhookUrlTest extends TestCase
{
    public function test_allows_only_official_https_discord_webhook_hosts_and_paths(): void
    {
        $this->assertTrue(DiscordWebhookUrl::valid('https://discord.com/api/webhooks/123/abc'));
        $this->assertTrue(DiscordWebhookUrl::valid('https://discord.com/api/v10/webhooks/123/abc'));
        $this->assertFalse(DiscordWebhookUrl::valid('http://discord.com/api/webhooks/123/abc'));
        $this->assertFalse(DiscordWebhookUrl::valid('https://discord.com.evil.example/api/webhooks/123/abc'));
        $this->assertFalse(DiscordWebhookUrl::valid('https://discord.com@evil.example/api/webhooks/123/abc'));
        $this->assertFalse(DiscordWebhookUrl::valid('https://discord.com/api/users/@me'));
    }
}
