<?php

namespace App\Services;

/**
 * HTTP integration fixtures use example.com; their DNS must also be offline.
 * Other hosts still use the real resolver, including SSRF rejection tests.
 */
function dns_get_record(string $hostname, int $type = DNS_ANY): array|false
{
    if ($hostname === 'example.com') {
        return [['host' => $hostname, 'type' => 'A', 'ip' => '93.184.216.34']];
    }

    return \dns_get_record($hostname, $type);
}
