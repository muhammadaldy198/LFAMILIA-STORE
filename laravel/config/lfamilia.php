<?php

return [
    'public_base_url' => env('PUBLIC_BASE_URL', env('APP_URL')),

    'integrations' => [
        'kokinpay' => [
            'base_url' => env('KOKINPAY_BASE_URL', 'https://api.kokinpay.com'),
            'api_key' => env('KOKINPAY_API_KEY'),
        ],
        'digiflazz' => [
            'username' => env('DIGIFLAZZ_USERNAME'),
            'api_key' => env('DIGIFLAZZ_API_KEY'),
            'webhook_secret' => env('DIGIFLAZZ_WEBHOOK_SECRET'),
        ],
        'midtrans' => [
            'environment' => env('MIDTRANS_ENV', 'production'),
            'server_key' => env('MIDTRANS_SERVER_KEY'),
            'client_key' => env('MIDTRANS_CLIENT_KEY'),
            'snap_base_url' => env('MIDTRANS_SNAP_BASE_URL', 'https://app.midtrans.com'),
            'api_base_url' => env('MIDTRANS_API_BASE_URL', 'https://api.midtrans.com'),
        ],
        'doku' => [
            'environment' => env('DOKU_ENV', 'production'),
            'client_id' => env('DOKU_CLIENT_ID'),
            'secret_key' => env('DOKU_SECRET_KEY'),
            'api_base_url' => env('DOKU_API_BASE_URL', 'https://api.doku.com'),
        ],
        'resend' => [
            'api_key' => env('RESEND_API_KEY'),
        ],
    ],

    // This key is never committed with a value. It protects secrets that are
    // stored through the Super Admin integration manager.
    'integration_encryption_key' => env('INTEGRATION_ENCRYPTION_KEY'),
];
