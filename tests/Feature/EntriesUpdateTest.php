<?php

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tools\EntriesGet;
use Danielgnh\StatamicMcp\Tools\EntriesUpdate;
use Illuminate\Support\Facades\Event;
use Statamic\Events\EntrySaved;
use Statamic\Events\EntrySaving;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

function makeUpdatableBlogEntry(array $data = []): Statamic\Contracts\Entries\Entry
{
    return tap(
        Entry::make()
            ->collection('blog')
            ->slug('hello-world')
            ->data(array_merge(['title' => 'Hello World', 'hero_image' => 'hero.jpg'], $data))
            ->published(true)
    )->save();
}

function makeUpdatableDatedEvent(): Statamic\Contracts\Entries\Entry
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

    return tap(
        Entry::make()->collection('events')->slug('launch-party')->data(['title' => 'Launch Party'])->date('2026-08-01')->published(true)
    )->save();
}

it('merges top-level keys shallowly, preserving untouched fields and publish state', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $entry = makeUpdatableBlogEntry();

    Server::actingAs(Fixtures::makeUser('edit blog entries'))
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['title' => 'Hello Again']])
        ->assertOk()
        ->assertSee('"result":"published"') // publish state untouched
        ->assertSee('"cp_edit_url"');

    $fresh = Entry::find($entry->id());

    expect($fresh->get('title'))->toBe('Hello Again')
        ->and($fresh->get('hero_image'))->toBe('hero.jpg')
        ->and($fresh->published())->toBeTrue();
});

it('replaces nested structures wholesale, never deep-merging', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $entry = makeUpdatableBlogEntry(['content' => [
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Old paragraph one.']]],
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Old paragraph two.']]],
    ]]);

    $newContent = [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'The only paragraph now.']]]];

    Server::actingAs(Fixtures::makeUser('edit blog entries'))
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['content' => $newContent]])
        ->assertOk();

    expect(Entry::find($entry->id())->get('content'))->toBe($newContent);
});

it('stores an explicit null to clear a field', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $entry = makeUpdatableBlogEntry();

    Server::actingAs(Fixtures::makeUser('edit blog entries'))
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['hero_image' => null]])
        ->assertOk();

    $fresh = Entry::find($entry->id());

    expect($fresh->data()->has('hero_image'))->toBeTrue() // a local null, not an absent key
        ->and($fresh->get('hero_image'))->toBeNull();
});

it('errors when clearing a required field, via merged validation', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $entry = makeUpdatableBlogEntry();

    Server::actingAs(Fixtures::makeUser('edit blog entries'))
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['title' => null]])
        ->assertHasErrors()
        ->assertSee('validation failed');
});

it('does not false-fail required fields on partial updates', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $entry = makeUpdatableBlogEntry();

    // title stays present via the merge — updating only hero_image must pass
    Server::actingAs(Fixtures::makeUser('edit blog entries'))
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['hero_image' => 'new.jpg']])
        ->assertOk()
        ->assertHasNoErrors();
});

it('updates entries on a blueprint that marks slug as required', function () {
    Fixtures::site();
    Fixtures::pages();

    $entry = tap(
        Entry::make()->collection('pages')->slug('about-us')->data(['title' => 'About Us'])->published(true)
    )->save();

    // Entries never store slug in data, so merged validation must be fed the
    // entry's own slug — otherwise a required slug field fails every update.
    Server::actingAs(Fixtures::makeUser('edit pages entries'))
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['title' => 'About']])
        ->assertOk()
        ->assertHasNoErrors();

    expect(Entry::find($entry->id())->get('title'))->toBe('About');
});

it('is a no-op when merged data equals current data', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $entry = makeUpdatableBlogEntry();

    Event::fake([EntrySaved::class]);

    Server::actingAs(Fixtures::makeUser('edit blog entries'))
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['title' => 'Hello World']])
        ->assertOk()
        ->assertSee('no-op');

    Event::assertNotDispatched(EntrySaved::class); // nothing was saved
});

