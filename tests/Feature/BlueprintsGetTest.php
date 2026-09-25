<?php

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tools\BlueprintsGet;
use Danielgnh\StatamicMcp\Tools\EntriesCreate;
use Illuminate\Support\Facades\Storage;
use Laravel\Mcp\Request;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

it('returns fields and a bounded example payload for a collection blueprint', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $user = Fixtures::makeUser('view blog entries');

    Server::actingAs($user)
        ->tool(BlueprintsGet::class, ['type' => 'collection', 'handle' => 'blog'])
        ->assertOk()
        ->assertSee('"type":"collection","handle":"blog","blueprint":"article","available_blueprints":["article"]')
        ->assertSee('"handle":"title","type":"text","required":true')
        ->assertSee('"handle":"topic","type":"terms","required":false')
        // v6 appends a 'slug' field to entry blueprints (Collection::ensureEntryBlueprintFields),
        // but the entry tools take slug as a top-level parameter, so it stays out of the example
        ->assertSee('"handle":"slug","type":"slug"')
        ->assertSee('"example":{"title":"Example text","content":null,"hero_image":"Example text","topic":"REPLACE-WITH-REAL-TERM-ID"}')
        ->assertSee('"slug":"pass slug as a top-level parameter of entries_create and entries_update, not inside data"');
});

it('falls back to null plus a type note for a bard field', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $user = Fixtures::makeUser('view blog entries');

    Server::actingAs($user)
        ->tool(BlueprintsGet::class, ['type' => 'collection', 'handle' => 'blog'])
        ->assertOk()
        ->assertSee('"content":null')
        ->assertSee('"example_notes":{"content":"no example generated for fieldtype \'bard\' — send an HTML string, which is converted to ProseMirror nodes, or the nodes themselves.","slug":"pass slug as a top-level parameter of entries_create and entries_update, not inside data"}');
});

it('returns the blueprint of a taxonomy', function () {
    Fixtures::site();
    Fixtures::tags();

    $user = Fixtures::makeUser('view tags terms');

    Server::actingAs($user)
        ->tool(BlueprintsGet::class, ['type' => 'taxonomy', 'handle' => 'tags'])
        ->assertOk()
        ->assertSee('"type":"taxonomy","handle":"tags","blueprint":"tag","available_blueprints":["tag"]')
        // v6 appends a required 'slug' field to term blueprints (Taxonomy::ensureTermBlueprintFields)
        ->assertSee('"handle":"slug","type":"slug","required":true')
        ->assertSee('"example":{"title":"Example text"}')
        ->assertSee('"slug":"pass slug as a top-level parameter of terms_create and terms_update, not inside data"');
});

it('returns the blueprint of a global set', function () {
    Fixtures::site();
    Fixtures::settings();

    $user = Fixtures::makeUser('edit settings globals');

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

    $user = Fixtures::makeUser('view pages entries');

    Server::actingAs($user)
        ->tool(BlueprintsGet::class, ['type' => 'collection', 'handle' => 'pages'])
        ->assertOk()
        ->assertSee('"options":{"red":"Red","blue":"Blue"}')
        // dates use the default save format 'Y-m-d H:i', the shape entries_get returns
        ->assertSee('"example":{"title":"Example text","color":"red","featured":true,"priority":42,"launch_date":"2026-01-15 09:30"}');
});

it('shapes date examples in the field save format and mode', function () {
    Fixtures::site();

    Collection::make('events')->title('Events')->save();

    Blueprint::makeFromFields([
        'title' => ['type' => 'text', 'validate' => 'required'],
        'when' => ['type' => 'date'], // default save format 'Y-m-d H:i'
        'when_timed' => ['type' => 'date', 'time_enabled' => true],
        'with_seconds' => ['type' => 'date', 'time_seconds_enabled' => true],
        'day_only' => ['type' => 'date', 'format' => 'Y-m-d'],
        'window' => ['type' => 'date', 'mode' => 'range'],
        'stay' => ['type' => 'date', 'mode' => 'range', 'format' => 'Y-m-d'],
    ])->setHandle('event')->setNamespace('collections.events')->save();

    $user = Fixtures::makeUser('view events entries');

    Server::actingAs($user)
        ->tool(BlueprintsGet::class, ['type' => 'collection', 'handle' => 'events'])
        ->assertOk()
        ->assertSee('"when":"2026-01-15 09:30"')
        ->assertSee('"when_timed":"2026-01-15 09:30"')
        ->assertSee('"with_seconds":"2026-01-15 09:30:00"')
        ->assertSee('"day_only":"2026-01-15"')
        ->assertSee('"window":{"start":"2026-01-15 09:30","end":"2026-01-16 09:30"}')
        ->assertSee('"stay":{"start":"2026-01-15","end":"2026-01-16"}');
});

