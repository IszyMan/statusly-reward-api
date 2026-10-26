<?php

return [
    'device_hash_key' => env('REWARDS_DEVICE_HASH_KEY'),

    'device_token_hours' => 24,

    'requery' => [
        // Maximum transactions checked in one command run.
        'batch_size' => 10,

        // Stop automatic checking after this many attempts.
        'max_attempts' => 12,

        // Allow the initial purchase request time to finish.
        'minimum_age_seconds' => 60,

        // Increasing delays between subsequent checks.
        // After the final value, continue using that delay.
        'delays_seconds' => [
            60,
            120,
            300,
            600,
            1800,
            3600,
        ],
    ],
];