<?php

use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tokens\TokenRepository;
use Danielgnh\StatamicMcp\Tools\AssetsDelete;
use Danielgnh\StatamicMcp\Tools\AssetsUpdate;
use Danielgnh\StatamicMcp\Tools\AssetsUpload;
use Danielgnh\StatamicMcp\Tools\EntriesCreate;
use Danielgnh\StatamicMcp\Tools\EntriesDelete;
use Danielgnh\StatamicMcp\Tools\EntriesUpdate;
use Danielgnh\StatamicMcp\Tools\GlobalsUpdate;
use Danielgnh\StatamicMcp\Tools\TermsCreate;
use Danielgnh\StatamicMcp\Tools\TermsDelete;
use Danielgnh\StatamicMcp\Tools\TermsUpdate;
use Illuminate\Testing\TestResponse;
use Laravel\Mcp\Request;
use Statamic\Facades\Entry;

const READ_TOOLS = [
    'assets_get',
    'assets_list',
    'blueprints_get',
    'entries_get',
    'entries_list',
    'globals_get',
    'statamic_overview',
    'terms_get',
    'terms_list',
];

const WRITE_TOOLS = [
    'assets_update',
    'assets_upload',
    'entries_create',
    'entries_update',
    'globals_update',
    'terms_create',
    'terms_update',
];

const DELETE_TOOLS = [
    'assets_delete',
    'entries_delete',
    'terms_delete',
];

const WRITE_TOOL_CLASSES = [
    'assets_delete' => AssetsDelete::class,
    'assets_update' => AssetsUpdate::class,
    'assets_upload' => AssetsUpload::class,
    'entries_create' => EntriesCreate::class,
    'entries_update' => EntriesUpdate::class,
    'entries_delete' => EntriesDelete::class,
    'terms_create' => TermsCreate::class,
    'terms_update' => TermsUpdate::class,
    'terms_delete' => TermsDelete::class,
    'globals_update' => GlobalsUpdate::class,
];

function readOnlyPost(array $payload, string $token, ?string $sessionId = null): TestResponse
{
    return test()->withHeaders(array_filter([
        'Authorization' => 'Bearer '.$token,
        'Accept' => 'application/json, text/event-stream',
        'Mcp-Session-Id' => $sessionId,
    ]))->postJson('/mcp/statamic', $payload);
}

function readOnlyInitialize(string $token): ?string
{
    $response = readOnlyPost([
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2025-11-25',
            'capabilities' => (object) [],
            'clientInfo' => ['name' => 'pest', 'version' => '1.0.0'],
        ],
    ], $token);

    $response->assertOk();

    // laravel/mcp's web transport is stateless, but pass the session id
    // along if the server issued one — protocol-correct either way.
    return $response->headers->get('Mcp-Session-Id');
}

function readOnlyToolNames(string $token): array
{
    $sessionId = readOnlyInitialize($token);

    // laravel/mcp paginates tools/list at 15 per page by default (spec
    // ServerContext::perPage); the tool set has grown past that, so request
    // the server's max page size to see the whole advertised set in one call.
    $response = readOnlyPost([
        'jsonrpc' => '2.0',
        'id' => 2,
        'method' => 'tools/list',
        'params' => ['per_page' => 50],
    ], $token, $sessionId);

    $response->assertOk();

    return collect($response->json('result.tools'))->pluck('name')->sort()->values()->all();
}

it('advertises only the nine read tools over HTTP in read_only mode', function () {
    config(['statamic.mcp.read_only' => true]);

    $user = Fixtures::makeUser();
    $token = app(TokenRepository::class)->issue($user, 'ro')->token;

    $names = readOnlyToolNames($token);

    // Exact set equality: ONLY the nine read tools remain...
    expect($names)->toBe(READ_TOOLS);

    // ...and every write/delete tool is absent BY NAME — if the exact-set
    // assertion ever loosens, this still pins the security property.
    expect($names)->not->toContain(...WRITE_TOOLS, ...DELETE_TOOLS);
});

it('advertises every non-delete tool with the zero-config default', function () {
    // Default config: read_only=false, deletes=false.
    $user = Fixtures::makeUser();
    $token = app(TokenRepository::class)->issue($user, 'rw')->token;

    $names = readOnlyToolNames($token);

    expect($names)->toBe(collect([...READ_TOOLS, ...WRITE_TOOLS])->sort()->values()->all());
    expect($names)->toContain(...WRITE_TOOLS);
    expect($names)->not->toContain(...DELETE_TOOLS);
});