it('returns an example that entries_create accepts as data on a dated collection', function () {
    Fixtures::site();

    tap(Collection::make('events')->title('Events')->dated(true)->routes('/events/{slug}'))->save();

    Blueprint::makeFromFields([
        'title' => ['type' => 'text', 'validate' => 'required'],
        'launch_date' => ['type' => 'date'],
        'featured' => ['type' => 'toggle'],
    ])->setHandle('event')->setNamespace('collections.events')->save();

    $user = Fixtures::makeUser('view events entries', 'create events entries');

    $this->actingAs($user);

    $blueprint = json_decode((string) (new BlueprintsGet)->handle(new Request(['type' => 'collection', 'handle' => 'events']))->content(), true);

    expect(data_get($blueprint, 'example'))->not->toHaveKeys(['slug', 'date'])
        ->and(data_get($blueprint, 'example_notes'))->toMatchArray([
            'slug' => 'pass slug as a top-level parameter of entries_create and entries_update, not inside data',
            'date' => 'pass date as a top-level parameter of entries_create and entries_update, not inside data',
        ]);

    Server::actingAs($user)
        ->tool(EntriesCreate::class, ['collection' => 'events', 'data' => data_get($blueprint, 'example'), 'date' => '2026-07-09'])
        ->assertOk()
        ->assertSee('saved as draft — not live');
});

it('gives single-item relationship fields a plain id example', function () {
    Fixtures::site();

    Collection::make('posts')->title('Posts')->save();

    Blueprint::makeFromFields([
        'title' => ['type' => 'text', 'validate' => 'required'],
        'author' => ['type' => 'users', 'max_items' => 1],
        'featured' => ['type' => 'entries', 'collections' => ['posts'], 'max_items' => 1],
        'related' => ['type' => 'entries', 'collections' => ['posts']],
    ])->setHandle('post')->setNamespace('collections.posts')->save();

    Server::actingAs(Fixtures::makeUser('view posts entries'))
        ->tool(BlueprintsGet::class, ['type' => 'collection', 'handle' => 'posts'])
        ->assertOk()
        ->assertSee('"author":"REPLACE-WITH-REAL-USER-ID"')
        ->assertSee('"featured":"REPLACE-WITH-REAL-ENTRY-ID"')
        ->assertSee('"related":["REPLACE-WITH-REAL-ENTRY-ID"]');
});

it('wraps the first option in an array for a multi-select', function () {
    Fixtures::site();

    Collection::make('shop')->title('Shop')->save();

    Blueprint::makeFromFields([
        'title' => ['type' => 'text', 'validate' => 'required'],
        'sizes' => ['type' => 'select', 'multiple' => true, 'options' => ['s' => 'Small', 'm' => 'Medium']],
        'material' => ['type' => 'select', 'options' => ['wool' => 'Wool', 'cotton' => 'Cotton']],
    ])->setHandle('product')->setNamespace('collections.shop')->save();

    $user = Fixtures::makeUser('view shop entries');

    Server::actingAs($user)
        ->tool(BlueprintsGet::class, ['type' => 'collection', 'handle' => 'shop'])
        ->assertOk()
        ->assertSee('"sizes":["s"]')
        ->assertSee('"material":"wool"');
});

it('uses the first key of options saved as key and value pairs', function () {
    Fixtures::site();

    Collection::make('pages')->title('Pages')->save();

    // The CP saves options as a list of key/value pairs.
    Blueprint::makeFromFields([
        'title' => ['type' => 'text', 'validate' => 'required'],
        'variant' => ['type' => 'select', 'options' => [['key' => 'default', 'value' => 'Default'], ['key' => 'search', 'value' => 'Search']]],
        'alignment' => ['type' => 'button_group', 'options' => [['key' => 'center', 'value' => 'Center'], ['key' => 'top', 'value' => 'Top']]],
    ])->setHandle('page')->setNamespace('collections.pages')->save();

    Server::actingAs(Fixtures::makeUser('view pages entries'))
        ->tool(BlueprintsGet::class, ['type' => 'collection', 'handle' => 'pages'])
        ->assertOk()
        ->assertSee('"variant":"default"')
        ->assertSee('"alignment":"center"');
});

