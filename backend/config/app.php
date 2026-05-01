<?php

return [
    'name'      => env('APP_NAME', 'ATLAS'),
    'env'       => env('APP_ENV', 'production'),
    'debug'     => (bool) env('APP_DEBUG', false),
    'url'       => env('APP_URL', 'http://localhost'),
    'asset_url' => env('ASSET_URL'),
    'timezone'  => 'America/Argentina/Buenos_Aires',
    'locale'    => 'es',
    'fallback_locale' => 'en',
    'faker_locale'    => 'es_AR',
    'cipher'    => 'AES-256-CBC',
    'key'       => env('APP_KEY'),
    'previous_keys' => array_filter(explode(',', env('APP_PREVIOUS_KEYS', ''))),
    'maintenance' => [
        'driver' => 'file',
    ],
];
