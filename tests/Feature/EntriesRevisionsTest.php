<?php

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tools\EntriesCreate;
use Danielgnh\StatamicMcp\Tools\EntriesGet;
use Danielgnh\StatamicMcp\Tools\EntriesUpdate;
use Illuminate\Support\Facades\File;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Revision;

// The revisions Stache store directory is fixed at boot and redirected into
// tests/__fixtures__/dev-null by PreventsSavingStacheItemsToDisk (wiped per
// test) — so file assertions use Revision::directory(), never a runtime
// statamic.revisions.path override (which would be inert post-boot).
function enableBlogRevisions(): void
{
    config([
        'statamic.editions.pro' => true, // revisionsEnabled() requires Statamic Pro
        'statamic.revisions.enabled' => true,
    ]);

    Collection::findByHandle('blog')->revisionsEnabled(true)->save();
}

function makePublishedRevisableEntry(): Statamic\Contracts\Entries\Entry
{
    return tap(
        Entry::make()
            ->collection('blog')
            ->slug('live-post')
            ->data(['title' => 'Live Title'])
            ->published(true)
    )->save();
}

it('writes a working copy for a published entry, leaving the live entry unchanged', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();
    enableBlogRevisions();

    $entry = makePublishedRevisableEntry();
    $user = Fixtures::makeUser('edit blog entries');

    Server::actingAs($user)
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['title' => 'Edited Title']])
        ->assertOk()
        ->assertSee('working copy created — live entry unchanged');

    $fresh = Entry::find($entry->id());

    expect($fresh->get('title'))->toBe('Live Title')   // live entry untouched
        ->and($fresh->published())->toBeTrue()
        ->and($fresh->hasWorkingCopy())->toBeTrue();

    // Attribution: the working.yaml on disk names the acting user and the tool
    $workingYaml = collect(File::allFiles(Revision::directory()))
        ->first(fn ($file) => $file->getFilename() === 'working.yaml');

    expect($workingYaml)->not->toBeNull();

    $contents = File::get($workingYaml->getPathname());

    expect($contents)->toContain('via MCP entries_update')
        ->toContain((string) $user->id())
        ->toContain('Edited Title');
});

it('rejects any explicit published value on update in a revision-enabled collection', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();
    enableBlogRevisions();

    $entry = makePublishedRevisableEntry();

    foreach ([true, false] as $published) {
        Server::actingAs(Fixtures::makeSuper())
            ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['title' => 'X'], 'published' => $published])
            ->assertHasErrors(["collection 'blog' uses revisions — publish/unpublish from the Control Panel, not via entries_update"]);
    }

    expect(Entry::find($entry->id())->get('title'))->toBe('Live Title');
});

it('creates an unpublished draft through the revision pipeline', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();
    enableBlogRevisions();

    Server::actingAs(Fixtures::makeUser('create blog entries'))
        ->tool(EntriesCreate::class, ['collection' => 'blog', 'data' => ['title' => 'New Draft']])
        ->assertOk()
        ->assertSee('saved as draft — not live');

    $entry = Entry::query()->where('collection', 'blog')->where('slug', 'new-draft')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->published())->toBeFalse()
        ->and($entry->hasWorkingCopy())->toBeFalse();

    // The create recorded an attributed initial revision on disk
    expect(collect(File::allFiles(Revision::directory()))->isNotEmpty())->toBeTrue();
});

it('rejects explicit published on create in a revision-enabled collection', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();
    enableBlogRevisions();

    foreach ([true, false] as $published) {
        Server::actingAs(Fixtures::makeSuper())
            ->tool(EntriesCreate::class, ['collection' => 'blog', 'data' => ['title' => 'X'], 'published' => $published])
            ->assertHasErrors(["collection 'blog' uses revisions — entries are always created as unpublished drafts here; publish/unpublish from the Control Panel"]);
    }
});

it('creates no working copy when the merged update equals current data', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();
    enableBlogRevisions();

    $entry = makePublishedRevisableEntry();

    Server::actingAs(Fixtures::makeUser('edit blog entries'))
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['title' => 'Live Title']])
        ->assertOk()
        ->assertSee('no-op');

    expect(Entry::find($entry->id())->hasWorkingCopy())->toBeFalse();
});

