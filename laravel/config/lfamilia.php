<?php

return [
    'public_base_url' => env('PUBLIC_BASE_URL'),

    'integrations' => [
        'midtrans' => [
            'snap_base_url' => env('MIDTRANS_SNAP_BASE_URL'),
            'api_base_url' => env('MIDTRANS_API_BASE_URL'),
        ],
        'google' => [
            'tokeninfo_url' => env('GOOGLE_TOKENINFO_URL', 'https://oauth2.googleapis.com/tokeninfo'),
        ],
    ],

    'integration_encryption_key' => env('INTEGRATION_ENCRYPTION_KEY'),
];
