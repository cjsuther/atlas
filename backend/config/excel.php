<?php

return [
    'exports' => [
        'chunk_size' => 1000,
        'pre_calculate_formulas' => false,
        'strict_null_comparison' => false,
        'csv' => [
            'delimiter' => ';',
            'enclosure' => '"',
            'line_ending' => PHP_EOL,
            'use_bom' => true,
            'output_encoding' => 'UTF-8',
        ],
    ],
    'imports' => [
        'read_only' => true,
    ],
    'temporary_files' => [
        'local_path' => storage_path('framework/cache/laravel-excel'),
    ],
    'cache' => [
        'driver' => 'memory',
    ],
];
