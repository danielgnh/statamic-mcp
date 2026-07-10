<?php

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tools\EntriesGet;
use Statamic\Facades\Entry;
use Statamic\Facades\Term;

function makeBlogEntryForGet(array $data = [], string $slug = 'hello-world'): Statamic\Contracts\Entries\Entry
{
    return tap(
        Entry::make()
            ->collection('blog')
            ->slug($slug)
            ->data(array_merge(['title' => 'Hello World'], $data))
            ->published(true)
    )->save();
}

function longBardValue(): array
{
    // ~1400 chars encoded — comfortably over the 500-char preview threshold
    return [[
        'type' => 'paragraph',
        'content' => [['type' => 'text', 'text' => str_repeat('Statamic and MCP together at last. ', 40)]],
    ]];
}

it('returns raw entry data by id', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $entry = makeBlogEntryForGet(['hero_image' => 'hero.jpg']);

    Server::actingAs(Fixtures::makeUser('view blog entries'))
        ->tool(EntriesGet::class, ['id' => $entry->id()])
        ->assertOk()
        ->assertSee('"format":"raw"')
        ->assertSee('"title":"Hello World"')
        ->assertSee('"hero_image":"hero.jpg"')
        ->assertSee('"status":"published"')
        ->assertSee('"cp_edit_url"');
});

it('finds an entry by collection and slug', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $entry = makeBlogEntryForGet();

    Server::actingAs(Fixtures::makeUser('view blog entries'))
        ->tool(EntriesGet::class, ['collection' => 'blog', 'slug' => 'hello-world'])
        ->assertOk()
        ->assertSee($entry->id());
});

it('errors when neither id nor collection + slug is given', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    Server::actingAs(Fixtures::makeSuper())
        ->tool(EntriesGet::class, [])
        ->assertHasErrors(['pass id, or collection + slug, to identify the entry']);
});

it('returns augmented data with a do-not-write-back warning when requested', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $entry = makeBlogEntryForGet();

    Server::actingAs(Fixtures::makeUser('view blog entries'))
        ->tool(EntriesGet::class, ['id' => $entry->id(), 'format' => 'augmented'])
        ->assertOk()
        ->assertSee('"format":"augmented"')
        ->assertSee('NEVER send it back into entries_update');
});

it('truncates long bard values to preview objects', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $entry = makeBlogEntryForGet(['content' => longBardValue()]);

    Server::actingAs(Fixtures::makeUser('view blog entries'))
        ->tool(EntriesGet::class, ['id' => $entry->id()])
        ->assertOk()
        ->assertSee('__preview')
        ->assertSee('"truncated":true')
        ->assertSee('NOT writable — fetch raw field before editing');
});

it('returns the full raw bard value when requested via fields', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $entry = makeBlogEntryForGet(['content' => longBardValue()]);

    Server::actingAs(Fixtures::makeUser('view blog entries'))
        ->tool(EntriesGet::class, ['id' => $entry->id(), 'fields' => ['content']])
        ->assertOk()
        ->assertSee('"type":"paragraph"')
        ->assertSee('"type":"text"')
        // fields is a selection, not just a truncation bypass — unselected data stays out
        ->assertDontSee('"title":"Hello World"');
});

it('rejects unknown field handles naming valid ones', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $entry = makeBlogEntryForGet();

    Server::actingAs(Fixtures::makeUser('view blog entries'))
        ->tool(EntriesGet::class, ['id' => $entry->id(), 'fields' => ['bodyy']])
        ->assertHasErrors(['unknown field bodyy — valid handles: content, hero_image, title, topic']);
});

it('treats an entry in an unexposed collection as not found', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $entry = makeBlogEntryForGet();

    config(['statamic.mcp.resources.collections' => []]);

    Server::actingAs(Fixtures::makeSuper())
        ->tool(EntriesGet::class, ['id' => $entry->id()])
        ->assertHasErrors(["entry '{$entry->id()}' not found"]);
});