it('points the replicator note at the first set editors can add', function () {
    Fixtures::site();

    Collection::make('pages')->title('Pages')->save();

    Blueprint::makeFromFields([
        'title' => ['type' => 'text', 'validate' => 'required'],
        'page_builder' => ['type' => 'replicator', 'sets' => ['main' => ['sets' => [
            'old_banner' => ['display' => 'Old Banner', 'hide' => true, 'fields' => [
                ['handle' => 'text', 'field' => ['type' => 'text']],
            ]],
            'section_text' => ['display' => 'Section - Text', 'fields' => [
                ['handle' => 'text', 'field' => ['type' => 'textarea']],
            ]],
        ]]]],
    ])->setHandle('page')->setNamespace('collections.pages')->save();

    Server::actingAs(Fixtures::makeUser('view pages entries'))
        ->tool(BlueprintsGet::class, ['type' => 'collection', 'handle' => 'pages'])
        ->assertOk()
        ->assertSee('"page_builder":null')
        ->assertSee('"page_builder":"a list of sets, each an object with its type plus its field values (id and enabled are optional) — call blueprints_get with set: section_text, or another handle from sets, for a set\'s fields and an example"');
});

it('describes the fields of grid and group fields, also inside a set', function () {
    Fixtures::site();

    Collection::make('pages')->title('Pages')->save();

    Blueprint::makeFromFields([
        'title' => ['type' => 'text', 'validate' => 'required'],
        'facts' => ['type' => 'grid', 'fields' => [
            ['handle' => 'label', 'field' => ['type' => 'text']],
        ]],
        'seo' => ['type' => 'group', 'fields' => [
            ['handle' => 'meta_title', 'field' => ['type' => 'text', 'instructions' => 'Under 60 characters.']],
        ]],
        'page_builder' => ['type' => 'replicator', 'sets' => ['main' => ['sets' => [
            'section_stats' => ['display' => 'Section - Stats', 'fields' => [
                ['handle' => 'stats', 'field' => ['type' => 'grid', 'fields' => [
                    ['handle' => 'value', 'field' => ['type' => 'integer']],
                ]]],
            ]],
        ]]]],
    ])->setHandle('page')->setNamespace('collections.pages')->save();

    Server::actingAs(Fixtures::makeUser('view pages entries'))
        ->tool(BlueprintsGet::class, ['type' => 'collection', 'handle' => 'pages'])
        ->assertOk()
        ->assertSee('{"handle":"facts","type":"grid","required":false,"rules":["array","nullable"],"fields":[{"handle":"label","type":"text","required":false,"rules":["nullable"]}]}')
        ->assertSee('{"handle":"seo","type":"group","required":false,"rules":["array","nullable"],"fields":[{"handle":"meta_title","type":"text","required":false,"rules":["nullable"],"instructions":"Under 60 characters."}]}');

    Server::actingAs(Fixtures::makeUser('view pages entries'))
        ->tool(BlueprintsGet::class, ['type' => 'collection', 'handle' => 'pages', 'set' => 'section_stats'])
        ->assertOk()
        ->assertSee('"set":{"handle":"section_stats","display":"Section - Stats","group":"Main","fields":[{"handle":"stats","type":"grid","required":false,"rules":["array","nullable"],"fields":[{"handle":"value","type":"integer","required":false,"rules":["integer","nullable"]}]}]}');
});

it('gives a grid one row and a group one object, and a set its own example row', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');
    Fixtures::landing();

    Server::actingAs(Fixtures::makeUser('view landing entries'))
        ->tool(BlueprintsGet::class, ['type' => 'collection', 'handle' => 'landing'])
        ->assertOk()
        ->assertSee('"page_builder":null')
        ->assertSee('"facts":[{"label":"Example text"}]')
        ->assertSee('"seo":{"meta_title":"Example text"}')
        ->assertSee('"page_builder":"a list of sets, each an object with its type plus its field values (id and enabled are optional) — call blueprints_get with set: section_hero, or another handle from sets, for a set\'s fields and an example"');

    Server::actingAs(Fixtures::makeUser('view landing entries'))
        ->tool(BlueprintsGet::class, ['type' => 'collection', 'handle' => 'landing', 'set' => 'section_hero'])
        ->assertOk()
        ->assertSee('"example":{"type":"section_hero","heading":"Example text","image":"REPLACE-WITH-REAL-ASSET-PATH"}')
        // notes are keyed by their path in the example row
        ->assertSee('"example_notes":{"image":"stores asset paths relative to the container root (container \'images\') — max_files is 1, so pass a single string path.');
});

