<?php

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\Addon\AddonNotReadOnlyTool;
use Danielgnh\StatamicMcp\Tests\Support\Addon\AddonReadTool;
use Danielgnh\StatamicMcp\Tests\Support\Addon\AddonWriteTool;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tests\UsesSiteServer;
use Danielgnh\StatamicMcp\Tokens\TokenRepository;
use Illuminate\Testing\TestResponse;

uses(UsesSiteServer::class);

beforeEach(function () {
    Server::addTools(AddonReadTool::class, AddonWriteTool::class, AddonNotReadOnlyTool::class);
});

function siteServerList(): TestResponse
{
    $token = app(TokenRepository::class)->issue(Fixtures::makeUser(), 'site')->token;

    return test()->withHeaders([
        'Authorization' => 'Bearer '.$token,
        'Accept' => 'application/json, text/event-stream',
    ])->postJson('/mcp/statamic', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/list',
        'params' => (object) [],
    ])->assertOk();
}

it("lets the site server's tools() remove an add-on tool", function () {
    $names = collect(siteServerList()->json('result.tools'))->pluck('name');

    expect($names)->toContain('addon_read', 'site_write')
        ->not->toContain('addon_not_read_only', 'addon_write');
});

it("applies read_only after the site server's tools(), so its replace() of an add-on write tool keeps working", function () {
    config(['statamic.mcp.read_only' => true]);

    $names = collect(siteServerList()->json('result.tools'))->pluck('name');

    expect($names)->toContain('addon_read', 'site_write')->not->toContain('addon_write');
});

it('serves every tool on the first page, because some clients never send a cursor', function () {
    $response = siteServerList();

    expect(count($response->json('result.tools')))->toBeGreaterThan(10)
        ->and($response->json('result.nextCursor'))->toBeNull();
});
