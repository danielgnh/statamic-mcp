<?php

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\Addon\AddonReadTool;
use Danielgnh\StatamicMcp\Tests\Support\Addon\AddonWriteTool;
use Danielgnh\StatamicMcp\Tests\Support\Addon\PlainMcpServer;
use Danielgnh\StatamicMcp\Tests\Support\Addon\ShadowOverviewTool;
use Danielgnh\StatamicMcp\Tests\Support\Addon\ThrowingToolsServer;
use Danielgnh\StatamicMcp\Tests\Support\Addon\ToolSearchServer;
use Danielgnh\StatamicMcp\Tests\Support\Addon\UnbuildableTool;
use Danielgnh\StatamicMcp\Tests\Support\Addon\UnregistrableTool;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tokens\TokenRepository;
use Illuminate\Support\Facades\Artisan;

it('lists the tools add-ons registered, by name and class', function () {
    Server::registerAddonTools(AddonReadTool::class);

    Artisan::call('statamic:mcp:doctor');

    expect(Artisan::output())
        ->toContain('tools served, every name unique.')
        ->toContain('Add-on tool addon_read: '.AddonReadTool::class);
});

it('counts the tools that tools/list serves, not the ones a switch hides', function () {
    Server::registerAddonTools(AddonReadTool::class);
    $token = app(TokenRepository::class)->issue(Fixtures::makeUser(), 'doctor')->token;

    $served = count($this->withHeaders([
        'Authorization' => 'Bearer '.$token,
        'Accept' => 'application/json, text/event-stream',
    ])->postJson('/mcp/statamic', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => (object) []])->json('result.tools'));

    Artisan::call('statamic:mcp:doctor');

    expect(Artisan::output())->toContain("[ OK ] {$served} tools served, every name unique.");
});

it('fails on two tools with one name, because tools/call only ever reaches the first', function () {
    Server::registerAddonTools(ShadowOverviewTool::class);

    $exit = Artisan::call('statamic:mcp:doctor');

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain("[FAIL] Tool name 'statamic_overview' is taken by Danielgnh\\StatamicMcp\\Tools\\StatamicOverview and ".ShadowOverviewTool::class);
});

it('fails naming a tool that cannot be built, because it breaks every tools/list and tools/call', function () {
    Server::registerAddonTools(UnbuildableTool::class);

    $exit = Artisan::call('statamic:mcp:doctor');

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('[FAIL] Tool '.UnbuildableTool::class.' cannot be built (missing API key)');
});

it('only warns about a shouldRegister() that throws, because one reading the request user throws only in the console', function () {
    Server::registerAddonTools(UnregistrableTool::class);
    app(TokenRepository::class)->issue(Fixtures::makeUser(), 'doctor');

    $exit = Artisan::call('statamic:mcp:doctor');

    expect($exit)->toBe(0)
        ->and(Artisan::output())
        ->toContain('[WARN] Tool '.UnregistrableTool::class.': its shouldRegister() throws here (license server down)')
        ->not->toContain('tools served, every name unique.');
});

it('fails when an add-on registered a class that is not a tool', function () {
    Server::registerAddonTools(stdClass::class);

    $exit = Artisan::call('statamic:mcp:doctor');

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('[FAIL] The MCP server cannot start ([stdClass] is not a Laravel\Mcp\Server\Tool subclass.)');
});

it('reports a server that throws while it starts and still runs the other checks', function () {
    config(['statamic.mcp.server' => ThrowingToolsServer::class]);

    $exit = Artisan::call('statamic:mcp:doctor');

    expect($exit)->toBe(1)
        ->and(Artisan::output())
        ->toContain('[FAIL] The MCP server cannot start (tools() broke)')
        ->toContain('[ OK ] Configured middleware resolves.');
});

it('reads the tools inside a laravel/mcp ToolSearch group', function () {
    config(['statamic.mcp.server' => ToolSearchServer::class]);

    Artisan::call('statamic:mcp:doctor');

    expect(Artisan::output())->toContain('[ OK ] 2 tools served, every name unique.');
});

it('names the add-on tools that are not served, such as a write tool under read_only', function () {
    Server::registerAddonTools(AddonWriteTool::class);
    config(['statamic.mcp.read_only' => true]);

    Artisan::call('statamic:mcp:doctor');

    expect(Artisan::output())->toContain('Add-on tool class '.AddonWriteTool::class.' is not served.');
});

it('warns when add-on tools never reach the configured server', function () {
    Server::registerAddonTools(AddonReadTool::class);
    config(['statamic.mcp.server' => PlainMcpServer::class]);

    Artisan::call('statamic:mcp:doctor');

    expect(Artisan::output())->toContain('[WARN] Add-ons registered tools ('.AddonReadTool::class.'), but '.PlainMcpServer::class.' does not extend Danielgnh\StatamicMcp\Server, so the AI does not get them.');
});
