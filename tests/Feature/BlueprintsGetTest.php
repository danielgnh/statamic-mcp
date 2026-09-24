<?php

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tools\BlueprintsGet;
use Danielgnh\StatamicMcp\Tools\EntriesCreate;
use Illuminate\Support\Arr;
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
        // v6 appends a 'slug' field to entry blueprints of routed collections (Collection::ensureEntryBlueprintFields)
        ->assertSee('"example":{"title":"Example text","content":null,"hero_image":"Example text","topic":"REPLACE-WITH-REAL-TERM-ID","slug":"example-slug"}');
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
        ->assertSee('"example_notes":{"content":"no example generated for fieldtype \'bard\' — read a real value from existing content before writing this field"}');
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
        ->assertSee('"example":{"title":"Example text","slug":"example-slug"}');
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
        // trailing 'slug' is v6's auto-appended entry blueprint field; dates use the
        // default save format 'Y-m-d H:i', the shape entries_get returns
        ->assertSee('"example":{"title":"Example text","color":"red","featured":true,"priority":42,"launch_date":"2026-01-15 09:30","slug":"example-slug"}');
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

it('leaves hidden sets out of the replicator example', function () {
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
        ->assertSee('"page_builder":[{"type":"section_text","text":"A longer example paragraph of plain text."}]');
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

it('gives a replicator one set, a grid one row, and a group one object as examples', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');
    Fixtures::landing();

    Server::actingAs(Fixtures::makeUser('view landing entries'))
        ->tool(BlueprintsGet::class, ['type' => 'collection', 'handle' => 'landing'])
        ->assertOk()
        ->assertSee('"page_builder":[{"type":"section_hero","heading":"Example text","image":"REPLACE-WITH-REAL-ASSET-PATH"}]')
        ->assertSee('"facts":[{"label":"Example text"}]')
        ->assertSee('"seo":{"meta_title":"Example text"}')
        ->assertSee('"page_builder":"shows one section_hero set — sets lists every set type with its fields. Each set is an object with its type plus its field values; id and enabled are optional."')
        // notes inside the example are keyed by their path in it
        ->assertSee('"page_builder.0.image":"stores asset paths relative to the container root (container \'images\') — max_files is 1, so pass a single string path.');
});

it('builds nested examples all the way down, leaving computed fields out', function () {
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
        ->tool(BlueprintsGet::class, ['type' => 'collection', 'handle' => 'pages'])
        ->assertOk()
        ->assertSee('"page_builder":[{"type":"section_stats","stats":[{"value":42}],"actions":[{"type":"link","url":null}]}]')
        ->assertSee('"page_builder.0.total":"computed — not writable"')
        ->assertSee('"page_builder.0.actions":"shows one link set')
        ->assertSee('"page_builder.0.actions.0.url":"no example generated for fieldtype \'link\'');
});

it('shows how a set node looks in the note of a bard field with sets', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');
    Fixtures::landing();

    Server::actingAs(Fixtures::makeUser('view landing entries'))
        ->tool(BlueprintsGet::class, ['type' => 'collection', 'handle' => 'landing'])
        ->assertOk()
        ->assertSee('"body":null')
        ->assertSee('"body":"no example generated for fieldtype \'bard\' — send an HTML string, which is converted to ProseMirror nodes, or the nodes themselves. A set is a node {\"type\":\"set\",\"attrs\":{\"values\":{\"type\":\"callout\", ...its field values}}}, and sets lists every set type; HTML cannot hold sets."');
});

it('returns an example that entries_create stores, sets and all', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');
    Fixtures::landing();

    Storage::disk('images')->put('hero.jpg', Fixtures::tinyPng());

    $user = Fixtures::makeUser('view landing entries', 'create landing entries');

    $this->actingAs($user);

    $content = (string) (new BlueprintsGet)->handle(new Request(['type' => 'collection', 'handle' => 'landing']))->content();
    $example = data_get(json_decode(str_replace('REPLACE-WITH-REAL-ASSET-PATH', 'hero.jpg', $content), true), 'example');

    // slug is a top-level parameter of entries_create, never a data key
    Server::actingAs($user)
        ->tool(EntriesCreate::class, ['collection' => 'landing', 'data' => Arr::except($example, 'slug')])
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

it('rejects an unknown type via validation', function () {
    Fixtures::site();

    $user = Fixtures::makeUser();

    Server::actingAs($user)
        ->tool(BlueprintsGet::class, ['type' => 'navigation', 'handle' => 'main'])
        ->assertHasErrors();
});
