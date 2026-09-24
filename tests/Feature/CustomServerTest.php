<?php

use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tests\UsesCustomServer;
use Danielgnh\StatamicMcp\Tokens\TokenRepository;
use Illuminate\Testing\TestResponse;

uses(UsesCustomServer::class);

function customServerPost(array $payload, string $token): TestResponse
{
    return test()->withHeaders([
        'Authorization' => 'Bearer '.$token,
        'Accept' => 'application/json, text/event-stream',
    ])->postJson('/mcp/statamic', $payload);
}

function customServerInitialize(string $token): TestResponse
{
    return customServerPost([
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2025-11-25',
            'capabilities' => (object) [],
            'clientInfo' => ['name' => 'pest', 'version' => '1.0.0'],
        ],
    ], $token)->assertOk();
}

it('adds, replaces and removes tools through the tools() hook', function () {
    $user = Fixtures::makeUser();
    $token = app(TokenRepository::class)->issue($user, 'custom')->token;

    customServerInitialize($token);

    $response = customServerPost([
        'jsonrpc' => '2.0',
        'id' => 2,
        'method' => 'tools/list',
        'params' => (object) [],
    ], $token)->assertOk();

    $tools = collect($response->json('result.tools'));
    $names = $tools->pluck('name');

    expect($names)->toContain('echo_user', 'statamic_overview', 'globals_update')
        ->and($names)->not->toContain('assets_upload')
        ->and($tools->where('name', 'entries_list'))->toHaveCount(1)
        ->and($tools->firstWhere('name', 'entries_list')['description'])->toBe('Overridden by the host app.');
});

it('keeps the server name and instructions on a subclass', function () {
    $user = Fixtures::makeUser();
    $token = app(TokenRepository::class)->issue($user, 'custom')->token;

    $response = customServerInitialize($token);

    // The feature depends on laravel/mcp resolving class attributes up the
    // parent chain — pin it, so an upstream change surfaces here and not as
    // every host subclass silently becoming "Laravel MCP Server".
    expect($response->json('result.serverInfo.name'))->toBe('Statamic')
        ->and($response->json('result.instructions'))->toContain('Call statamic_overview first');
});

it('runs a host-app tool through the auth pipeline with the acting user', function () {
    Fixtures::site();

    $user = Fixtures::makeUser();
    $token = app(TokenRepository::class)->issue($user, 'custom')->token;

    customServerInitialize($token);

    $response = customServerPost([
        'jsonrpc' => '2.0',
        'id' => 3,
        'method' => 'tools/call',
        'params' => ['name' => 'echo_user', 'arguments' => (object) []],
    ], $token)->assertOk();

    expect($response->json('error'))->toBeNull()
        ->and(data_get($response->json(), 'result.isError'))->not->toBeTrue()
        ->and(data_get($response->json(), 'result.content.0.text'))
        ->toContain('"email":"'.$user->email().'"');
});
