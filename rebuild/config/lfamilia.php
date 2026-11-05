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
    // Internal H2H nickname-check service (own machine, same VPS, loopback
    // only). The key is provisioned in the server .env, never in the panel.
    // Empty key = H2H disabled; NicknameService falls back to the legacy
    // provider configuration.
    'h2h' => [
        // No default: the loopback base URL is set explicitly in the
        // server .env (H2H_BASE_URL) together with the key.
        'base_url' => rtrim((string) env('H2H_BASE_URL', ''), '/'),
        'api_key' => (string) env('H2H_API_KEY', ''),
        'timeout' => max(1, (int) env('H2H_TIMEOUT', 6)),
    ],
    'trusted_hosts' => $trustedHosts,
    'trusted_proxies' => $trustedProxies,
];
