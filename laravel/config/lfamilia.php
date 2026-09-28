<?php

return [
    'public_base_url' => env('PUBLIC_BASE_URL'),

    'integrations' => [
        'kokinpay' => [
            'base_url' => env('KOKINPAY_BASE_URL'),
            'api_key' => env('KOKINPAY_API_KEY'),
        ],
        'digiflazz' => [
            'environment' => env('DIGIFLAZZ_ENV'),
            'username' => env('DIGIFLAZZ_USERNAME'),
            'development_api_key' => env('DIGIFLAZZ_DEVELOPMENT_API_KEY'),
            'production_api_key' => env('DIGIFLAZZ_PRODUCTION_API_KEY'),
            'development_transaction_url' => env('DIGIFLAZZ_DEVELOPMENT_API_URL'),
            'production_transaction_url' => env('DIGIFLAZZ_PRODUCTION_API_URL'),
            'development_price_list_url' => env('DIGIFLAZZ_DEVELOPMENT_PRICE_LIST_URL'),
            'production_price_list_url' => env('DIGIFLAZZ_PRODUCTION_PRICE_LIST_URL'),
            'webhook_secret' => env('DIGIFLAZZ_WEBHOOK_SECRET', env('DIGIFLAZZ_CALLBACK_SECRET')),
        ],
        'midtrans' => [
            'environment' => env('MIDTRANS_ENV'),
            'server_key' => env('MIDTRANS_SERVER_KEY'),
            'client_key' => env('MIDTRANS_CLIENT_KEY'),
            'snap_base_url' => env('MIDTRANS_SNAP_BASE_URL'),
            'api_base_url' => env('MIDTRANS_API_BASE_URL'),
        ],
        'doku' => [
            'environment' => env('DOKU_ENV'),
            'client_id' => env('DOKU_CLIENT_ID'),
            'secret_key' => env('DOKU_SECRET_KEY'),
            'api_base_url' => env('DOKU_API_BASE_URL'),
        ],
        'resend' => [
            'api_key' => env('RESEND_API_KEY'),
            'from' => env('RESEND_FROM_EMAIL', env('RESEND_FROM')),
            'api_url' => env('RESEND_API_URL', env('RESEND_BASE_URL')),
        ],
        'google' => [
            'client_id' => env('GOOGLE_OAUTH_CLIENT_ID', env('GOOGLE_CLIENT_ID')),
            'client_secret' => env('GOOGLE_CLIENT_SECRET'),
            'redirect_uri' => env('GOOGLE_REDIRECT_URI'),
            'tokeninfo_url' => env('GOOGLE_TOKENINFO_URL'),
        ],
        'turnstile' => [
            'site_key' => env('TURNSTILE_SITE_KEY'),
            'secret_key' => env('TURNSTILE_SECRET_KEY'),
            'verify_url' => env('TURNSTILE_VERIFY_URL'),
        ],
        'voucher' => [
            'encryption_key' => env('VOUCHER_ENCRYPTION_KEY'),
        ],
    ],

    'integration_encryption_key' => env('INTEGRATION_ENCRYPTION_KEY'),
];