it('amends an existing working copy, preserving fields staged earlier', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();
    enableBlogRevisions();

    $entry = tap(
        Entry::make()
            ->collection('blog')
            ->slug('live-post')
            ->data(['title' => 'Live Title', 'hero_image' => 'hero.jpg'])
            ->published(true)
    )->save();

    $user = Fixtures::makeUser('edit blog entries');

    Server::actingAs($user)
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['title' => 'Edited Title']])
        ->assertOk()
        ->assertSee('working copy created — live entry unchanged');

    Server::actingAs($user)
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['hero_image' => 'new-hero.jpg']])
        ->assertOk()
        ->assertSee('working copy amended — live entry unchanged');

    // Both staged edits live in the single working copy — update #2 rebased
    // onto the staged state instead of clobbering it from live data.
    $staged = Entry::find($entry->id())->workingCopy()->attributes();

    expect($staged['data']['title'])->toBe('Edited Title')
        ->and($staged['data']['hero_image'])->toBe('new-hero.jpg');

    $fresh = Entry::find($entry->id());

    expect($fresh->get('title'))->toBe('Live Title')
        ->and($fresh->get('hero_image'))->toBe('hero.jpg');
});

it('treats a revert-to-live request as dirty when a working copy is staged', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();
    enableBlogRevisions();

    $entry = makePublishedRevisableEntry();
    $user = Fixtures::makeUser('edit blog entries');

    Server::actingAs($user)
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['title' => 'Edited Title']])
        ->assertOk()
        ->assertSee('working copy created — live entry unchanged');

    // 'Live Title' equals the LIVE entry but differs from the STAGED copy —
    // it must be dirty against the rebased basis and re-stage the live value.
    Server::actingAs($user)
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['title' => 'Live Title']])
        ->assertOk()
        ->assertDontSee('no-op')
        ->assertSee('working copy amended — live entry unchanged');

    expect(Entry::find($entry->id())->workingCopy()->attributes()['data']['title'])->toBe('Live Title');
});

it('is a no-op against the staged working copy basis, leaving the copy unchanged', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();
    enableBlogRevisions();

    $entry = makePublishedRevisableEntry();
    $user = Fixtures::makeUser('edit blog entries');

    Server::actingAs($user)
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['title' => 'Edited Title']])
        ->assertOk()
        ->assertSee('working copy created — live entry unchanged');

    // Exactly the staged values → no-op against the STAGED basis, not live.
    Server::actingAs($user)
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['title' => 'Edited Title']])
        ->assertOk()
        ->assertSee('no-op')
        ->assertSee('working copy unchanged');

    $fresh = Entry::find($entry->id());

    expect($fresh->hasWorkingCopy())->toBeTrue()
        ->and($fresh->workingCopy()->attributes()['data']['title'])->toBe('Edited Title');
});

it('surfaces has_working_copy on revision-enabled entries in entries_get', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();
    enableBlogRevisions();

    $entry = makePublishedRevisableEntry();
    $user = Fixtures::makeUser('view blog entries', 'edit blog entries');

    Server::actingAs($user)
        ->tool(EntriesGet::class, ['id' => $entry->id()])
        ->assertOk()
        ->assertSee('"has_working_copy":false');

    Server::actingAs($user)
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['title' => 'Edited Title']])
        ->assertOk();

    Server::actingAs($user)
        ->tool(EntriesGet::class, ['id' => $entry->id()])
        ->assertOk()
        ->assertSee('"has_working_copy":true');
});

it('saves unpublished drafts directly without a working copy (CP parity)', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();
    enableBlogRevisions();

    $entry = tap(
        Entry::make()->collection('blog')->slug('a-draft')->data(['title' => 'Draft Title'])->published(false)
    )->save();

    Server::actingAs(Fixtures::makeUser('edit blog entries'))
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['title' => 'Edited Draft']])
        ->assertOk()
        ->assertSee('saved as draft — not live');

    $fresh = Entry::find($entry->id());

    expect($fresh->get('title'))->toBe('Edited Draft')  // saved directly
        ->and($fresh->hasWorkingCopy())->toBeFalse();
});
