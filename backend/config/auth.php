<?php

return [
    'defaults' => [
        'guard'     => 'sanctum',
        'passwords' => 'users',
    ],

    'guards' => [
        'web' => [
            'driver'   => 'session',
            'provider' => 'users',
        ],
        'sanctum' => [
            'driver'   => 'sanctum',
            'provider' => 'users',
        ],
    ],

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model'  => App\Models\UserRole::class,
        ],
    ],

    'passwords' => [],

    'password_timeout' => 10800,
];
