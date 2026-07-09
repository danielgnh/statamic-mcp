<?php

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tests\Support\GuardProbeTool;
use Danielgnh\StatamicMcp\Tools\StatamicOverview;
use Laravel\Mcp\Request;

it('blocks writes with one canonical message when read_only is on', function () {
    $probe = new GuardProbeTool;

    // enabled: the guard is a no-op
    expect($probe->handle(new Request(['guard' => 'writes']))->isError())->toBeFalse();

    config(['statamic.mcp.read_only' => true]);

    $response = $probe->handle(new Request(['guard' => 'writes']));

    expect($response->isError())->toBeTrue()
        ->and((string) $response->content())
        ->toBe('writes are disabled on this server (statamic.mcp.read_only) — reads remain available');
});

it('blocks deletes with the operative switch named in the message', function () {
    $probe = new GuardProbeTool;

    // deletes are off by default → the deletes switch is the operative one
    $response = $probe->handle(new Request(['guard' => 'deletes']));

    expect($response->isError())->toBeTrue()
        ->and((string) $response->content())
        ->toBe('delete tools are disabled on this server (statamic.mcp.deletes)');

    // read_only trumps deletes → the read_only switch is the operative one
    config(['statamic.mcp.read_only' => true, 'statamic.mcp.deletes' => true]);

    expect((string) $probe->handle(new Request(['guard' => 'deletes']))->content())
        ->toBe('writes are disabled on this server (statamic.mcp.read_only) — reads remain available');

    // both switches open: the guard is a no-op
    config(['statamic.mcp.read_only' => false]);

    expect($probe->handle(new Request(['guard' => 'deletes']))->isError())->toBeFalse();
});

it('returns a tool error instead of a 500 when no user is authenticated', function () {
    Fixtures::site();

    // no actingAs(): the auth manager resolves no user, as in stdio/inspector contexts
    Server::tool(StatamicOverview::class, [])
        ->assertSee('no authenticated user — the MCP server requires token or OAuth authentication; see the README');
});
