<?php

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tools\EntriesCreate;
use Illuminate\Support\Facades\Event;
use Statamic\Events\EntryCreating;
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

    $user = Fixtures::makeUser('create blog entries');

    Server::actingAs($user)
        ->tool(EntriesCreate::class, ['collection' => 'blog', 'data' => ['title' => 'My First Post']])
        ->assertOk()
        ->assertSee('"slug":"my-first-post"')
        ->assertSee('"status":"draft"')
        ->assertSee('saved as draft — not live')
        ->assertSee('"cp_edit_url"');

    $entry = Entry::query()->where('collection', 'blog')->where('slug', 'my-first-post')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->published())->toBeFalse()
        ->and($entry->get('title'))->toBe('My First Post')
        ->and($entry->get('updated_by'))->toBe($user->id()); // CP parity: creates carry updated_by/updated_at
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

it('creates entries on a blueprint that marks slug as required', function () {
    Fixtures::site();
    Fixtures::pages();

    // The resolved slug must reach blueprint validation — slug is barred from
    // data and lives as a top-level param, so without the merge a required
    // slug field would be unsatisfiable (catch-22).
    Server::actingAs(Fixtures::makeUser('create pages entries'))
        ->tool(EntriesCreate::class, ['collection' => 'pages', 'data' => ['title' => 'About Us']])
        ->assertOk()
        ->assertSee('"slug":"about-us"');

    Server::actingAs(Fixtures::makeUser('create pages entries'))
        ->tool(EntriesCreate::class, ['collection' => 'pages', 'data' => ['title' => 'Contact'], 'slug' => 'get-in-touch'])
        ->assertOk()
        ->assertSee('"slug":"get-in-touch"');
});

it('rejects slug inside data with a targeted message', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    Server::actingAs(Fixtures::makeUser('create blog entries'))
        ->tool(EntriesCreate::class, ['collection' => 'blog', 'data' => ['title' => 'Hi', 'slug' => 'hi']])
        ->assertHasErrors(['pass slug as a top-level parameter, not inside data']);
});

it('returns field-level validation errors from the blueprint', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    // title is required by the article blueprint (slug passed explicitly —
    // its resolution runs first and would otherwise mask the field errors)
    Server::actingAs(Fixtures::makeUser('create blog entries'))
        ->tool(EntriesCreate::class, ['collection' => 'blog', 'data' => ['hero_image' => 'x.jpg'], 'slug' => 'post'])
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

it('normalizes the slug the way Statamic will persist it before checking collisions', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $existing = tap(
        Entry::make()->collection('blog')->slug('hello-world')->data(['title' => 'Hello'])->published(true)
    )->save();

    // Entry::save() re-normalizes 'Hello World' to 'hello-world' — a raw-value
    // collision query would miss this and silently produce two entries, one URL.
    Server::actingAs(Fixtures::makeUser('create blog entries'))
        ->tool(EntriesCreate::class, ['collection' => 'blog', 'data' => ['title' => 'Fresh'], 'slug' => 'Hello World'])
        ->assertHasErrors(["slug 'hello-world' already exists in collection 'blog' (site 'en') as entry '{$existing->id()}' — use entries_update to modify it"]);
});

it('generates the slug with the target site language (CP parity)', function () {
    Fixtures::multisite();
    Fixtures::tags();
    Fixtures::blog();

    Server::actingAs(Fixtures::makeUser('create blog entries', 'access de site'))
        ->tool(EntriesCreate::class, ['collection' => 'blog', 'data' => ['title' => 'Über Uns'], 'site' => 'de'])
        ->assertOk()
        ->assertSee('"slug":"ueber-uns"'); // de transliterates Ü → Ue; en would give 'uber-uns'
});

it('rejects a title that normalizes to an empty slug', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    Server::actingAs(Fixtures::makeUser('create blog entries'))
        ->tool(EntriesCreate::class, ['collection' => 'blog', 'data' => ['title' => '🎉🎉🎉']])
        ->assertHasErrors(['could not derive a slug from the title — pass slug explicitly']);

    expect(Entry::query()->where('collection', 'blog')->count())->toBe(0);
});

