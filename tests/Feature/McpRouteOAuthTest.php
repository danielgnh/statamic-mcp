<?php

use Danielgnh\StatamicMcp\Middleware\AuthenticateOAuth;
use Danielgnh\StatamicMcp\Middleware\EnsureMcpPermission;
use Danielgnh\StatamicMcp\Tests\Support\OAuthFixtures;
use Danielgnh\StatamicMcp\Tests\UsesOAuthMode;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

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

it('throttles anonymous client registration per address', function () {
    OAuthFixtures::migratePassport();

    $codes = collect(range(1, 61))->map(fn (int $i) => $this->postJson('/oauth/register', [
        'client_name' => 'Client '.$i,
        'redirect_uris' => ['https://client.example.com/callback'],
    ])->getStatusCode());

    expect($codes->countBy()->all())->toBe([201 => 60, 429 => 1])
        ->and(Passport::client()->newQuery()->count())->toBe(60);
});

it('answers the mounted endpoint with a 503 during a database outage, without connection details or a remedy that rotates keys', function () {
    $keyDir = sys_get_temp_dir().'/mcp-outage-'.Str::random(8);
    mkdir($keyDir, 0700, true);
    Passport::loadKeysFrom($keyDir);

    config(['passport.private_key' => null, 'passport.public_key' => null, 'database.default' => 'unconfigured']);

    Exceptions::fake();

    $this->withHeaders(['Authorization' => 'Bearer anything', 'Accept' => 'application/json, text/event-stream'])
        ->postJson('/mcp/statamic', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
        ->assertStatus(503)
        ->assertHeader('Retry-After', '60')
        ->assertJsonPath('error', "MCP can't reach its database.")
        ->assertDontSee('unconfigured')
        ->assertDontSee('migrate')
        ->assertDontSee('mcp:keys');

    Passport::$keyPath = null;
    rmdir($keyDir);
});