it('denies fetching a non-default-site entry by id without site access', function () {
    Fixtures::multisite();
    Fixtures::tags();
    Fixtures::blog();

    $entry = tap(
        Entry::make()->collection('blog')->locale('de')->slug('deutscher-beitrag')->data(['title' => 'Deutsch'])->published(true)
    )->save();

    $user = Fixtures::makeUser('view blog entries'); // no 'access de site'

    Server::actingAs($user)
        ->tool(EntriesGet::class, ['id' => $entry->id()])
        ->assertHasErrors(["requires 'access de site' — grant it to a role of {$user->email()} in the Control Panel"]);
});

it('errors when the site param does not match the entry own site on id lookups', function () {
    Fixtures::multisite();
    Fixtures::tags();
    Fixtures::blog();

    $entry = tap(
        Entry::make()->collection('blog')->locale('de')->slug('deutscher-beitrag')->data(['title' => 'Deutsch'])->published(true)
    )->save();

    Server::actingAs(Fixtures::makeUser('view blog entries', 'access de site'))
        ->tool(EntriesGet::class, ['id' => $entry->id(), 'site' => 'en'])
        // de-only entry — no en localization exists, so only de is listed
        ->assertHasErrors(["entry '{$entry->id()}' belongs to site 'de', not 'en' — pass the matching localization id instead (or omit site). Localizations: de => {$entry->id()}"]);
});

it('fetches a non-default-site entry by id with site access granted', function () {
    Fixtures::multisite();
    Fixtures::tags();
    Fixtures::blog();

    $entry = tap(
        Entry::make()->collection('blog')->locale('de')->slug('deutscher-beitrag')->data(['title' => 'Deutsch'])->published(true)
    )->save();

    Server::actingAs(Fixtures::makeUser('view blog entries', 'access de site'))
        ->tool(EntriesGet::class, ['id' => $entry->id()])
        ->assertOk()
        ->assertSee('"site":"de"');
});

it('returns shallow relation stubs in augmented format', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    tap(Term::make('news')->taxonomy('tags')->dataForLocale('en', ['title' => 'News']))->save();

    $entry = makeBlogEntryForGet(['topic' => 'news']);

    Server::actingAs(Fixtures::makeUser('view blog entries'))
        ->tool(EntriesGet::class, ['id' => $entry->id(), 'format' => 'augmented'])
        ->assertOk()
        ->assertSee('"title":"News"')
        ->assertSee('"api_url"')
        // shallow stub only — the term's own reverse relations must not be inlined
        ->assertDontSee('"entries"');
});

it('keeps multibyte previews intact when truncating', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $entry = makeBlogEntryForGet(['content' => [[
        'type' => 'paragraph',
        'content' => [['type' => 'text', 'text' => str_repeat('Über die Zukunft des Ökosystems entscheiden wir. ', 20)]],
    ]]]);

    Server::actingAs(Fixtures::makeUser('view blog entries'))
        ->tool(EntriesGet::class, ['id' => $entry->id()])
        ->assertOk()
        ->assertSee('"truncated":true')
        ->assertSee('Über die Zukunft des Ökosystems')
        ->assertDontSee('�'); // no bytes cut mid-character
});

it('strips updated_at and updated_by from raw data', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $entry = makeBlogEntryForGet(['updated_at' => 1719999999, 'updated_by' => 'stale-user-id']);

    Server::actingAs(Fixtures::makeUser('view blog entries'))
        ->tool(EntriesGet::class, ['id' => $entry->id()])
        ->assertOk()
        ->assertDontSee('"updated_at"')
        ->assertDontSee('"updated_by"');
});

it('denies reading without the view permission', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $entry = makeBlogEntryForGet();
    $user = Fixtures::makeUser(); // 'access mcp' only

    Server::actingAs($user)
        ->tool(EntriesGet::class, ['id' => $entry->id()])
        ->assertHasErrors(["requires 'view blog entries' — grant it to a role of {$user->email()} in the Control Panel"]);
});
