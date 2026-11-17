<?php

return [
    'database_path' => storage_path(
        'app/geoip/dbip-country-lite-2026-11.mmdb'
    ),

    // Used only when APP_ENV is local or testing.
    'local_override' => env('REWARDS_LOCAL_COUNTRY'),
];