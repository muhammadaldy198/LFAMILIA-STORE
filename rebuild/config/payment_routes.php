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
        'channel' => 'qris',
        'gateway' => 'DOKU',
        'provider_channel' => 'DOKU_CHECKOUT',
        'configuration' => [
            'api_path' => '/checkout/v1/payment',
            'payment_method_types' => ['QRIS'],
            'public_paths' => ['payment_url' => 'response.payment.url'],
        ],
        'priority' => 20,
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

// Individually selectable methods. Newly created customer-facing methods stay
// inactive until an operator explicitly enables the merchant-supported gateway.
foreach (require __DIR__.'/payment_ewallet_channels.php' as $wallet) {
    if (! empty($wallet['midtrans'])) {
        $routes[] = [
            'channel' => $wallet['code'],
            'gateway' => 'MIDTRANS',
            'provider_channel' => $wallet['midtrans'],
            'configuration' => ['enabled_payments' => [$wallet['midtrans']]],
            'priority' => 10,
            'supports_order' => true,
            'supports_wallet_topup' => true,
        ];
    }
    if (! empty($wallet['doku']) && empty($wallet['requires_integration'])) {
        $routes[] = [
            'channel' => $wallet['code'],
            'gateway' => 'DOKU',
            'provider_channel' => 'DOKU_CHECKOUT',
            'configuration' => [
                'api_path' => '/checkout/v1/payment',
                'payment_method_types' => [$wallet['doku']],
                'public_paths' => ['payment_url' => 'response.payment.url'],
            ],
            'priority' => 20,
            'supports_order' => true,
            'supports_wallet_topup' => true,
        ];
    }
}

// Every source-supported bank has a route only at its documented provider.
foreach (require __DIR__.'/payment_bank_channels.php' as $bank) {
    if (! empty($bank['midtrans'])) {
        $routes[] = [
            'channel' => $bank['code'],
            'gateway' => 'MIDTRANS',
            'provider_channel' => $bank['midtrans'],
            'configuration' => ['enabled_payments' => [$bank['midtrans']]],
            'priority' => 10,
            'supports_order' => true,
            'supports_wallet_topup' => true,
        ];
    }
    if (! empty($bank['doku'])) {
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
}

foreach (require __DIR__.'/payment_retail_channels.php' as $store) {
    $routes[] = [
        'channel' => $store['code'],
        'gateway' => 'MIDTRANS',
        'provider_channel' => $store['midtrans'],
        'configuration' => ['enabled_payments' => [$store['midtrans']]],
        'priority' => 10,
        'supports_order' => true,
        'supports_wallet_topup' => true,
    ];
    $routes[] = [
        'channel' => $store['code'],
        'gateway' => 'DOKU',
        'provider_channel' => 'DOKU_CHECKOUT',
        'configuration' => [
            'api_path' => '/checkout/v1/payment',
            'payment_method_types' => [$store['doku']],
            'public_paths' => ['payment_url' => 'response.payment.url'],
        ],
        'priority' => 20,
        'supports_order' => true,
        'supports_wallet_topup' => true,
    ];
}

return $routes;
