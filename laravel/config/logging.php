<?php

return [
    'default' => env('LOG_CHANNEL', 'daily'),
    'channels' => [
        'daily' => [
            'driver' => 'daily',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'info'),
            'days' => (int) env('LOG_DAILY_DAYS', 14),
            'replace_placeholders' => true,
        ],
        'stderr' => [
            'driver' => 'errorlog',
            'level' => env('LOG_LEVEL', 'info'),
        ],
    ],
];
