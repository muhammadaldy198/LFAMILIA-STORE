<?php

namespace App\Services;

final class DiscordWebhookUrl
{
    public static function valid(string $url): bool
    {
        $parts = parse_url(trim($url));
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
        ) {
            return false;
        }

        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        if (! in_array($host, ['discord.com', 'www.discord.com', 'discordapp.com', 'www.discordapp.com'], true)) {
            return false;
        }

        return (bool) preg_match('#^/api(?:/v\d+)?/webhooks/[^/]+/[^/]+/?$#', (string) ($parts['path'] ?? ''));
    }
}
