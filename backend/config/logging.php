<?php

use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Processor\PsrLogMessageProcessor;

return [
    'default' => env('LOG_CHANNEL', 'stack'),
    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace'   => false,
    ],

    'channels' => [
        'stack' => [
            'driver' => 'stack',
            'channels' => ['single', 'stderr'],
            'ignore_exceptions' => false,
        ],
        'single' => [
            'driver' => 'single',
            'path'   => storage_path('logs/atlas.log'),
            'level'  => env('LOG_LEVEL', 'info'),
            'replace_placeholders' => true,
        ],
        'daily' => [
            'driver' => 'daily',
            'path'   => storage_path('logs/atlas.log'),
            'level'  => env('LOG_LEVEL', 'info'),
            'days'   => 14,
            'replace_placeholders' => true,
        ],
        'stderr' => [
            'driver' => 'monolog',
            'level'  => env('LOG_LEVEL', 'info'),
            'handler' => StreamHandler::class,
            'formatter' => env('LOG_STDERR_FORMATTER'),
            'with' => [
                'stream' => 'php://stderr',
            ],
            'processors' => [PsrLogMessageProcessor::class],
        ],
        'null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],
        'emergency' => [
            'path' => storage_path('logs/atlas.log'),
        ],
    ],
];
