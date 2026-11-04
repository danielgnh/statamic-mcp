<?php

use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tokens\TokenRepository;
use Illuminate\Testing\TestResponse;

function callToolOverHttp(string $tool, array $arguments): TestResponse
{
    $token = app(TokenRepository::class)->issue(Fixtures::makeSuper(), 'http')->token;

    return test()->withHeaders([
        'Authorization' => "Bearer {$token}",
        'Accept' => 'application/json, text/event-stream',
    ])->postJson('/mcp/statamic', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => ['name' => $tool, 'arguments' => $arguments],
    ]);
}

it('answers a bad tool argument with 200 and isError, as docs/troubleshooting.md says', function (array $arguments) {
    Fixtures::site();
    Fixtures::assetContainer('images');

    callToolOverHttp('assets_upload', $arguments)
        ->assertOk()
        ->assertJsonPath('result.isError', true);
})->with([
    'missing' => [[]],
    'wrong type' => [['container' => ['images']]],
]);

it('answers an unknown tool with 400 and a JSON-RPC error, as docs/troubleshooting.md says', function () {
    callToolOverHttp('no_such_tool', [])
        ->assertStatus(400)
        ->assertJsonPath('error.code', -32602);
});