it('advertises the full tool set when deletes are enabled', function () {
    config(['statamic.mcp.deletes' => true]);

    $user = Fixtures::makeUser();
    $token = app(TokenRepository::class)->issue($user, 'full')->token;

    expect(readOnlyToolNames($token))->toBe(
        collect([...READ_TOOLS, ...WRITE_TOOLS, ...DELETE_TOOLS])->sort()->values()->all()
    );
});

it('serves the full tool set on one page for clients that never paginate', function () {
    config(['statamic.mcp.deletes' => true]);

    $user = Fixtures::makeUser();
    $token = app(TokenRepository::class)->issue($user, 'no-pagination')->token;

    $sessionId = readOnlyInitialize($token);

    // Deliberately NO per_page: laravel/mcp would page at 15 by default and a
    // cursor-less client would silently miss the overflow — the Server's
    // defaultPaginationLength override must make one page hold everything.
    $response = readOnlyPost([
        'jsonrpc' => '2.0',
        'id' => 2,
        'method' => 'tools/list',
        'params' => (object) [],
    ], $token, $sessionId);

    $response->assertOk();

    $names = collect($response->json('result.tools'))->pluck('name')->sort()->values()->all();

    expect($names)->toBe(collect([...READ_TOOLS, ...WRITE_TOOLS, ...DELETE_TOOLS])->sort()->values()->all());
});

it('still serves read tool calls over HTTP in read_only mode', function () {
    config(['statamic.mcp.read_only' => true]);

    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $user = Fixtures::makeUser('view blog entries');
    $token = app(TokenRepository::class)->issue($user, 'reader')->token;

    $sessionId = readOnlyInitialize($token);

    $call = readOnlyPost([
        'jsonrpc' => '2.0',
        'id' => 3,
        'method' => 'tools/call',
        'params' => ['name' => 'statamic_overview', 'arguments' => (object) []],
    ], $token, $sessionId);

    $call->assertOk();

    expect($call->json('error'))->toBeNull()
        ->and(data_get($call->json(), 'result.isError'))->not->toBeTrue()
        ->and(data_get($call->json(), 'result.content.0.text'))
        ->toContain('"read_only":true');
});

// Stale-client-cache scenario, spec §6 layer 1: the Server harness enforces
// shouldRegister(), so only a direct handle() call can pin the IN-HANDLER
// re-check for every write/delete tool. The guard fires before validation or
// user resolution, so bare requests suffice and no content can be touched.
it('re-checks read_only inside the handler of every write and delete tool', function (string $tool) {
    config(['statamic.mcp.read_only' => true, 'statamic.mcp.deletes' => true]);

    Fixtures::site();

    $response = (new $tool)->handle(new Request([]));

    expect($response->isError())->toBeTrue()
        ->and((string) $response->content())
        ->toContain('writes are disabled on this server (statamic.mcp.read_only)');
})->with(WRITE_TOOL_CLASSES);

// Completeness: a v1.1 write tool first breaks the exact-set tests above;
// updating the name constants then breaks this until the tool's class joins
// WRITE_TOOL_CLASSES — nothing can be advertised as a write/delete tool
// without its in-handler re-check being swept.
it('sweeps every advertised write and delete tool', function () {
    expect(collect(array_keys(WRITE_TOOL_CLASSES))->sort()->values()->all())
        ->toBe(collect([...WRITE_TOOLS, ...DELETE_TOOLS])->sort()->values()->all());
});

it('refuses a stale-cached write tool call over HTTP in read_only mode', function () {
    config(['statamic.mcp.read_only' => true]);

    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $entry = tap(
        Entry::make()->collection('blog')->slug('hello-world')->data(['title' => 'Hello World'])->published(true)
    )->save();

    // A super user: the refusal below can only come from the read_only gate,
    // never from a permission denial.
    $super = Fixtures::makeSuper();
    $token = app(TokenRepository::class)->issue($super, 'stale-cache')->token;

    $sessionId = readOnlyInitialize($token);

    // Simulates a client whose cached tool list still contains entries_update.
    $call = readOnlyPost([
        'jsonrpc' => '2.0',
        'id' => 3,
        'method' => 'tools/call',
        'params' => [
            'name' => 'entries_update',
            'arguments' => ['id' => $entry->id(), 'data' => ['title' => 'Hacked']],
        ],
    ], $token, $sessionId);

    $call->assertOk(); // JSON-RPC errors still ride on HTTP 200

    // Whether laravel/mcp rejects the unregistered tool at dispatch (JSON-RPC
    // 'error') or the handler's own re-check fires (tool result isError),
    // the refusal must happen and the write must not.
    $refused = $call->json('error') !== null
        || data_get($call->json(), 'result.isError') === true;

    expect($refused)->toBeTrue();
    expect(Entry::find($entry->id())->get('title'))->toBe('Hello World');
});