it('builds a set example from its fields, leaving computed fields out', function () {
    Fixtures::site();

    Collection::make('pages')->title('Pages')->save();

    Blueprint::makeFromFields([
        'title' => ['type' => 'text', 'validate' => 'required'],
        'page_builder' => ['type' => 'replicator', 'sets' => ['main' => ['sets' => [
            'section_stats' => ['display' => 'Section - Stats', 'fields' => [
                ['handle' => 'total', 'field' => ['type' => 'integer', 'visibility' => 'computed']],
                ['handle' => 'stats', 'field' => ['type' => 'grid', 'fields' => [
                    ['handle' => 'value', 'field' => ['type' => 'integer']],
                ]]],
                ['handle' => 'actions', 'field' => ['type' => 'replicator', 'sets' => ['main' => ['sets' => [
                    'link' => ['display' => 'Link', 'fields' => [
                        ['handle' => 'url', 'field' => ['type' => 'link']],
                    ]],
                ]]]]],
            ]],
        ]]]],
    ])->setHandle('page')->setNamespace('collections.pages')->save();

    Server::actingAs(Fixtures::makeUser('view pages entries'))
        ->tool(BlueprintsGet::class, ['type' => 'collection', 'handle' => 'pages', 'set' => 'section_stats'])
        ->assertOk()
        ->assertSee('"example":{"type":"section_stats","stats":[{"value":42}],"actions":null}')
        ->assertSee('"total":"computed — not writable"')
        ->assertSee('"actions":"a list of sets, each an object with its type plus its field values (id and enabled are optional) — call blueprints_get with set: link, or another handle from sets');
});

it('shows how a set node looks in the note of a bard field with sets', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');
    Fixtures::landing();

    Server::actingAs(Fixtures::makeUser('view landing entries'))
        ->tool(BlueprintsGet::class, ['type' => 'collection', 'handle' => 'landing'])
        ->assertOk()
        ->assertSee('"body":null')
        ->assertSee('"body":"no example generated for fieldtype \'bard\' — send an HTML string, which is converted to ProseMirror nodes, or the nodes themselves. A set is a node {\"type\":\"set\",\"attrs\":{\"values\":{\"type\":\"callout\", ...its field values}}}; call blueprints_get with set: callout, or another handle from sets, for a set\'s fields and an example of its values. HTML cannot hold sets."');
});

it('returns examples that entries_create stores, a looked-up set row included', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');
    Fixtures::landing();

    Storage::disk('images')->put('hero.jpg', Fixtures::tinyPng());

    $user = Fixtures::makeUser('view landing entries', 'create landing entries');

    $this->actingAs($user);

    $example = fn (array $arguments = []) => data_get(json_decode(str_replace(
        'REPLACE-WITH-REAL-ASSET-PATH',
        'hero.jpg',
        (string) (new BlueprintsGet)->handle(new Request(['type' => 'collection', 'handle' => 'landing', ...$arguments]))->content(),
    ), true), 'example');

    $data = [...$example(), 'page_builder' => [$example(['set' => 'section_hero'])]];

    Server::actingAs($user)
        ->tool(EntriesCreate::class, ['collection' => 'landing', 'data' => $data])
        ->assertOk();

    $entry = Entry::query()->where('collection', 'landing')->first();

    expect($entry->get('page_builder')[0])->toMatchArray(['type' => 'section_hero', 'enabled' => true, 'heading' => 'Example text', 'image' => 'hero.jpg'])
        ->and($entry->get('facts')[0])->toMatchArray(['label' => 'Example text'])
        ->and($entry->get('seo'))->toBe(['meta_title' => 'Example text']);
});

it('returns the requested blueprint when a collection has several', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    Blueprint::makeFromFields([
        'title' => ['type' => 'text', 'validate' => 'required'],
        'video_url' => ['type' => 'text'],
    ])->setHandle('video')->setNamespace('collections.blog')->save();

    $user = Fixtures::makeUser('view blog entries');

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

    $user = Fixtures::makeUser('view press entries');

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

    $user = Fixtures::makeUser('view reports entries');

    Server::actingAs($user)
        ->tool(BlueprintsGet::class, ['type' => 'collection', 'handle' => 'reports'])
        ->assertOk()
        ->assertSee('"handle":"word_count","type":"integer","required":false,"rules":["integer","nullable"],"visibility":"computed"')
        ->assertSee('"word_count":"computed — not writable"')
        // word_count must not appear as a writable example key
        ->assertSee('"example":{"title":"Example text"}');
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

    $user = Fixtures::makeUser('view blog entries');

    Server::actingAs($user)
        ->tool(BlueprintsGet::class, ['type' => 'collection', 'handle' => 'blog', 'blueprint' => 'story'])
        ->assertHasErrors(["blueprint 'story' not found — available: article"]);
});

