<?php

return [
    'default' => env('LDAP_CONNECTION', 'default'),

    'connections' => [
        'default' => [
            'hosts' => [env('LDAP_HOST', '127.0.0.1')],
            'username' => env('LDAP_USERNAME'),
            'password' => env('LDAP_PASSWORD'),
            // LDAPS por defecto: el bind viaja cifrado. Un despliegue que
            // todavía no tenga certificado puede bajar a 389 por .env, pero es
            // una excepción y expone las credenciales en la red.
            'port'     => env('LDAP_PORT', 636),
            'base_dn'  => env('LDAP_BASE_DN'),
            'timeout'  => env('LDAP_TIMEOUT', 5),
            'use_ssl'  => filter_var(env('LDAP_USE_SSL', true), FILTER_VALIDATE_BOOLEAN),
            'use_tls'  => filter_var(env('LDAP_USE_TLS', false), FILTER_VALIDATE_BOOLEAN),
            'options'  => [
                LDAP_OPT_REFERRALS    => 0,
                LDAP_OPT_PROTOCOL_VERSION => 3,
                LDAP_OPT_NETWORK_TIMEOUT  => 5,
            ],
        ],
    ],

    'logging' => [
        'enabled' => env('LDAP_LOGGING', true),
        'channel' => env('LOG_CHANNEL', 'stack'),
        'level'   => env('LOG_LEVEL', 'info'),
    ],

    'cache' => [
        'enabled' => env('LDAP_CACHE', false),
        'driver'  => env('CACHE_DRIVER', 'file'),
    ],

    // Atributo para identificar al usuario en el AD
    'user_attribute' => env('LDAP_USER_ATTRIBUTE', 'sAMAccountName'),
];
