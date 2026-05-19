<?php

return [
    'default' => env('FILESYSTEM_DISK', 'local'),

    'disks' => [
        // Disco "local" — privado, accesible solo desde el backend.
        // Persistido por el volumen Docker `backend_storage` montado en /var/www/html/storage.
        'local' => [
            'driver' => 'local',
            'root'   => storage_path('app/private'),
            'serve'  => true,
            'throw'  => false,
        ],

        // Disco público (no se usa en ATLAS pero queda para compatibilidad).
        'public' => [
            'driver'     => 'local',
            'root'       => storage_path('app/public'),
            'url'        => env('APP_URL') . '/storage',
            'visibility' => 'public',
            'throw'      => false,
        ],
    ],

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],
];
