<?php

use Danielgnh\StatamicMcp\Tests\CachesRoutes;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tokens\TokenRepository;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;

uses(CachesRoutes::class);

function initializeWithStaticToken(string $token): TestResponse
{
    return test()->withHeaders([
        'Authorization' => "Bearer {$token}",
        'Accept' => 'application/json, text/event-stream',
    ])->postJson('/mcp/statamic', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2025-11-25',
            'capabilities' => (object) [],
            'clientInfo' => ['name' => 'pest', 'version' => '1.0.0'],
        ],
    ]);
}

beforeEach(function () {
    config(['statamic.editions.pro' => true, 'cache.default' => 'array']);
    File::delete(storage_path('statamic/mcp/tokens.yaml'));
});

it('answers no mcp request once the kill switch is off, even with routes cached while it was on', function () {
    $token = app(TokenRepository::class)->issue(Fixtures::makeUser(), 'agent')->token;

    $this->cacheRoutes();
    config(['statamic.mcp.enabled' => false]);

    initializeWithStaticToken($token)->assertNotFound();
});

it('serves no tools → mcp page once the kill switch is off, even with routes cached while it was on', function () {
    $user = Fixtures::makeUser('access cp');

    $this->cacheRoutes();
    config(['statamic.mcp.enabled' => false]);

    $this->actingAs($user)->getJson('/cp/mcp/connections')->assertNotFound();
    $this->actingAs($user)->getJson('/cp/mcp/guidelines')->assertNotFound();
    $this->actingAs($user)
        ->postJson('/cp/mcp/connections/tokens', ['name' => 'agent', 'expiry' => '30'])
        ->assertNotFound();

    expect(app(TokenRepository::class)->all())->toBeEmpty();
});

it('refuses static tokens once the auth mode is oauth, even with routes cached in token mode', function () {
    $token = app(TokenRepository::class)->issue(Fixtures::makeUser(), 'agent')->token;

    $this->cacheRoutes();
    config(['statamic.mcp.auth' => 'oauth']);

    initializeWithStaticToken($token)->assertNotFound();
});

it('keeps answering with routes cached while nothing changed', function () {
    $token = app(TokenRepository::class)->issue(Fixtures::makeUser(), 'agent')->token;

    $this->cacheRoutes();

    initializeWithStaticToken($token)->assertOk();
});