it('changes publish state only when published is sent explicitly', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $entry = makeUpdatableBlogEntry();

    // CP parity: the unpublish route authorizes 'publish' too
    // (PublishedEntriesController::destroy) — the gate is on any transition.
    Server::actingAs(Fixtures::makeUser('edit blog entries', 'publish blog entries'))
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['title' => 'Hello World'], 'published' => false])
        ->assertOk()
        ->assertSee('saved as draft — not live');

    expect(Entry::find($entry->id())->published())->toBeFalse();
});

it("requires 'publish blog entries' to set published: true", function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $entry = tap(
        Entry::make()->collection('blog')->slug('a-draft')->data(['title' => 'Draft'])->published(false)
    )->save();

    $user = Fixtures::makeUser('edit blog entries');

    Server::actingAs($user)
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['title' => 'Draft'], 'published' => true])
        ->assertHasErrors(["requires 'publish blog entries' — grant it to a role of {$user->email()} in the Control Panel"]);

    Server::actingAs(Fixtures::makeUser('edit blog entries', 'publish blog entries'))
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['title' => 'Draft'], 'published' => true])
        ->assertOk()
        ->assertSee('"result":"published"');
});

it("requires 'publish blog entries' to unpublish (CP parity)", function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $entry = makeUpdatableBlogEntry();
    $user = Fixtures::makeUser('edit blog entries');

    // The CP's unpublish action authorizes 'publish' (there is no separate
    // unpublish permission in v6) — same gate here, on the transition to false.
    Server::actingAs($user)
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['title' => 'Hello World'], 'published' => false])
        ->assertHasErrors(["requires 'publish blog entries' — grant it to a role of {$user->email()} in the Control Panel"]);

    expect(Entry::find($entry->id())->published())->toBeTrue();
});

it('needs no publish permission when published matches the current state', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $entry = makeUpdatableBlogEntry();

    // No transition — sending the current state is harmless, and here nothing
    // else changed either, so it resolves as a no-op.
    Server::actingAs(Fixtures::makeUser('edit blog entries'))
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['title' => 'Hello World'], 'published' => true])
        ->assertOk()
        ->assertSee('no-op');
});

it('rejects a mismatched site selector, listing localization ids', function () {
    Fixtures::multisite();
    Fixtures::tags();
    Fixtures::blog();

    $origin = tap(
        Entry::make()->collection('blog')->slug('hello')->locale('en')->data(['title' => 'Hello'])->published(true)
    )->save();

    $localization = tap($origin->makeLocalization('de')->data(['title' => 'Hallo']))->save();

    Server::actingAs(Fixtures::makeSuper())
        ->tool(EntriesUpdate::class, ['id' => $origin->id(), 'data' => ['title' => 'Hi'], 'site' => 'de'])
        ->assertHasErrors([
            "entry '{$origin->id()}' belongs to site 'en', not 'de' — pass the matching localization id instead (or omit site). Localizations: en => {$origin->id()}; de => {$localization->id()}",
        ]);
});

it('rejects unknown data keys with a did-you-mean hint', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $entry = makeUpdatableBlogEntry();

    Server::actingAs(Fixtures::makeUser('edit blog entries'))
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['titel' => 'Hi']])
        ->assertHasErrors(["unknown field titel — valid handles: content, hero_image, title, topic — did you mean 'title' instead of 'titel'?"]);
});

it('denies updating without the edit permission', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $entry = makeUpdatableBlogEntry();
    $user = Fixtures::makeUser('view blog entries');

    Server::actingAs($user)
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['title' => 'Hi']])
        ->assertHasErrors(["requires 'edit blog entries' — grant it to a role of {$user->email()} in the Control Panel"]);
});

it('rejects preview-object values, pointing at the raw fetch', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $entry = makeUpdatableBlogEntry();

    // The one raw-path artifact an agent can accidentally round-trip: the
    // truncated {__preview, truncated, note} shape from entries_get.
    Server::actingAs(Fixtures::makeUser('edit blog entries'))
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['content' => [
            '__preview' => 'Old paragraph one. Old paragraph two…',
            'truncated' => true,
            'note' => 'NOT writable — fetch raw field before editing: entries_get with fields: ["content"]',
        ]]])
        ->assertHasErrors(['field content is a truncated preview object from entries_get, not raw content — fetch the raw value first (entries_get with fields: ["content"]) and send that back']);

    expect(Entry::find($entry->id())->get('content'))->toBeNull(); // nothing written
});

