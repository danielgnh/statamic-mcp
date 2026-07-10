<?php

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tools\EntriesCreate;
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
