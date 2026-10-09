<?php

return [
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
            'enabled_payments' => ['bank_transfer'],
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
            'enabled_payments' => ['gopay', 'shopeepay', 'ovo', 'dana'],
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
