<?php

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tools\BlueprintsGet;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;

it('returns fields and a bounded example payload for a collection blueprint', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $user = Fixtures::makeUser();

    Server::actingAs($user)
        ->tool(BlueprintsGet::class, ['type' => 'collection', 'handle' => 'blog'])
        ->assertOk()
        ->assertSee('"type":"collection","handle":"blog","blueprint":"article","available_blueprints":["article"]')
        ->assertSee('"handle":"title","type":"text","required":true')
        ->assertSee('"handle":"topic","type":"terms","required":false')
        // v6 appends a 'slug' field to entry blueprints of routed collections (Collection::ensureEntryBlueprintFields)
        ->assertSee('"example":{"title":"Example text","content":null,"hero_image":"Example text","topic":["REPLACE-WITH-REAL-TERM-ID"],"slug":"example-slug"}');
});

it('falls back to null plus a type note for a bard field', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $user = Fixtures::makeUser();

    Server::actingAs($user)
        ->tool(BlueprintsGet::class, ['type' => 'collection', 'handle' => 'blog'])
        ->assertOk()
        ->assertSee('"content":null')
        ->assertSee('"example_notes":{"content":"no example generated for fieldtype \'bard\' — read a real value from existing content before writing this field"}');
});

it('returns the blueprint of a taxonomy', function () {
    Fixtures::site();
    Fixtures::tags();

    $user = Fixtures::makeUser();

    Server::actingAs($user)
        ->tool(BlueprintsGet::class, ['type' => 'taxonomy', 'handle' => 'tags'])
        ->assertOk()
        ->assertSee('"type":"taxonomy","handle":"tags","blueprint":"tag","available_blueprints":["tag"]')
        // v6 appends a required 'slug' field to term blueprints (Taxonomy::ensureTermBlueprintFields)
        ->assertSee('"example":{"title":"Example text","slug":"example-slug"}');
});

it('returns the blueprint of a global set', function () {
    Fixtures::site();
    Fixtures::settings();

    $user = Fixtures::makeUser();

    Server::actingAs($user)
        ->tool(BlueprintsGet::class, ['type' => 'global', 'handle' => 'settings'])
        ->assertOk()
        ->assertSee('"type":"global","handle":"settings","blueprint":"settings","available_blueprints":["settings"]')
        ->assertSee('"handle":"site_name","type":"text","required":false')
        ->assertSee('"example":{"site_name":"Example text","footer_text":"Example text"}');
});

it('generates real examples for select, toggle, integer, and date fields', function () {
    Fixtures::site();

    Collection::make('pages')->title('Pages')->save();

    Blueprint::makeFromFields([
        'title' => ['type' => 'text', 'validate' => 'required'],
        'color' => ['type' => 'select', 'options' => ['red' => 'Red', 'blue' => 'Blue']],
        'featured' => ['type' => 'toggle'],
        'priority' => ['type' => 'integer'],
        'launch_date' => ['type' => 'date'],
    ])->setHandle('page')->setNamespace('collections.pages')->save();

    $user = Fixtures::makeUser();

    Server::actingAs($user)
        ->tool(BlueprintsGet::class, ['type' => 'collection', 'handle' => 'pages'])
        ->assertOk()
        ->assertSee('"options":{"red":"Red","blue":"Blue"}')
        // trailing 'slug' is v6's auto-appended entry blueprint field
        ->assertSee('"example":{"title":"Example text","color":"red","featured":true,"priority":42,"launch_date":"2026-01-15","slug":"example-slug"}');
});

it('treats unexposed and missing collections identically, listing only exposed handles', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    Collection::make('secrets')->title('Secrets')->save();

    config(['statamic.mcp.resources.collections' => ['blog']]);

    $user = Fixtures::makeUser();

    // exists but unexposed
    Server::actingAs($user)
        ->tool(BlueprintsGet::class, ['type' => 'collection', 'handle' => 'secrets'])
        ->assertHasErrors(["collection 'secrets' not found — available: blog"]);

    // does not exist at all — identical error shape
    Server::actingAs($user)
        ->tool(BlueprintsGet::class, ['type' => 'collection', 'handle' => 'nope'])
        ->assertHasErrors(["collection 'nope' not found — available: blog"]);
});

it('rejects an unknown blueprint handle, listing available blueprints', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $user = Fixtures::makeUser();

    Server::actingAs($user)
        ->tool(BlueprintsGet::class, ['type' => 'collection', 'handle' => 'blog', 'blueprint' => 'story'])
        ->assertHasErrors(["blueprint 'story' not found — available: article"]);
});

it('rejects an unknown type via validation', function () {
    Fixtures::site();

    $user = Fixtures::makeUser();

    Server::actingAs($user)
        ->tool(BlueprintsGet::class, ['type' => 'navigation', 'handle' => 'main'])
        ->assertHasErrors();
});
