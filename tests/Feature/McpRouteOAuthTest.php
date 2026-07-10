<?php

use Danielgnh\StatamicMcp\Middleware\AuthenticateOAuth;
use Danielgnh\StatamicMcp\Middleware\EnsureMcpPermission;
use Danielgnh\StatamicMcp\Tests\UsesOAuthMode;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Support\Facades\Route;

uses(UsesOAuthMode::class);

it('authenticates oauth mode through the preflight wrapper, never a bare auth:api', function () {
    $route = collect(Route::getRoutes()->getRoutes())
        ->first(fn ($route) => $route->uri() === 'mcp/statamic'
            && in_array('POST', $route->methods(), true));

    $resolved = app('router')->gatherRouteMiddleware($route);

    expect(array_slice($resolved, -2))->toBe([
        AuthenticateOAuth::class,
        EnsureMcpPermission::class,
    ]);

    // A bare 'auth:api' would be priority-hoisted above the oauth preflight and
    // 500 on a missing api guard — the wrapper must be the only auth entry.
    expect($route->middleware())->not->toContain('auth:api');

    expect(collect($resolved)->contains(
        fn ($middleware) => is_string($middleware) && str_starts_with($middleware, Authenticate::class),
    ))->toBeFalse();
});
