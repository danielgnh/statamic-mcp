<?php

use Danielgnh\StatamicMcp\Middleware\AuthenticateMcpToken;
use Danielgnh\StatamicMcp\Middleware\EnsureMcpPermission;
use Illuminate\Support\Facades\RateLimiter;
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
    // Assert the RESOLVED pipeline (post-SortedMiddleware), not the declared
    // array — declared order passing means nothing if Laravel's middleware
    // priority hoists an entry at runtime.
    $resolved = app('router')->gatherRouteMiddleware(mcpPostRoute());

    // spec §5: configured middleware runs BEFORE auth; 'access mcp' is checked AFTER auth.
    expect(array_slice($resolved, -2))->toBe([
        AuthenticateMcpToken::class,
        EnsureMcpPermission::class,
    ]);

    $throttleIndex = collect($resolved)->search(
        fn ($middleware) => is_string($middleware) && str_ends_with($middleware, ':60,1'),
    );

    expect($throttleIndex)->not->toBeFalse()
        ->and($throttleIndex)->toBeLessThan(array_search(AuthenticateMcpToken::class, $resolved, true));
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

it('counts every anonymous request against the throttle, also one laravel/mcp refuses for its headers', function () {
    config(['cache.default' => 'array']);

    $mismatch = fn () => $this->withHeaders([
        'Accept' => 'application/json, text/event-stream',
        'MCP-Protocol-Version' => '1999-01-01',
    ])->postJson('/mcp/statamic', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => (object) []]);

    $mismatch()->assertStatus(400);
    $this->get('/mcp/statamic')->assertStatus(405)->assertHeader('Allow', 'POST');

    expect(RateLimiter::attempts(sha1('|127.0.0.1')))->toBe(2);
});

it('keeps the discovery hint on a 401 now that the throttle runs before the header checks', function () {
    $this->withHeaders(['Accept' => 'application/json, text/event-stream'])
        ->postJson('/mcp/statamic', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate');
});

it('answers a throttled request as JSON, also for a client listing event-stream first', function () {
    config(['cache.default' => 'array']);

    foreach (range(1, 60) as $i) {
        $this->get('/mcp/statamic');
    }

    $this->withHeaders(['Accept' => 'text/event-stream, application/json'])
        ->post('/mcp/statamic', [])
        ->assertTooManyRequests()
        ->assertHeader('Content-Type', 'application/json');
});
