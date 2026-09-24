<?php

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tools\EntriesPublish;
use Danielgnh\StatamicMcp\Tools\EntriesUnpublish;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Statamic\Contracts\Auth\User;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Events\EntrySaving;
use Statamic\Facades\Entry;
use Statamic\Facades\Revision;

function makeBlogDraft(): EntryContract
{
    return tap(
        Entry::make()->collection('blog')->slug('draft-post')->data(['title' => 'Draft'])->published(false)
    )->save();
}

function makeBlogLive(): EntryContract
{
    return tap(
        Entry::make()->collection('blog')->slug('live-post')->data(['title' => 'Live'])->published(true)
    )->save();
}

// The same snapshot entries_update stages on a revision-enabled collection.
function stageWorkingCopy(EntryContract $entry, User $user, string $title): void
{
    (clone $entry)->data(['title' => $title])->makeWorkingCopy()->user($user)->save();

    expect(Entry::find($entry->id())->hasWorkingCopy())->toBeTrue();
}

function revisionsOnDisk(): string
{
    return collect(File::allFiles(Revision::directory()))
        ->map(fn ($file) => File::get($file->getPathname()))
        ->implode("\n");
}

it('publishes a draft', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $entry = makeBlogDraft();

    Server::actingAs(Fixtures::makeUser('publish blog entries'))
        ->tool(EntriesPublish::class, ['id' => $entry->id()])
        ->assertOk()
        ->assertSee('"status":"published"')
        ->assertSee('"result":"published"')
        ->assertSee('"cp_edit_url"');

    expect(Entry::find($entry->id())->published())->toBeTrue();
});

it("requires 'publish blog entries' to publish", function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $entry = makeBlogDraft();
    $user = Fixtures::makeUser('edit blog entries', 'create blog entries');

    Server::actingAs($user)
        ->tool(EntriesPublish::class, ['id' => $entry->id()])
        ->assertHasErrors(["requires 'publish blog entries' — grant it to a role of {$user->email()} in the Control Panel"]);

    expect(Entry::find($entry->id())->published())->toBeFalse();
});

it('is a no-op on an entry that is already published', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $entry = makeBlogLive();

    Server::actingAs(Fixtures::makeUser('publish blog entries'))
        ->tool(EntriesPublish::class, ['id' => $entry->id()])
        ->assertOk()
        ->assertSee('no-op — already published, nothing staged');
});

it('reports an unexposed entry as not found', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $entry = makeBlogDraft();

    config(['statamic.mcp.resources.collections' => []]);

    Server::actingAs(Fixtures::makeSuper())
        ->tool(EntriesPublish::class, ['id' => $entry->id()])
        ->assertHasErrors(["entry '{$entry->id()}' not found"]);

    expect(Entry::find($entry->id())->published())->toBeFalse();
});

it('promotes the working copy on a revision-enabled collection', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();
    Fixtures::revisions();

    $entry = makeBlogLive();
    $user = Fixtures::makeUser('publish blog entries');

    stageWorkingCopy($entry, $user, 'Staged');

    Server::actingAs($user)
        ->tool(EntriesPublish::class, ['id' => $entry->id()])
        ->assertOk()
        ->assertSee('published — working copy is now live');

    $fresh = Entry::find($entry->id());

    expect($fresh->get('title'))->toBe('Staged')
        ->and($fresh->published())->toBeTrue()
        ->and($fresh->hasWorkingCopy())->toBeFalse();

    expect(revisionsOnDisk())
        ->toContain('action: publish')
        ->toContain('via MCP entries_publish')
        ->toContain((string) $user->id());
});

it('publishes a draft on a revision-enabled collection with an attributed revision', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();
    Fixtures::revisions();

    $entry = makeBlogDraft();
    $user = Fixtures::makeUser('publish blog entries');

    Server::actingAs($user)
        ->tool(EntriesPublish::class, ['id' => $entry->id()])
        ->assertOk()
        ->assertSee('"result":"published"');

    expect(Entry::find($entry->id())->published())->toBeTrue();

    expect(revisionsOnDisk())
        ->toContain('action: publish')
        ->toContain((string) $user->id());
});

it('reports a listener-cancelled publish instead of claiming success', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $entry = makeBlogDraft();

    Event::listen(EntrySaving::class, fn () => false);

    Server::actingAs(Fixtures::makeUser('publish blog entries'))
        ->tool(EntriesPublish::class, ['id' => $entry->id()])
        ->assertHasErrors(['the publish was cancelled by a listener on this site — the entry is not live']);
});

