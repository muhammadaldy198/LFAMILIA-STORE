<?php

$routes = [
    [
        'channel' => 'qris',
        'gateway' => 'MIDTRANS',
        'provider_channel' => null,
        'configuration' => [
            'enabled_payments' => ['other_qris'],
        ],
        'priority' => 10,
        'supports_order' => true,
        'supports_wallet_topup' => true,
    ],
    [
        'channel' => 'virtual_account',
        'gateway' => 'MIDTRANS',
        'provider_channel' => null,
        'configuration' => [
            'enabled_payments' => ['bni_va', 'bri_va', 'echannel', 'cimb_va', 'permata_va', 'bsi_va'],
        ],
        'priority' => 10,
        'supports_order' => true,
        'supports_wallet_topup' => true,
    ],
    [
        'channel' => 'ewallet',
        'gateway' => 'MIDTRANS',
        'provider_channel' => null,
        'configuration' => [
            'enabled_payments' => ['gopay'],
        ],
        'priority' => 10,
        'supports_order' => true,
        'supports_wallet_topup' => true,
    ],
    [
        'channel' => 'virtual_account',
        'gateway' => 'DOKU',
        'provider_channel' => 'DOKU_CHECKOUT',
        'configuration' => [
            'api_path' => '/checkout/v1/payment',
            'public_paths' => [
                'payment_url' => 'response.payment.url',
            ],
        ],
        'priority' => 20,
        'supports_order' => true,
        'supports_wallet_topup' => true,
    ],
];

// Each bank gets an independent route; the existing generic VA option remains
// for existing payments and backwards compatibility.
foreach (require __DIR__.'/payment_bank_channels.php' as $bank) {
    $routes[] = [
        'channel' => $bank['code'],
        'gateway' => 'MIDTRANS',
        'provider_channel' => $bank['midtrans'],
        'configuration' => ['enabled_payments' => [$bank['midtrans']]],
        'priority' => 10,
        'supports_order' => true,
        'supports_wallet_topup' => true,
    ];
    if (empty($bank['doku'])) {
        continue; // BSI is currently enabled on Midtrans only; do not invent a DOKU protocol.
    }
    $routes[] = [
        'channel' => $bank['code'],
        'gateway' => 'DOKU',
        'provider_channel' => 'DOKU_CHECKOUT',
        'configuration' => [
            'api_path' => '/checkout/v1/payment',
            'payment_method_types' => [$bank['doku']],
            'public_paths' => ['payment_url' => 'response.payment.url'],
        ],
        'priority' => 20,
        'supports_order' => true,
        'supports_wallet_topup' => true,
    ];
}

return $routes;