it('ignores stale updated_at and updated_by keys in the patch', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $entry = makeUpdatableBlogEntry();

    // Statamic-managed metadata — stripped, so a stale copy in agent context
    // can neither dirty the entry (no-op below) nor overwrite real values.
    Server::actingAs(Fixtures::makeUser('edit blog entries'))
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => [
            'title' => 'Hello World',
            'updated_at' => 12345,
            'updated_by' => 'stale-user-id',
        ]])
        ->assertOk()
        ->assertSee('no-op');

    $user = Fixtures::makeUser('edit blog entries');

    Server::actingAs($user)
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => [
            'title' => 'Hello Again',
            'updated_by' => 'stale-user-id',
        ]])
        ->assertOk();

    expect(Entry::find($entry->id())->get('updated_by'))->toBe($user->id()); // the acting user, never the stale copy
});

it('normalizes a new slug the way Statamic will persist it', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $entry = makeUpdatableBlogEntry();

    Server::actingAs(Fixtures::makeUser('edit blog entries'))
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['title' => 'Hello World'], 'slug' => 'Hello Again!'])
        ->assertOk()
        ->assertSee('"slug":"hello-again"');

    expect(Entry::find($entry->id())->slug())->toBe('hello-again');
});

it('updates the slug alone with an empty data object', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $entry = makeUpdatableBlogEntry();

    // The UX papercut this pins: Laravel's 'required' rule fails on [] — an
    // agent changing only the slug must not be forced to invent a data patch.
    Server::actingAs(Fixtures::makeUser('edit blog entries'))
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => [], 'slug' => 'hello-again'])
        ->assertOk()
        ->assertSee('"slug":"hello-again"');

    $fresh = Entry::find($entry->id());

    expect($fresh->slug())->toBe('hello-again')
        ->and($fresh->get('title'))->toBe('Hello World');
});

it('still requires the data key to be present', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $entry = makeUpdatableBlogEntry();

    Server::actingAs(Fixtures::makeUser('edit blog entries'))
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'slug' => 'hello-again'])
        ->assertHasErrors(['Pass data to merge (may be an empty object when only changing slug, date, or published).']);

    expect(Entry::find($entry->id())->slug())->toBe('hello-world');
});

it('rejects a slug colliding with another entry, but never with itself', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $entry = makeUpdatableBlogEntry();

    $other = tap(
        Entry::make()->collection('blog')->slug('taken')->data(['title' => 'Taken'])->published(true)
    )->save();

    Server::actingAs(Fixtures::makeUser('edit blog entries'))
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['title' => 'Hello World'], 'slug' => 'taken'])
        ->assertHasErrors(["slug 'taken' already exists in collection 'blog' (site 'en') as entry '{$other->id()}'"]);

    // Its own slug (even un-normalized) is not a collision — it's a no-op.
    Server::actingAs(Fixtures::makeUser('edit blog entries'))
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['title' => 'Hello World'], 'slug' => 'Hello World'])
        ->assertOk()
        ->assertSee('no-op');

    expect(Entry::find($entry->id())->slug())->toBe('hello-world');
});

it('rejects a slug that normalizes to empty', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $entry = makeUpdatableBlogEntry();

    Server::actingAs(Fixtures::makeUser('edit blog entries'))
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['title' => 'Hello World'], 'slug' => '🎉🎉🎉'])
        ->assertHasErrors(["slug '🎉🎉🎉' normalizes to an empty string — pass a usable slug"]);

    expect(Entry::find($entry->id())->slug())->toBe('hello-world');
});

it('rejects the data-key spelling of slug with a targeted remedy', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $entry = makeUpdatableBlogEntry();

    // v6 injects 'slug' into blueprints, but it is a dedicated top-level
    // parameter — the generic unknown-field error would give no usable hint.
    Server::actingAs(Fixtures::makeUser('edit blog entries'))
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['slug' => 'other']])
        ->assertHasErrors(['pass slug as a top-level parameter, not inside data']);

    expect(Entry::find($entry->id())->slug())->toBe('hello-world');
});

