<?php

$environment = env('VTPASS_ENVIRONMENT', 'sandbox');

$connections = [
    'sandbox' => [
        'base_url' => 'https://sandbox.vtpass.com/api',
        'api_key' => env('VTPASS_SANDBOX_API_KEY'),
        'public_key' => env('VTPASS_SANDBOX_PUBLIC_KEY'),
        'secret_key' => env('VTPASS_SANDBOX_SECRET_KEY'),
    ],

    'live' => [
        'base_url' => 'https://vtpass.com/api',
        'api_key' => env('VTPASS_LIVE_API_KEY'),
        'public_key' => env('VTPASS_LIVE_PUBLIC_KEY'),
        'secret_key' => env('VTPASS_LIVE_SECRET_KEY'),
    ],
];

return [
    'environment' => $environment,

    // An invalid environment has no connection.
    // The service will reject it rather than silently switch.
    'base_url' => $connections[$environment]['base_url'] ?? null,
    'api_key' => $connections[$environment]['api_key'] ?? null,
    'public_key' => $connections[$environment]['public_key'] ?? null,
    'secret_key' => $connections[$environment]['secret_key'] ?? null,

    'connect_timeout' => 10,
    'timeout' => 30,

    'networks' => [
        'mtn' => [
            'name' => 'MTN',
            'service_id' => 'mtn-data',
        ],

        'airtel' => [
            'name' => 'Airtel',
            'service_id' => 'airtel-data',
        ],

        'glo' => [
            'name' => 'Glo',
            'service_id' => 'glo-data',
        ],
    ],

    // Retained only for the original vtpass:plans terminal test.
    // Actual campaign rewards are stored in MySQL.
    'reward_plans' => [
        'mtn' => [
            'service_id' => 'mtn-data',
            'variation_code' => 'mtn-10mb-100',
        ],

        'airtel' => [
            'service_id' => 'airtel-data',
            'variation_code' => 'airt-100',
        ],

        'glo' => [
            'service_id' => 'glo-data',
            'variation_code' => 'glo-dg-70',
        ],
    ],
];