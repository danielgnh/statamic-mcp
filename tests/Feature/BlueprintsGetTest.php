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
        // trailing 'slug' is v6's auto-appended entry blueprint field; the default date save
        // format is 'Y-m-d H:i' (has time), so the valid example is the ISO-Z datetime
        ->assertSee('"example":{"title":"Example text","color":"red","featured":true,"priority":42,"launch_date":"2026-01-15T09:30:00.000Z","slug":"example-slug"}');
});

it('shapes date examples by save format and mode, matching the DateFieldtype rule', function () {
    Fixtures::site();

    Collection::make('events')->title('Events')->save();

    Blueprint::makeFromFields([
        'title' => ['type' => 'text', 'validate' => 'required'],
        'when' => ['type' => 'date'], // default save format 'Y-m-d H:i' → ISO-Z datetime required
        'when_timed' => ['type' => 'date', 'time_enabled' => true],
        'day_only' => ['type' => 'date', 'format' => 'Y-m-d'], // time-less save format → plain date
        'window' => ['type' => 'date', 'mode' => 'range'],
        'stay' => ['type' => 'date', 'mode' => 'range', 'format' => 'Y-m-d'],
    ])->setHandle('event')->setNamespace('collections.events')->save();

    $user = Fixtures::makeUser();

    Server::actingAs($user)
        ->tool(BlueprintsGet::class, ['type' => 'collection', 'handle' => 'events'])
        ->assertOk()
        ->assertSee('"when":"2026-01-15T09:30:00.000Z"')
        ->assertSee('"when_timed":"2026-01-15T09:30:00.000Z"')
        ->assertSee('"day_only":"2026-01-15"')
        ->assertSee('"window":{"start":"2026-01-15T09:30:00.000Z","end":"2026-01-16T09:30:00.000Z"}')
        ->assertSee('"stay":{"start":"2026-01-15","end":"2026-01-16"}');
});

it('wraps the first option in an array for a multi-select', function () {
    Fixtures::site();

    Collection::make('shop')->title('Shop')->save();

    Blueprint::makeFromFields([
        'title' => ['type' => 'text', 'validate' => 'required'],
        'sizes' => ['type' => 'select', 'multiple' => true, 'options' => ['s' => 'Small', 'm' => 'Medium']],
        'material' => ['type' => 'select', 'options' => ['wool' => 'Wool', 'cotton' => 'Cotton']],
    ])->setHandle('product')->setNamespace('collections.shop')->save();

    $user = Fixtures::makeUser();

    Server::actingAs($user)
        ->tool(BlueprintsGet::class, ['type' => 'collection', 'handle' => 'shop'])
        ->assertOk()
        ->assertSee('"sizes":["s"]')
        ->assertSee('"material":"wool"');
});

it('returns the requested blueprint when a collection has several', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    Blueprint::makeFromFields([
        'title' => ['type' => 'text', 'validate' => 'required'],
        'video_url' => ['type' => 'text'],
    ])->setHandle('video')->setNamespace('collections.blog')->save();

    $user = Fixtures::makeUser();

    Server::actingAs($user)
        ->tool(BlueprintsGet::class, ['type' => 'collection', 'handle' => 'blog', 'blueprint' => 'video'])
        ->assertOk()
        ->assertSee('"blueprint":"video","available_blueprints":["article","video"]')
        ->assertSee('"handle":"video_url","type":"text"');
});

it('emits obviously fake placeholders for entries and users relation fields', function () {
    Fixtures::site();

    Collection::make('press')->title('Press')->save();

    Blueprint::makeFromFields([
        'title' => ['type' => 'text', 'validate' => 'required'],
        'related' => ['type' => 'entries'],
        'authors' => ['type' => 'users'],
    ])->setHandle('release')->setNamespace('collections.press')->save();

    $user = Fixtures::makeUser();

    Server::actingAs($user)
        ->tool(BlueprintsGet::class, ['type' => 'collection', 'handle' => 'press'])
        ->assertOk()
        ->assertSee('"related":["REPLACE-WITH-REAL-ENTRY-ID"]')
        ->assertSee('"authors":["REPLACE-WITH-REAL-USER-ID"]');
});

it('excludes computed fields from the example and marks them not writable', function () {
    Fixtures::site();

    Collection::make('reports')->title('Reports')->save();

    Blueprint::makeFromFields([
        'title' => ['type' => 'text', 'validate' => 'required'],
        'word_count' => ['type' => 'integer', 'visibility' => 'computed'],
    ])->setHandle('report')->setNamespace('collections.reports')->save();

    $user = Fixtures::makeUser();

    Server::actingAs($user)
        ->tool(BlueprintsGet::class, ['type' => 'collection', 'handle' => 'reports'])
        ->assertOk()
        ->assertSee('"handle":"word_count","type":"integer","required":false,"rules":["integer","nullable"],"visibility":"computed"')
        ->assertSee('"word_count":"computed — not writable"')
        // word_count must not appear as a writable example key
        ->assertSee('"example":{"title":"Example text","slug":"example-slug"}');
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
