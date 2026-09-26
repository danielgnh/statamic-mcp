<?php

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tokens\TokenRepository;
use Danielgnh\StatamicMcp\Tools\StatamicOverview;
use Statamic\Facades\Collection;
use Statamic\Facades\Nav;

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
        ->assertSee(sprintf('"user":{"id":"%s","email":"%s","roles":[],"is_super":true}', $super->id(), $super->email()))
        ->assertSee('"server":{"read_only":false,"deletes":false,"timezone":"UTC"}');
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
        // asset_containers, navigations, and forms sit between globals and user
        ->assertSee('"globals":[],"asset_containers":[],"navigations":[],"forms":[],"user"');
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

it('lists navigations the user may view with their max depth and edit flag', function () {
    Fixtures::site();
    Fixtures::pages();
    Fixtures::nav('main', maxDepth: 2);
    Fixtures::nav('footer');
    Fixtures::nav('secret');

    // 'secret' (no 'view secret nav') is filtered out entirely; single-site
    // navigations carry no sites list.
    Server::actingAs(Fixtures::makeUser('view main nav', 'edit main nav', 'view footer nav'))
        ->tool(StatamicOverview::class, [])
        ->assertOk()
        ->assertSee('"navigations":[{"handle":"footer","title":"Footer","max_depth":null,"can_edit":false},{"handle":"main","title":"Main","max_depth":2,"can_edit":true}],"forms":[],"user"');
});

it('lists forms whose submissions the user may view, with their submission count', function () {
    Fixtures::site();
    Fixtures::form('contact');
    Fixtures::form('newsletter', store: false);
    Fixtures::form('secret');
    Fixtures::submission('contact', ['name' => 'Ada'], '2026-09-20 09:00:00');

    // 'secret' (no 'view secret form submissions') is filtered out entirely;
    // no can_delete while deletes are disabled.
    Server::actingAs(Fixtures::makeUser('view contact form submissions', 'view newsletter form submissions'))
        ->tool(StatamicOverview::class, [])
        ->assertOk()
        ->assertSee('"forms":[{"handle":"contact","title":"Contact","stores_submissions":true,"submissions":1},{"handle":"newsletter","title":"Newsletter","stores_submissions":false,"submissions":0}],"user"');
});

it("lists every form for 'configure forms', the umbrella Statamic's form policy honors", function () {
    Fixtures::site();
    Fixtures::form('contact');
    Fixtures::form('secret');

    config(['statamic.mcp.deletes' => true]);

    Server::actingAs(Fixtures::makeUser('configure forms'))
        ->tool(StatamicOverview::class, [])
        ->assertOk()
        ->assertSee('"forms":[{"handle":"contact","title":"Contact","stores_submissions":true,"submissions":0,"can_delete":true},{"handle":"secret","title":"Secret","stores_submissions":true,"submissions":0,"can_delete":true}],"user"');
});

it('reports can_delete on forms from the delete permission when deletes are enabled', function () {
    Fixtures::site();
    Fixtures::form('contact');
    Fixtures::form('newsletter');

    config(['statamic.mcp.deletes' => true]);

    Server::actingAs(Fixtures::makeUser('view contact form submissions', 'delete contact form submissions', 'view newsletter form submissions'))
        ->tool(StatamicOverview::class, [])
        ->assertOk()
        ->assertSee('"forms":[{"handle":"contact","title":"Contact","stores_submissions":true,"submissions":0,"can_delete":true},{"handle":"newsletter","title":"Newsletter","stores_submissions":true,"submissions":0,"can_delete":false}],"user"');
});

it('omits forms excluded by the resources allowlist', function () {
    Fixtures::site();
    Fixtures::form('contact');
    Fixtures::form('newsletter');

    config(['statamic.mcp.resources.forms' => ['contact']]);

    Server::actingAs(Fixtures::makeSuper())
        ->tool(StatamicOverview::class, [])
        ->assertOk()
        ->assertSee('"forms":[{"handle":"contact","title":"Contact","stores_submissions":true,"submissions":0}],"user"');
});

it('lists the sites each navigation has a tree in under multisite', function () {
    Fixtures::multisite();
    Fixtures::pages();
    Fixtures::nav('main');
    Fixtures::nav('footer');

    Nav::find('footer')->in('de')->delete();

    Server::actingAs(Fixtures::makeSuper())
        ->tool(StatamicOverview::class, [])
        ->assertOk()
        ->assertSee('"navigations":[{"handle":"footer","title":"Footer","max_depth":null,"sites":["en"],"can_edit":true},{"handle":"main","title":"Main","max_depth":null,"sites":["en","de"],"can_edit":true}]');
});