it('unpublishes a live entry', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $entry = makeBlogLive();

    Server::actingAs(Fixtures::makeUser('publish blog entries'))
        ->tool(EntriesUnpublish::class, ['id' => $entry->id()])
        ->assertOk()
        ->assertSee('"status":"draft"')
        ->assertSee('unpublished — not live')
        ->assertSee('"cp_edit_url"');

    expect(Entry::find($entry->id())->published())->toBeFalse();
});

it("requires 'publish blog entries' to unpublish (CP parity)", function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $entry = makeBlogLive();
    $user = Fixtures::makeUser('edit blog entries');

    // The CP's unpublish route authorizes 'publish' — v6 has no separate
    // unpublish permission.
    Server::actingAs($user)
        ->tool(EntriesUnpublish::class, ['id' => $entry->id()])
        ->assertHasErrors(["requires 'publish blog entries' — grant it to a role of {$user->email()} in the Control Panel"]);

    expect(Entry::find($entry->id())->published())->toBeTrue();
});

it('is a no-op on an entry that is already a draft', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $entry = makeBlogDraft();

    Server::actingAs(Fixtures::makeUser('publish blog entries'))
        ->tool(EntriesUnpublish::class, ['id' => $entry->id()])
        ->assertOk()
        ->assertSee('no-op — already a draft');
});

it('applies the working copy and unpublishes on a revision-enabled collection', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();
    Fixtures::revisions();

    $entry = makeBlogLive();
    $user = Fixtures::makeUser('publish blog entries');

    stageWorkingCopy($entry, $user, 'Staged');

    Server::actingAs($user)
        ->tool(EntriesUnpublish::class, ['id' => $entry->id()])
        ->assertOk()
        ->assertSee('unpublished — working copy applied, not live');

    $fresh = Entry::find($entry->id());

    expect($fresh->get('title'))->toBe('Staged')
        ->and($fresh->published())->toBeFalse()
        ->and($fresh->hasWorkingCopy())->toBeFalse();

    expect(revisionsOnDisk())
        ->toContain('action: unpublish')
        ->toContain('via MCP entries_unpublish')
        ->toContain((string) $user->id());
});

it('reports a listener-cancelled unpublish instead of claiming success', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $entry = makeBlogLive();

    Event::listen(EntrySaving::class, fn () => false);

    Server::actingAs(Fixtures::makeUser('publish blog entries'))
        ->tool(EntriesUnpublish::class, ['id' => $entry->id()])
        ->assertHasErrors(['the unpublish was cancelled by a listener on this site — the entry is still live']);
});

function makeAuthoredBlogPost(string $slug, string $author, bool $published): EntryContract
{
    return tap(
        Entry::make()->collection('blog')->slug($slug)->data(['title' => $slug, 'author' => [$author]])->published($published)
    )->save();
}

it("requires 'publish other authors blog entries' to publish someone else's entry, but not one's own", function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();
    Fixtures::authors();

    $user = Fixtures::makeUser('publish blog entries');
    $theirs = makeAuthoredBlogPost('theirs', Fixtures::makeUser()->id(), published: false);
    $mine = makeAuthoredBlogPost('mine', $user->id(), published: false);

    Server::actingAs($user)
        ->tool(EntriesPublish::class, ['id' => $theirs->id()])
        ->assertHasErrors(["requires 'publish other authors blog entries' — grant it to a role of {$user->email()} in the Control Panel"]);

    Server::actingAs($user)
        ->tool(EntriesPublish::class, ['id' => $mine->id()])
        ->assertOk();

    expect(Entry::find($theirs->id())->published())->toBeFalse()
        ->and(Entry::find($mine->id())->published())->toBeTrue();
});

it("requires 'publish other authors blog entries' to unpublish someone else's entry, but not one's own", function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();
    Fixtures::authors();

    $user = Fixtures::makeUser('publish blog entries');
    $theirs = makeAuthoredBlogPost('theirs', Fixtures::makeUser()->id(), published: true);
    $mine = makeAuthoredBlogPost('mine', $user->id(), published: true);

    Server::actingAs($user)
        ->tool(EntriesUnpublish::class, ['id' => $theirs->id()])
        ->assertHasErrors(["requires 'publish other authors blog entries' — grant it to a role of {$user->email()} in the Control Panel"]);

    Server::actingAs($user)
        ->tool(EntriesUnpublish::class, ['id' => $mine->id()])
        ->assertOk();

    expect(Entry::find($theirs->id())->published())->toBeTrue()
        ->and(Entry::find($mine->id())->published())->toBeFalse();
});
