<?php

use Danielgnh\StatamicMcp\Middleware\AuthenticateMcpToken;
use Danielgnh\StatamicMcp\Middleware\EnsureMcpPermission;
use Illuminate\Support\Facades\Route;

function mcpPostRoute(): ?Illuminate\Routing\Route
{
    return collect(Route::getRoutes()->getRoutes())
        ->first(fn ($route) => $route->uri() === 'mcp/statamic'
            && in_array('POST', $route->methods(), true));
}

it('registers the mcp route when enabled', function () {
    expect(mcpPostRoute())->not->toBeNull();
});

it('applies configured middleware, then token auth, then the permission gate', function () {
    $middleware = mcpPostRoute()->middleware();

    expect($middleware)->toContain('throttle:60,1')
        ->toContain(AuthenticateMcpToken::class)
        ->toContain(EnsureMcpPermission::class);

    // spec §5: configured middleware is PREPENDED to auth; 'access mcp' is checked AFTER auth.
    expect(array_search('throttle:60,1', $middleware, true))
        ->toBeLessThan(array_search(AuthenticateMcpToken::class, $middleware, true));

    expect(array_search(AuthenticateMcpToken::class, $middleware, true))
        ->toBeLessThan(array_search(EnsureMcpPermission::class, $middleware, true));
});

it('merges default config under statamic.mcp', function () {
    expect(config('statamic.mcp.enabled'))->toBeTrue()
        ->and(config('statamic.mcp.route'))->toBe('mcp/statamic')
        ->and(config('statamic.mcp.auth'))->toBe('token')
        ->and(config('statamic.mcp.read_only'))->toBeFalse()
        ->and(config('statamic.mcp.deletes'))->toBeFalse()
        ->and(config('statamic.mcp.resources.collections'))->toBeTrue()
        ->and(config('statamic.mcp.per_page'))->toBe(25);
});
