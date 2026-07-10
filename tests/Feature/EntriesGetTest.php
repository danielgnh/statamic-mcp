<?php

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tools\EntriesGet;
use Statamic\Facades\Entry;

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
