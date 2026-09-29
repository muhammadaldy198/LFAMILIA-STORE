<?php

$trustedHosts = array_values(array_filter(array_map(
    fn (string $host): string => strtolower(trim($host)),
    explode(',', (string) env('APP_TRUSTED_HOSTS', ''))
)));

return [
    'checkout_reservation_minutes' => (int) env('CHECKOUT_RESERVATION_MINUTES', 30),
    'trusted_hosts' => $trustedHosts,
];
