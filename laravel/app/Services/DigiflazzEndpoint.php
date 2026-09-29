<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class DigiflazzEndpoint
{
    public static function request(): PendingRequest
    {
        // Keep provider traffic on this VPS, even if a proxy is set in the process environment.
        return Http::acceptJson()->withoutRedirecting()->withOptions(['proxy' => '']);
    }

    public static function requireOfficial(string $url, string $path): string
    {
        $parts = parse_url($url);
        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || strtolower((string) ($parts['host'] ?? '')) !== 'api.digiflazz.com'
            || ($parts['path'] ?? '') !== $path
            || isset($parts['port'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            throw new RuntimeException('URL DigiFlazz harus https://api.digiflazz.com'.$path.'.');
        }

        return 'https://api.digiflazz.com'.$path;
    }
}
