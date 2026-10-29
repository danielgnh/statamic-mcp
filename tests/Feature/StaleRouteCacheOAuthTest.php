<?php

use Danielgnh\StatamicMcp\Tests\CachesRoutes;
use Danielgnh\StatamicMcp\Tests\Support\OAuthFixtures;
use Danielgnh\StatamicMcp\Tests\UsesOAuthMode;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Passport;

uses(UsesOAuthMode::class, CachesRoutes::class);

beforeEach(function () {
    OAuthFixtures::migratePassport();
});

function registerExampleClient(): TestResponse
{
    return test()->postJson('/oauth/register', [
        'client_name' => 'Example Client',
        'redirect_uris' => ['https://example.com/callback'],
    ]);
}

it('registers no oauth client once the kill switch is off, even with routes cached while it was on', function () {
    $this->cacheRoutes();
    config(['statamic.mcp.enabled' => false]);

    registerExampleClient()->assertNotFound();
    $this->getJson('/.well-known/oauth-authorization-server')->assertNotFound();

    expect(Passport::client()->newQuery()->count())->toBe(0);
});

it('serves neither the oauth routes nor the oauth mcp route once the auth mode is token, even with routes cached in oauth mode', function () {
    $this->cacheRoutes();
    config(['statamic.mcp.auth' => 'token']);

    registerExampleClient()->assertNotFound();
    $this->postJson('/mcp/statamic', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])->assertNotFound();

    expect(Passport::client()->newQuery()->count())->toBe(0);
});

it('keeps registering clients with routes cached while nothing changed', function () {
    $this->cacheRoutes();

    registerExampleClient()->assertCreated();
});
