<?php

declare(strict_types=1);

return [
    // Kill switch. When false the MCP route is never registered.
    'enabled' => env('STATAMIC_MCP_ENABLED', true),

    // Where Mcp::web() mounts the streamable-HTTP endpoint.
    'route' => 'mcp/statamic',

    // 'token' — addon-issued tokens (file or Eloquent users): php please mcp:token you@site.com
    // 'oauth' — Laravel Passport via laravel/mcp, for claude.ai/ChatGPT connectors.
    //           Requires Eloquent users + laravel/passport. See README.
    'auth' => env('STATAMIC_MCP_AUTH', 'token'),

    // Prepended to the auth middleware on the MCP route. Plain Laravel.
    'middleware' => ['throttle:60,1'],

    // Hide every write/delete tool from the server entirely.
    'read_only' => env('STATAMIC_MCP_READ_ONLY', false),

    // Delete tools are not even registered unless true.
    'deletes' => env('STATAMIC_MCP_DELETES', false),

    // What exists as far as MCP is concerned. true = all handles, or an
    // array of handles: 'collections' => ['blog', 'pages'].
    // NOTE: this controls EXPOSURE only. Who may read/write what is decided
    // by the connected user's Statamic roles & permissions — nothing here.
    'resources' => [
        'collections' => true,
        'taxonomies' => true,
        'globals' => true,
        'asset_containers' => true,
    ],

    // Default page size for list tools (hard-capped at 100 in code).
    'per_page' => 25,

    'uploads' => [
        // Hard per-upload cap in kilobytes, for both source_url downloads
        // and decoded content_base64. Container validation rules still
        // apply on top.
        'max_size' => 10240,

        // Exact-host allowlist for assets_upload source_url. null = any
        // public host. Private/reserved/loopback IPs are ALWAYS blocked.
        'source_allowlist' => null,
    ],
];
