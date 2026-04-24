<?php

return [
    'token_ttl_seconds' => (int) env('CALCULATOR_WS_TOKEN_TTL', 3600),
    'default_section_name' => env('CALCULATOR_DEFAULT_SECTION_NAME', 'Kundalik harajatlarim'),

    'websocket' => [
        'scheme' => env('CALCULATOR_WS_SCHEME', 'ws'),
        'host' => env('CALCULATOR_WS_HOST', '127.0.0.1'),
        'port' => (int) env('CALCULATOR_WS_PORT', 8090),
        'path' => env('CALCULATOR_WS_PATH', '/calculator'),
        'client_url' => env('CALCULATOR_WS_CLIENT_URL'),
    ],
];
