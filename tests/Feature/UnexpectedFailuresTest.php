<?php

use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tokens\TokenRepository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Fields\Fieldtype;

class FieldtypeWhoseDatabaseIsDown extends Fieldtype
{
    protected static $handle = 'database_is_down';

    #[Override]
    public function preProcess($data)
    {
        return $data;
    }

    #[Override]
    public function process($data)
    {
        throw new QueryException('mysql', 'select secret from users', [], new PDOException('Connection refused'));
    }
}

function callToolAsSuper(string $tool, array $arguments): TestResponse
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

beforeEach(function () {
    Exceptions::fake();
    config(['app.debug' => false]);
    Fixtures::site();
});

it('reports a database failure inside a fieldtype and tells the agent nothing about it, because its value was fine', function () {
    FieldtypeWhoseDatabaseIsDown::register();

    Collection::make('articles')->routes('/articles/{slug}')->save();
    Blueprint::make('article')->setNamespace('collections.articles')->setContents(['fields' => [
        ['handle' => 'title', 'field' => ['type' => 'text']],
        ['handle' => 'related', 'field' => ['type' => 'database_is_down']],
    ]])->save();

    callToolAsSuper('entries_create', ['collection' => 'articles', 'data' => ['title' => 'A', 'related' => 'fine']])
        ->assertOk()
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0.text', 'An internal server error occurred.');

    Exceptions::assertReported(QueryException::class);
});

it('reports a programming error during a source_url download and tells the agent nothing about it, because the url was fine', function () {
    Fixtures::assetContainer('images');
    Http::fake(fn () => throw new TypeError('a bug, not a bad url'));

    callToolAsSuper('assets_upload', ['container' => 'images', 'source_url' => 'http://93.184.216.34/a.png'])
        ->assertOk()
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0.text', 'An internal server error occurred.');

    Exceptions::assertReported(TypeError::class);
});
