<?php

$trustedHosts = array_values(array_filter(array_map(
    fn (string $host): string => strtolower(trim($host)),
    explode(',', (string) env('APP_TRUSTED_HOSTS', ''))
)));

$trustedProxiesRaw = trim((string) env('TRUSTED_PROXIES', ''));
$trustedProxies = $trustedProxiesRaw === '*'
    ? '*'
    : array_values(array_filter(array_map(
        fn (string $proxy): string => trim($proxy),
        explode(',', $trustedProxiesRaw)
    )));

return [
    'checkout_reservation_minutes' => (int) env('CHECKOUT_RESERVATION_MINUTES', 30),
    'trusted_hosts' => $trustedHosts,
    'trusted_proxies' => $trustedProxies,
];