it('gives assets fields an actionable example pointing at the assets tools', function () {
    Fixtures::site();

    Collection::make('posts')->title('Posts')->save();

    Blueprint::makeFromFields([
        'title' => ['type' => 'text', 'validate' => 'required'],
        'hero' => ['type' => 'assets', 'container' => 'images', 'max_files' => 1],
        'gallery' => ['type' => 'assets', 'container' => 'images'],
    ])->setHandle('post')->setNamespace('collections.posts')->save();

    $user = Fixtures::makeUser('view posts entries');

    Server::actingAs($user)
        ->tool(BlueprintsGet::class, ['type' => 'collection', 'handle' => 'posts'])
        ->assertOk()
        // max_files 1 stores a single string; multi stores a list (vendor Fieldtypes\Assets::process)
        ->assertSee('"hero":"REPLACE-WITH-REAL-ASSET-PATH"')
        ->assertSee('"gallery":["REPLACE-WITH-REAL-ASSET-PATH"]')
        ->assertSee("container 'images'")
        ->assertSee('assets_list')
        ->assertSee('assets_upload');
});

it('says where asset, entry, term, and user fields point when that is configured', function () {
    Fixtures::site();

    Collection::make('posts')->title('Posts')->save();

    Blueprint::makeFromFields([
        'title' => ['type' => 'text', 'validate' => 'required'],
        'hero' => ['type' => 'assets', 'container' => 'images', 'max_files' => 1],
        'gallery' => ['type' => 'assets'],
        'related' => ['type' => 'entries', 'collections' => ['posts'], 'max_items' => 3],
        'topics' => ['type' => 'terms', 'taxonomies' => ['tags']],
        'author' => ['type' => 'users', 'max_items' => 1],
    ])->setHandle('post')->setNamespace('collections.posts')->save();

    Server::actingAs(Fixtures::makeUser('view posts entries'))
        ->tool(BlueprintsGet::class, ['type' => 'collection', 'handle' => 'posts'])
        ->assertOk()
        ->assertSee('{"handle":"hero","type":"assets","required":false,"rules":["array","max:1","nullable"],"container":"images","max_files":1}')
        ->assertSee('{"handle":"gallery","type":"assets","required":false,"rules":["array","nullable"]}')
        ->assertSee('{"handle":"related","type":"entries","required":false,"rules":["array","max:3","nullable"],"collections":["posts"],"max_items":3}')
        ->assertSee('{"handle":"topics","type":"terms","required":false,"rules":["array","nullable"],"taxonomies":["tags"]}')
        ->assertSee('{"handle":"author","type":"users","required":false,"rules":["array","max:1","nullable"],"max_items":1}');
});

it('denies reading a blueprint the user has no permission to view', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    // 'access mcp' only — exposed, but no 'view blog entries'. The schema must
    // not leak through blueprints_get to a user who can't view the content.
    $user = Fixtures::makeUser();

    Server::actingAs($user)
        ->tool(BlueprintsGet::class, ['type' => 'collection', 'handle' => 'blog'])
        ->assertHasErrors(["requires 'view blog entries' — grant it to a role of {$user->email()} in the Control Panel"]);
});

it('returns the blueprint of a form, the fields its submissions carry', function () {
    Fixtures::site();
    Fixtures::form();

    Server::actingAs(Fixtures::makeUser('view contact form submissions'))
        ->tool(BlueprintsGet::class, ['type' => 'form', 'handle' => 'contact'])
        ->assertOk()
        ->assertSee('"type":"form","handle":"contact","blueprint":"contact","available_blueprints":["contact"]')
        ->assertSee('"handle":"name","type":"text","required":true')
        ->assertSee('"handle":"email","type":"text","required":false')
        ->assertSee('"handle":"message","type":"textarea","required":false')
        ->assertSee('"handle":"newsletter","type":"toggle","required":false');
});

it("reads a form blueprint with 'configure forms', and denies it without any form permission", function () {
    Fixtures::site();
    Fixtures::form();

    Server::actingAs(Fixtures::makeUser('configure forms'))
        ->tool(BlueprintsGet::class, ['type' => 'form', 'handle' => 'contact'])
        ->assertOk()
        ->assertSee('"type":"form","handle":"contact"');

    $user = Fixtures::makeUser();

    Server::actingAs($user)
        ->tool(BlueprintsGet::class, ['type' => 'form', 'handle' => 'contact'])
        ->assertHasErrors(["requires 'view contact form submissions' — grant it to a role of {$user->email()} in the Control Panel"]);
});