it('reports a listener-cancelled save instead of claiming success', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    // Approval-workflow addons cancel saves by returning false from
    // EntryCreating/EntrySaving; Entry::save() then returns false.
    Event::listen(EntryCreating::class, fn () => false);

    Server::actingAs(Fixtures::makeUser('create blog entries'))
        ->tool(EntriesCreate::class, ['collection' => 'blog', 'data' => ['title' => 'Hi']])
        ->assertHasErrors(['the save was cancelled by a listener on this site — nothing was created']);

    expect(Entry::query()->where('collection', 'blog')->count())->toBe(0);
});

it('rejects a site the collection is not configured for', function () {
    Fixtures::multisite();

    tap(
        Collection::make('docs')->title('Docs')->sites(['en'])->routes('/docs/{slug}')
    )->save();

    Blueprint::makeFromFields([
        'title' => ['type' => 'text', 'validate' => 'required'],
    ])->setHandle('doc')->setNamespace('collections.docs')->save();

    Server::actingAs(Fixtures::makeUser('create docs entries', 'access de site'))
        ->tool(EntriesCreate::class, ['collection' => 'docs', 'data' => ['title' => 'Hi'], 'site' => 'de'])
        ->assertHasErrors(["collection 'docs' is not available in site 'de' — available sites: en"]);
});

it('applies rule placeholder replacements so unique_entry_value scopes to the collection', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    tap(
        Collection::make('products')->title('Products')->sites(['en'])->routes('/products/{slug}')
    )->save();

    Blueprint::makeFromFields([
        'title' => ['type' => 'text', 'validate' => 'required'],
        'sku' => ['type' => 'text', 'validate' => ['new \Statamic\Rules\UniqueEntryValue({collection}, {id}, {site})']],
    ])->setHandle('product')->setNamespace('collections.products')->save();

    // Same sku in ANOTHER collection: an unreplaced {collection} placeholder
    // becomes null and the rule queries ALL collections — this entry must not
    // block a products create once replacements are wired.
    tap(
        Entry::make()->collection('blog')->slug('other')->data(['title' => 'Other', 'sku' => 'ABC-1'])->published(true)
    )->save();

    tap(
        Entry::make()->collection('products')->slug('first')->data(['title' => 'First', 'sku' => 'ABC-2'])->published(true)
    )->save();

    // Duplicate within products → field-level validation error.
    Server::actingAs(Fixtures::makeUser('create products entries'))
        ->tool(EntriesCreate::class, ['collection' => 'products', 'data' => ['title' => 'Dupe', 'sku' => 'ABC-2']])
        ->assertHasErrors()
        ->assertSee('validation failed');

    // Same sku existing only in blog → scoped rule passes.
    Server::actingAs(Fixtures::makeUser('create products entries'))
        ->tool(EntriesCreate::class, ['collection' => 'products', 'data' => ['title' => 'Fine', 'sku' => 'ABC-1']])
        ->assertOk();
});

it('rejects reserved keys in data even when the blueprint defines them', function () {
    Fixtures::site();

    tap(
        Collection::make('pages')->title('Pages')->sites(['en'])->routes('/pages/{slug}')
    )->save();

    // A 'published' blueprint field would otherwise let a create-only user
    // persist publish state through data (data keys shadow front matter).
    Blueprint::makeFromFields([
        'title' => ['type' => 'text', 'validate' => 'required'],
        'published' => ['type' => 'toggle'],
    ])->setHandle('page')->setNamespace('collections.pages')->save();

    Server::actingAs(Fixtures::makeUser('create pages entries'))
        ->tool(EntriesCreate::class, ['collection' => 'pages', 'data' => ['title' => 'Hi', 'published' => true]])
        ->assertHasErrors(['field published is reserved — never writable via data']);
});
