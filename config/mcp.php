<?php

declare(strict_types=1);

return [
    'enabled' => env('STATAMIC_MCP_ENABLED', true),
    'route' => 'mcp/statamic',

    'auth' => env('STATAMIC_MCP_AUTH', 'token'),
    'middleware' => ['throttle:60,1'],

    'read_only' => env('STATAMIC_MCP_READ_ONLY', false),
    'deletes' => env('STATAMIC_MCP_DELETES', false),

    'resources' => [
        'collections' => true,
        'taxonomies' => true,
        'globals' => true,
        'asset_containers' => true,
    ],

    'per_page' => 25,

    'uploads' => [
        'max_size' => 10240,
        'source_allowlist' => null,
    ],
];