it('omits navigations excluded by the resources allowlist', function () {
    Fixtures::site();
    Fixtures::pages();
    Fixtures::nav('main');
    Fixtures::nav('footer');

    config(['statamic.mcp.resources.navigations' => ['main']]);

    Server::actingAs(Fixtures::makeSuper())
        ->tool(StatamicOverview::class, [])
        ->assertOk()
        ->assertSee('"navigations":[{"handle":"main","title":"Main","max_depth":null,"can_edit":true}]');
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
        ->assertSee('"server":{"read_only":true,"deletes":false,"timezone":"UTC"}');
});

it('flags per-site access under multisite, the default site included', function () {
    Fixtures::multisite();
    Fixtures::tags();
    Fixtures::blog();

    Server::actingAs(Fixtures::makeUser('view blog entries', 'access en site'))
        ->tool(StatamicOverview::class, [])
        ->assertOk()
        ->assertSee('"locale":"en_US","can_access":true')
        ->assertSee('"locale":"de_DE","can_access":false');

    // CP parity: Statamic's SitePolicy gates the default site like any other.
    Server::actingAs(Fixtures::makeUser('view blog entries'))
        ->tool(StatamicOverview::class, [])
        ->assertOk()
        ->assertSee('"locale":"en_US","can_access":false');
});

it('adds other-authors flags to collections with an author field', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();
    Fixtures::authors();

    config(['statamic.mcp.deletes' => true]);

    $user = Fixtures::makeUser('view blog entries', 'edit blog entries', 'edit other authors blog entries');

    Server::actingAs($user)
        ->tool(StatamicOverview::class, [])
        ->assertOk()
        ->assertSee('"can_create":false,"can_edit":true,"can_publish":false,"can_delete":false,"can_edit_other_authors":true,"can_publish_other_authors":false,"can_delete_other_authors":false}]')
        ->assertSee(sprintf('"user":{"id":"%s"', $user->id()));
});

it('reflects a granted site permission in the can_access flag', function () {
    Fixtures::multisite();
    Fixtures::tags();
    Fixtures::blog();

    Server::actingAs(Fixtures::makeUser('access de site'))
        ->tool(StatamicOverview::class, [])
        ->assertOk()
        ->assertSee('"locale":"de_DE","can_access":true');
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
        ->assertSee('collections')
        ->assertSee('"isError":false', escape: false);
});

it('lists exposed asset containers with capability flags', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');

    $user = Fixtures::makeUser('view images assets', 'upload images assets');

    Server::actingAs($user)
        ->tool(StatamicOverview::class, [])
        ->assertOk()
        ->assertSee('"asset_containers":[{"handle":"images","title":"Images","can_upload":true,"can_edit":false}]');
});

it('hides asset containers the user cannot view and includes can_delete when deletes are enabled', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');
    Fixtures::assetContainer('private');
    config(['statamic.mcp.deletes' => true]);

    $user = Fixtures::makeUser('view images assets', 'delete images assets');

    Server::actingAs($user)
        ->tool(StatamicOverview::class, [])
        ->assertOk()
        ->assertSee('"handle":"images"')
        ->assertSee('"can_delete":true')
        ->assertDontSee('"handle":"private"');
});

it('omits unexposed asset containers entirely', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');
    config(['statamic.mcp.resources.asset_containers' => []]);

    Server::actingAs(Fixtures::makeSuper())
        ->tool(StatamicOverview::class, [])
        ->assertOk()
        ->assertSee('"asset_containers":[]');
});

it('reports date behavior on dated collections and the timezone dates are read in', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();
    Fixtures::news(past: 'unlisted');

    config(['app.timezone' => 'Europe/Berlin']);

    Server::actingAs(Fixtures::makeSuper())
        ->tool(StatamicOverview::class, [])
        ->assertOk()
        ->assertSee('{"handle":"blog","title":"Blog","dated":false,"revisions":false,"blueprints":["article"],"can_create":true,"can_edit":true,"can_publish":true}')
        ->assertSee('{"handle":"news","title":"News","dated":true,"revisions":false,"blueprints":["story"],"can_create":true,"can_edit":true,"can_publish":true,"date_behavior":{"future":"private","past":"unlisted"}}')
        ->assertSee('"server":{"read_only":false,"deletes":false,"timezone":"Europe/Berlin"}');
});

it("lists each collection's sites and propagate under multisite", function () {
    Fixtures::multisite();
    Fixtures::tags();
    Fixtures::blog();

    Server::actingAs(Fixtures::makeSuper())
        ->tool(StatamicOverview::class, [])
        ->assertOk()
        ->assertSee('"handle":"blog","title":"Blog","dated":false,"revisions":false,"sites":["en","de"],"propagate":false,"origin_behavior":"select","blueprints":["article"]');

    Collection::findByHandle('blog')->propagate(true)->originBehavior('root')->save();

    Server::actingAs(Fixtures::makeSuper())
        ->tool(StatamicOverview::class, [])
        ->assertOk()
        ->assertSee('"sites":["en","de"],"propagate":true,"origin_behavior":"root","blueprints":["article"]');
});
