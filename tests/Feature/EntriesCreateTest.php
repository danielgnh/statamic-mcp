<?php

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tools\EntriesCreate;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

function makeDatedEventsCollection(): void
{
    tap(
        Collection::make('events')
            ->title('Events')
            ->dated(true)
            ->sites(['en'])
            ->routes('/events/{slug}')
    )->save();

    Blueprint::makeFromFields([
        'title' => ['type' => 'text', 'validate' => 'required'],
    ])->setHandle('event')->setNamespace('collections.events')->save();
}

it('creates a draft by default with a slug generated from the title', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    Server::actingAs(Fixtures::makeUser('create blog entries'))
        ->tool(EntriesCreate::class, ['collection' => 'blog', 'data' => ['title' => 'My First Post']])
        ->assertOk()
        ->assertSee('"slug":"my-first-post"')
        ->assertSee('"status":"draft"')
        ->assertSee('saved as draft — not live')
        ->assertSee('"cp_edit_url"');

    $entry = Entry::query()->where('collection', 'blog')->where('slug', 'my-first-post')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->published())->toBeFalse()
        ->and($entry->get('title'))->toBe('My First Post');
});

it("requires 'publish blog entries' for published: true", function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $user = Fixtures::makeUser('create blog entries');

    Server::actingAs($user)
        ->tool(EntriesCreate::class, ['collection' => 'blog', 'data' => ['title' => 'Live Post'], 'published' => true])
        ->assertHasErrors(["requires 'publish blog entries' — grant it to a role of {$user->email()} in the Control Panel"]);

    Server::actingAs(Fixtures::makeUser('create blog entries', 'publish blog entries'))
        ->tool(EntriesCreate::class, ['collection' => 'blog', 'data' => ['title' => 'Live Post'], 'published' => true])
        ->assertOk()
        ->assertSee('"status":"published"')
        ->assertSee('"result":"published"');
});

it('requires date for dated collections', function () {
    Fixtures::site();
    makeDatedEventsCollection();

    Server::actingAs(Fixtures::makeUser('create events entries'))
        ->tool(EntriesCreate::class, ['collection' => 'events', 'data' => ['title' => 'Launch Party']])
        ->assertHasErrors(["collection 'events' is dated — pass date (e.g. 2026-07-09 or 2026-07-09 15:30)"]);

    Server::actingAs(Fixtures::makeUser('create events entries'))
        ->tool(EntriesCreate::class, ['collection' => 'events', 'data' => ['title' => 'Launch Party'], 'date' => '2026-08-01'])
        ->assertOk()
        ->assertSee('"slug":"launch-party"');
});

it('rejects date inside data on a dated collection', function () {
    Fixtures::site();
    makeDatedEventsCollection();

    // The injected 'date' blueprint field would otherwise let a data key
    // silently shadow the top-level date param.
    Server::actingAs(Fixtures::makeUser('create events entries'))
        ->tool(EntriesCreate::class, ['collection' => 'events', 'data' => ['title' => 'Launch Party', 'date' => '2026-08-01']])
        ->assertHasErrors(['pass date as a top-level parameter, not inside data']);
});

it('rejects date on a non-dated collection', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    Server::actingAs(Fixtures::makeUser('create blog entries'))
        ->tool(EntriesCreate::class, ['collection' => 'blog', 'data' => ['title' => 'Hi'], 'date' => '2026-08-01'])
        ->assertHasErrors(["collection 'blog' is not dated — omit date"]);
});

it('rejects a colliding slug with the existing id and points to entries_update', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $existing = tap(
        Entry::make()->collection('blog')->slug('hello-world')->data(['title' => 'Hello'])->published(true)
    )->save();

    Server::actingAs(Fixtures::makeUser('create blog entries'))
        ->tool(EntriesCreate::class, ['collection' => 'blog', 'data' => ['title' => 'Hello World'], 'slug' => 'hello-world'])
        ->assertHasErrors(["slug 'hello-world' already exists in collection 'blog' (site 'en') as entry '{$existing->id()}' — use entries_update to modify it"]);
});

it('rejects unknown data keys with valid handles and a did-you-mean hint', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    Server::actingAs(Fixtures::makeUser('create blog entries'))
        ->tool(EntriesCreate::class, ['collection' => 'blog', 'data' => ['title' => 'Hi', 'hero_imge' => 'x.jpg']])
        ->assertHasErrors(["unknown field hero_imge — valid handles: content, hero_image, title, topic — did you mean 'hero_image' instead of 'hero_imge'?"]);
});

it('returns field-level validation errors from the blueprint', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    // title is required by the article blueprint
    Server::actingAs(Fixtures::makeUser('create blog entries'))
        ->tool(EntriesCreate::class, ['collection' => 'blog', 'data' => ['hero_image' => 'x.jpg']])
        ->assertHasErrors()
        ->assertSee('validation failed');
});

it('denies creating without the create permission', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $user = Fixtures::makeUser('view blog entries');

    Server::actingAs($user)
        ->tool(EntriesCreate::class, ['collection' => 'blog', 'data' => ['title' => 'Hi']])
        ->assertHasErrors(["requires 'create blog entries' — grant it to a role of {$user->email()} in the Control Panel"]);
});

it('refuses to create when the server is read-only', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    config(['statamic.mcp.read_only' => true]);

    // Either the registration gate (shouldRegister) or the in-handler
    // re-check rejects the call — both are errors, which is all that matters.
    Server::actingAs(Fixtures::makeSuper())
        ->tool(EntriesCreate::class, ['collection' => 'blog', 'data' => ['title' => 'Hi']])
        ->assertHasErrors();

    expect(Entry::query()->where('collection', 'blog')->count())->toBe(0);
});