it('updates the date of a dated entry, rejecting the data-key spelling', function () {
    Fixtures::site();
    $entry = makeUpdatableDatedEvent();

    Server::actingAs(Fixtures::makeUser('edit events entries'))
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['date' => '2026-09-01']])
        ->assertHasErrors(['pass date as a top-level parameter, not inside data']);

    // The injected required 'date' blueprint field is satisfied by the
    // resolved Carbon — a plain string (or a missing value) would false-fail.
    Server::actingAs(Fixtures::makeUser('edit events entries'))
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['title' => 'Launch Party'], 'date' => '2026-09-01'])
        ->assertOk();

    expect(Entry::find($entry->id())->date()->format('Y-m-d'))->toBe('2026-09-01');
});

it('rejects date on a non-dated collection', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $entry = makeUpdatableBlogEntry();

    Server::actingAs(Fixtures::makeUser('edit blog entries'))
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['title' => 'Hello World'], 'date' => '2026-09-01'])
        ->assertHasErrors(["collection 'blog' is not dated — omit date"]);
});

it('refuses to update when the server is read-only', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $entry = makeUpdatableBlogEntry();

    config(['statamic.mcp.read_only' => true]);

    // Either the registration gate (shouldRegister) or the in-handler
    // re-check rejects the call — both are errors, which is all that matters.
    Server::actingAs(Fixtures::makeSuper())
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['title' => 'Hi']])
        ->assertHasErrors();

    expect(Entry::find($entry->id())->get('title'))->toBe('Hello World');
});

it('reports a listener-cancelled save instead of claiming success', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $entry = makeUpdatableBlogEntry();

    // Approval-workflow addons cancel saves by returning false from
    // EntrySaving; Entry::save() then returns false.
    Event::listen(EntrySaving::class, fn () => false);

    Server::actingAs(Fixtures::makeUser('edit blog entries'))
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['title' => 'Hello Again']])
        ->assertHasErrors(['the save was cancelled by a listener on this site — the entry was not updated']);
});

it('saves an explicit null over an empty-string value instead of a false no-op', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    // Loose == juggles null == '' — a false no-op here would silently drop
    // the write, making explicit null unable to clear any falsy field.
    $entry = makeUpdatableBlogEntry(['hero_image' => '']);

    Server::actingAs(Fixtures::makeUser('edit blog entries'))
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['hero_image' => null]])
        ->assertOk()
        ->assertDontSee('no-op');

    $fresh = Entry::find($entry->id());

    expect($fresh->data()->has('hero_image'))->toBeTrue()
        ->and($fresh->get('hero_image'))->toBeNull();
});

it('treats a type-corrective update as dirty', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    // '1' == 1 under loose comparison — fixing a stringly-typed value must save.
    $entry = makeUpdatableBlogEntry(['hero_image' => '1']);

    Server::actingAs(Fixtures::makeUser('edit blog entries'))
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['hero_image' => 1]])
        ->assertOk()
        ->assertDontSee('no-op');

    expect(Entry::find($entry->id())->get('hero_image'))->toBe(1);
});

it('is a no-op when only nested associative key order differs', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $entry = makeUpdatableBlogEntry(['content' => [
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Hi.']]],
    ]]);

    Event::fake([EntrySaved::class]);

    // Same values, assoc keys reordered at every level — the recursive ksort
    // normalization must see through it (list order still counts as content).
    Server::actingAs(Fixtures::makeUser('edit blog entries'))
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['content' => [
            ['content' => [['text' => 'Hi.', 'type' => 'text']], 'type' => 'paragraph'],
        ]]])
        ->assertOk()
        ->assertSee('no-op');

    Event::assertNotDispatched(EntrySaved::class);
});

