<?php

use Illuminate\Support\Str;

return [
    'driver'   => env('SESSION_DRIVER', 'file'),
    'lifetime' => env('SESSION_LIFETIME', 120),
    'expire_on_close' => false,
    'encrypt'  => false,
    'files'    => storage_path('framework/sessions'),
    'cookie'   => env('SESSION_COOKIE', Str::slug(env('APP_NAME', 'atlas'), '_').'_session'),
    'path'     => '/',
    'domain'   => env('SESSION_DOMAIN'),
    'secure'   => env('SESSION_SECURE_COOKIE'),
    'http_only' => true,
    'same_site' => 'lax',
];