it('rejects an unknown type via validation', function () {
    Fixtures::site();

    $user = Fixtures::makeUser();

    Server::actingAs($user)
        ->tool(BlueprintsGet::class, ['type' => 'navigation', 'handle' => 'main'])
        ->assertHasErrors();
});

it('reports time_enabled on date fields, including the date field injected into dated collections', function () {
    Fixtures::site();
    Fixtures::news();

    Collection::make('events')->title('Events')->dated(true)->save();

    Blueprint::makeFromFields([
        'title' => ['type' => 'text', 'validate' => 'required'],
    ])->setHandle('event')->setNamespace('collections.events')->save();

    Server::actingAs(Fixtures::makeUser('view news entries'))
        ->tool(BlueprintsGet::class, ['type' => 'collection', 'handle' => 'news'])
        ->assertOk()
        ->assertSee('{"handle":"date","type":"date","required":true,"rules":["required"],"time_enabled":true}');

    Server::actingAs(Fixtures::makeUser('view events entries'))
        ->tool(BlueprintsGet::class, ['type' => 'collection', 'handle' => 'events'])
        ->assertOk()
        ->assertSee('{"handle":"date","type":"date","required":true,"rules":["required"],"time_enabled":false}');
});

it('returns the tabs and sections that carry instructions, with the fields under them', function () {
    Fixtures::site();

    Collection::make('pages')->title('Pages')->save();

    Blueprint::make('page')->setNamespace('collections.pages')->setContents(['tabs' => [
        'main' => ['display' => 'Page', 'sections' => [
            ['display' => 'Basics', 'instructions' => 'One page per service. Follow bike-rental.', 'fields' => [
                ['handle' => 'title', 'field' => ['type' => 'text']],
                ['handle' => 'intro', 'field' => ['type' => 'textarea']],
            ]],
            ['display' => 'Body', 'fields' => [
                ['handle' => 'body', 'field' => ['type' => 'markdown']],
            ]],
        ]],
        'seo' => ['display' => 'SEO', 'instructions' => 'Fill in after the copy is final.', 'sections' => [
            ['fields' => [['handle' => 'meta_title', 'field' => ['type' => 'text']]]],
        ]],
        'sidebar' => ['display' => 'Sidebar', 'sections' => [
            ['fields' => [['handle' => 'slug', 'field' => ['type' => 'slug']]]],
        ]],
    ]])->save();

    Server::actingAs(Fixtures::makeUser('view pages entries'))
        ->tool(BlueprintsGet::class, ['type' => 'collection', 'handle' => 'pages'])
        ->assertOk()
        ->assertSee('"tabs":[{"handle":"main","display":"Page","fields":["title","intro","body"],"sections":[{"display":"Basics","instructions":"One page per service. Follow bike-rental.","fields":["title","intro"]}]},{"handle":"seo","display":"SEO","instructions":"Fill in after the copy is final.","fields":["meta_title"]}],"fields":[');
});

it('sends no tabs when no tab or section has instructions', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    Server::actingAs(Fixtures::makeUser('view blog entries'))
        ->tool(BlueprintsGet::class, ['type' => 'collection', 'handle' => 'blog'])
        ->assertOk()
        ->assertDontSee('"tabs"');
});

it('says which fields are localizable on a multisite install', function () {
    Fixtures::multisite();
    Fixtures::tags();
    Fixtures::blog();

    Blueprint::find('collections.blog.article')->ensureFieldHasConfig('hero_image', ['localizable' => false])->save();

    Server::actingAs(Fixtures::makeUser('view blog entries'))
        ->tool(BlueprintsGet::class, ['type' => 'collection', 'handle' => 'blog'])
        ->assertOk()
        ->assertSee('"handle":"title","type":"text","required":true,"rules":["required"],"localizable":true')
        ->assertSee('"handle":"hero_image","type":"text","required":false,"rules":["nullable"],"localizable":false');
});

it('leaves localizable out on a single-site install', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    Server::actingAs(Fixtures::makeUser('view blog entries'))
        ->tool(BlueprintsGet::class, ['type' => 'collection', 'handle' => 'blog'])
        ->assertOk()
        ->assertDontSee('"localizable"');
});