it('severs only the written field on a localization, keeping the rest inherited', function () {
    Fixtures::multisite();
    Fixtures::tags();
    Fixtures::blog();

    $origin = tap(
        Entry::make()->collection('blog')->slug('hello')->locale('en')->data(['title' => 'Hello', 'hero_image' => 'hero.jpg'])->published(true)
    )->save();

    $localization = tap($origin->makeLocalization('de')->data(['title' => 'Hallo']))->save();

    Server::actingAs(Fixtures::makeSuper())
        ->tool(EntriesUpdate::class, ['id' => $localization->id(), 'data' => ['title' => 'Servus']])
        ->assertOk();

    // The annotation contract: only the written field becomes a local
    // override; everything else keeps inheriting from the origin.
    Server::actingAs(Fixtures::makeSuper())
        ->tool(EntriesGet::class, ['id' => $localization->id()])
        ->assertSee('"local_overrides":["title"]')
        ->assertSee('"inherited_from_origin":["hero_image"]');

    $fresh = Entry::find($localization->id());

    expect($fresh->data()->has('hero_image'))->toBeFalse() // not copied into own data
        ->and($fresh->value('hero_image'))->toBe('hero.jpg') // still resolved via the origin
        ->and($fresh->get('title'))->toBe('Servus');
});

it('creates a local override when re-sending an inherited value', function () {
    Fixtures::multisite();
    Fixtures::tags();
    Fixtures::blog();

    $origin = tap(
        Entry::make()->collection('blog')->slug('hello')->locale('en')->data(['title' => 'Hello', 'hero_image' => 'hero.jpg'])->published(true)
    )->save();

    $localization = tap($origin->makeLocalization('de')->data(['title' => 'Hallo']))->save();

    // The dirty check compares OWN data, not resolved values(): re-sending a
    // value the entry currently inherits IS a change — it pins the field as
    // a local override that no longer follows the origin.
    Server::actingAs(Fixtures::makeSuper())
        ->tool(EntriesUpdate::class, ['id' => $localization->id(), 'data' => ['hero_image' => 'hero.jpg']])
        ->assertOk()
        ->assertDontSee('no-op');

    Server::actingAs(Fixtures::makeSuper())
        ->tool(EntriesGet::class, ['id' => $localization->id()])
        ->assertSee('"local_overrides":["title","hero_image"]');

    expect(Entry::find($localization->id())->data()->has('hero_image'))->toBeTrue();
});

it('scopes slug collisions to the localization site', function () {
    Fixtures::multisite();
    Fixtures::tags();
    Fixtures::blog();

    $origin = tap(
        Entry::make()->collection('blog')->slug('hello')->locale('en')->data(['title' => 'Hello'])->published(true)
    )->save();

    $localization = tap($origin->makeLocalization('de')->data(['title' => 'Hallo']))->save();

    tap(
        Entry::make()->collection('blog')->slug('greetings')->locale('en')->data(['title' => 'Greetings'])->published(true)
    )->save();

    $taken = tap(
        Entry::make()->collection('blog')->slug('besetzt')->locale('de')->data(['title' => 'Besetzt'])->published(true)
    )->save();

    // 'greetings' exists only in en — no collision for the de localization.
    Server::actingAs(Fixtures::makeSuper())
        ->tool(EntriesUpdate::class, ['id' => $localization->id(), 'data' => ['title' => 'Hallo'], 'slug' => 'greetings'])
        ->assertOk()
        ->assertSee('"slug":"greetings"');

    // 'besetzt' exists in de — collision, naming the de entry.
    Server::actingAs(Fixtures::makeSuper())
        ->tool(EntriesUpdate::class, ['id' => $localization->id(), 'data' => ['title' => 'Hallo'], 'slug' => 'besetzt'])
        ->assertHasErrors(["slug 'besetzt' already exists in collection 'blog' (site 'de') as entry '{$taken->id()}'"]);
});

it('rejects an empty date instead of silently ignoring it', function () {
    Fixtures::site();
    $entry = makeUpdatableDatedEvent();

    Server::actingAs(Fixtures::makeUser('edit events entries'))
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['title' => 'Launch Party'], 'date' => ''])
        ->assertHasErrors(['date is empty — pass e.g. 2026-07-09 or 2026-07-09 15:30, or omit date']);

    expect(Entry::find($entry->id())->date()->format('Y-m-d'))->toBe('2026-08-01');
});
