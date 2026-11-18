<?php

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\Addon\AddonHintedReadTool;
use Danielgnh\StatamicMcp\Tests\Support\Addon\AddonInheritedReadTool;
use Danielgnh\StatamicMcp\Tests\Support\Addon\AddonNotReadOnlyTool;
use Danielgnh\StatamicMcp\Tests\Support\Addon\AddonReadTool;
use Danielgnh\StatamicMcp\Tests\Support\Addon\AddonWriteTool;
use Danielgnh\StatamicMcp\Tests\Support\Addon\ShadowOverviewTool;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tests\UsesAddonProviders;
use Danielgnh\StatamicMcp\Tokens\TokenRepository;
use Illuminate\Support\Collection;
use Illuminate\Testing\TestResponse;

uses(UsesAddonProviders::class);

function addonPost(string $method, array $params, ?string $token): TestResponse
{
    return test()->withHeaders(array_filter([
        'Authorization' => $token === null ? null : 'Bearer '.$token,
        'Accept' => 'application/json, text/event-stream',
    ]))->postJson('/mcp/statamic', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => $method,
        'params' => $params === [] ? (object) [] : $params,
    ]);
}

function addonToken(): string
{
    return app(TokenRepository::class)->issue(Fixtures::makeUser(), 'addon')->token;
}

/**
 * @return Collection<int, string>
 */
function addonToolNames(string $token): Collection
{
    return collect(addonPost('tools/list', [], $token)->assertOk()->json('result.tools'))->pluck('name');
}

it('lists the tools of add-ons that boot before and after this addon, each class once', function () {
    $names = addonToolNames(addonToken());

    expect($names)->toContain('addon_read', 'addon_write', 'statamic_overview')
        ->and($names->filter(fn (string $name) => $name === 'addon_read'))->toHaveCount(1);
});

it('runs an add-on tool as the token user', function () {
    $user = Fixtures::makeUser();
    $token = app(TokenRepository::class)->issue($user, 'addon')->token;

    $response = addonPost('tools/call', ['name' => 'addon_read', 'arguments' => (object) []], $token)->assertOk();

    expect($response->json('result.content.0.text'))->toContain('"email":"'.$user->email().'"');
});

it('refuses an add-on tool call without a token', function () {
    addonPost('tools/call', ['name' => 'addon_read', 'arguments' => (object) []], null)->assertUnauthorized();
});

it('serves add-on tools through the laravel/mcp test harness', function () {
    $user = Fixtures::makeUser();

    Server::actingAs($user)
        ->tool(AddonReadTool::class)
        ->assertOk()
        ->assertSee('"email":"'.$user->email().'"');
});

it('keeps the built-in tool on tools/call when an add-on tool takes its name', function () {
    Server::registerAddonTools(ShadowOverviewTool::class);

    $response = addonPost('tools/call', ['name' => 'statamic_overview', 'arguments' => (object) []], addonToken())->assertOk();

    expect($response->json('result.content.0.text'))->not->toContain('"shadow":true')
        ->and($response->json('result.content.0.text'))->toContain('collections');
});

it('hides an add-on tool under read_only unless its own class declares IsReadOnly, as laravel/mcp reads readOnlyHint', function (string $tool, string $name) {
    Server::registerAddonTools($tool);
    config(['statamic.mcp.read_only' => true]);
    $token = addonToken();

    $call = addonPost('tools/call', ['name' => $name, 'arguments' => (object) []], $token);

    expect(addonToolNames($token))->toContain('addon_read')->not->toContain($name)
        ->and($call->json('error.message'))->toBe("Tool [{$name}] not found.");
})->with([
    'no attribute' => [AddonWriteTool::class, 'addon_write'],
    'IsReadOnly(false)' => [AddonNotReadOnlyTool::class, 'addon_not_read_only'],
    'attribute on the parent only' => [AddonInheritedReadTool::class, 'addon_inherited_read'],
]);

it('keeps an add-on tool under read_only when it declares a subclass of IsReadOnly, as laravel/mcp accepts one', function () {
    Server::registerAddonTools(AddonHintedReadTool::class);
    config(['statamic.mcp.read_only' => true]);

    expect(addonToolNames(addonToken()))->toContain('addon_hinted_read');
});

it('takes any class at registration, so a broken add-on fails the MCP route and not the boot of the site', function () {
    expect(fn () => Server::registerAddonTools(stdClass::class))->not->toThrow(Throwable::class);

    addonPost('tools/list', [], addonToken())->assertServerError();
});
