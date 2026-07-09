<?php

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tokens\TokenRepository;
use Danielgnh\StatamicMcp\Tools\StatamicOverview;
use Statamic\Facades\Collection;

it('returns sites, resources with capability flags, acting user, and server flags for a super', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();
    Fixtures::settings();

    $super = Fixtures::makeSuper();

    Server::actingAs($super)
        ->tool(StatamicOverview::class, [])
        ->assertOk()
        ->assertSee('"sites":[{"handle":"en","name":"English","url":"/","locale":"en_US"}]')
        // fragment ends at "can_publish":true}] — proves can_delete is absent while deletes are disabled
        ->assertSee('"collections":[{"handle":"blog","title":"Blog","dated":false,"revisions":false,"blueprints":["article"],"can_create":true,"can_edit":true,"can_publish":true}]')
        ->assertSee('"taxonomies":[{"handle":"tags","title":"Tags","blueprints":["tag"],"can_create":true,"can_edit":true}]')
        ->assertSee('"globals":[{"handle":"settings","title":"Settings","can_edit":true}]')
        ->assertSee(sprintf('"user":{"email":"%s","roles":[],"is_super":true}', $super->email()))
        ->assertSee('"server":{"read_only":false,"deletes":false}');
});

it('omits collections excluded by the resources allowlist', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    Collection::make('secrets')->title('Secrets')->save();

    config(['statamic.mcp.resources.collections' => ['blog']]);

    $super = Fixtures::makeSuper();

    Server::actingAs($super)
        ->tool(StatamicOverview::class, [])
        ->assertOk()
        // trailing ],"taxonomies" proves the collections array holds exactly one element:
        // 'secrets' exists on the site but is not exposed to MCP
        ->assertSee('"collections":[{"handle":"blog","title":"Blog","dated":false,"revisions":false,"blueprints":["article"],"can_create":true,"can_edit":true,"can_publish":true}],"taxonomies"');
});

it('omits resources the user may not view and reflects granted permissions in flags', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    Collection::make('pages')->title('Pages')->save();

    $user = Fixtures::makeUser('view blog entries', 'edit blog entries');

    Server::actingAs($user)
        ->tool(StatamicOverview::class, [])
        ->assertOk()
        // 'pages' (no 'view pages entries') and 'tags' (no 'view tags terms') are filtered out
        // entirely; blog flags mirror the granted permissions: view+edit but no create/publish
        ->assertSee('"collections":[{"handle":"blog","title":"Blog","dated":false,"revisions":false,"blueprints":["article"],"can_create":false,"can_edit":true,"can_publish":false}],"taxonomies":[],"globals":[]');
});

it('hides global sets the user may not edit', function () {
    Fixtures::site();
    Fixtures::settings();

    $user = Fixtures::makeUser(); // 'access mcp' only

    Server::actingAs($user)
        ->tool(StatamicOverview::class, [])
        ->assertOk()
        ->assertSee('"globals":[],"user"');
});

it('lists global sets the user may edit', function () {
    Fixtures::site();
    Fixtures::settings();

    $user = Fixtures::makeUser('edit settings globals');

    Server::actingAs($user)
        ->tool(StatamicOverview::class, [])
        ->assertOk()
        ->assertSee('"globals":[{"handle":"settings","title":"Settings","can_edit":true}]');
});

it('includes can_delete flags only when deletes are enabled', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    config(['statamic.mcp.deletes' => true]);

    $user = Fixtures::makeUser('view blog entries', 'delete blog entries', 'view tags terms');

    Server::actingAs($user)
        ->tool(StatamicOverview::class, [])
        ->assertOk()
        ->assertSee('"collections":[{"handle":"blog","title":"Blog","dated":false,"revisions":false,"blueprints":["article"],"can_create":false,"can_edit":false,"can_publish":false,"can_delete":true}]')
        ->assertSee('"taxonomies":[{"handle":"tags","title":"Tags","blueprints":["tag"],"can_create":false,"can_edit":false,"can_delete":false}]')
        ->assertSee('"deletes":true');
});

it('reports the read_only server flag and forces the deletes flag off', function () {
    Fixtures::site();

    config(['statamic.mcp.read_only' => true, 'statamic.mcp.deletes' => true]);

    $super = Fixtures::makeSuper();

    Server::actingAs($super)
        ->tool(StatamicOverview::class, [])
        ->assertOk()
        ->assertSee('"server":{"read_only":true,"deletes":false}');
});

// moved here from Task 6: requires the Server class
it('guards the real MCP endpoint end to end', function () {
    $initialize = [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2025-11-25',
            'capabilities' => [],
            'clientInfo' => ['name' => 'pest', 'version' => '1.0.0'],
        ],
    ];

    $this->postJson('/mcp/statamic', $initialize)->assertStatus(401);

    $user = Fixtures::makeUser();
    $plain = app(TokenRepository::class)->issue($user);

    $this->postJson('/mcp/statamic', $initialize, [
        'Authorization' => "Bearer {$plain->token}",
        'Accept' => 'application/json, text/event-stream', // streamable-HTTP clients send both
    ])
        ->assertOk()
        ->assertSee('Statamic'); // serverInfo name from the #[Name] attribute

    // full seam over real HTTP: middleware → Auth::setUser → Request::user() → User::fromUser
    $this->postJson('/mcp/statamic', [
        'jsonrpc' => '2.0',
        'id' => 2,
        'method' => 'tools/call',
        'params' => ['name' => 'statamic_overview', 'arguments' => []],
    ], [
        'Authorization' => "Bearer {$plain->token}",
        'Accept' => 'application/json, text/event-stream',
    ])
        ->assertOk()
        ->assertSee('collections');
});
