<?php

use Danielgnh\StatamicMcp\Middleware\AuthenticateMcpToken;
use Danielgnh\StatamicMcp\Tests\UsesStringMiddlewareConfig;
use Illuminate\Support\Facades\Route;

uses(UsesStringMiddlewareConfig::class);

it('still mounts an authenticated route when middleware config is a string', function () {
    $route = collect(Route::getRoutes()->getRoutes())
        ->first(fn ($route) => $route->uri() === 'mcp/statamic'
            && in_array('POST', $route->methods(), true));

    // Pins the fail-closed fix: a config-shape mistake must never leave the
    // route unregistered (kill switch untouched) or, worse, unauthenticated.
    expect($route)->not->toBeNull();

    expect(app('router')->gatherRouteMiddleware($route))
        ->toContain(AuthenticateMcpToken::class);
});
