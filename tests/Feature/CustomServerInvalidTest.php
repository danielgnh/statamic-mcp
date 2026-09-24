<?php

use Danielgnh\StatamicMcp\Tests\UsesInvalidServer;
use Illuminate\Support\Facades\Route;

uses(UsesInvalidServer::class);

it('fails closed when the configured server is not a laravel/mcp server', function () {
    $route = collect(Route::getRoutes()->getRoutes())
        ->first(fn ($route) => $route->uri() === 'mcp/statamic');

    // The provider throws before Mcp::web() registers anything, so a typo in
    // 'server' leaves no route at all — never one that 500s on every request.
    expect($route)->toBeNull();

    $this->artisan('statamic:mcp:doctor')
        ->expectsOutputToContain("[FAIL] Configured server 'stdClass' is not a subclass of Laravel\\Mcp\\Server")
        ->expectsOutputToContain('[FAIL] MCP is enabled but its route is not mounted')
        ->assertExitCode(1);
});
