<?php

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tools\EntriesDelete;
use Illuminate\Support\Facades\Event;
use Laravel\Mcp\Request;
use Statamic\Events\EntryDeleting;
use Statamic\Facades\Entry;

function makeDeletableBlogEntry(): Statamic\Contracts\Entries\Entry
{
    return tap(
        Entry::make()
            ->collection('blog')
            ->slug('doomed-post')
            ->data(['title' => 'Doomed Post'])
            ->published(true)
    )->save();
}

function makeDeletableLocalizedPair(): array
{
    $origin = tap(
        Entry::make()->collection('blog')->slug('doomed')->locale('en')->data(['title' => 'Doomed'])->published(true)
    )->save();

    $localization = tap($origin->makeLocalization('de')->data(['title' => 'Verloren']))->save();

    return [$origin, $localization];
}

it('deletes an entry when deletes are enabled and the user may delete', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    config(['statamic.mcp.deletes' => true]);

    $entry = makeDeletableBlogEntry();

    Server::actingAs(Fixtures::makeUser('delete blog entries'))
        ->tool(EntriesDelete::class, ['id' => $entry->id()])
        ->assertOk()
        ->assertSee('deleted')
        ->assertSee('"slug":"doomed-post"')
        ->assertSee('"collection":"blog"')
        ->assertSee('"site":"en"')
        // Amended spec exception: no cp_edit_url on deletes — the CP page
        // for a deleted entry would 404.
        ->assertDontSee('cp_edit_url');

    expect(Entry::find($entry->id()))->toBeNull();
});

it('denies deleting without the delete permission', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    config(['statamic.mcp.deletes' => true]);

    $entry = makeDeletableBlogEntry();
    $user = Fixtures::makeUser('edit blog entries');

    Server::actingAs($user)
        ->tool(EntriesDelete::class, ['id' => $entry->id()])
        ->assertHasErrors(["requires 'delete blog entries' — grant it to a role of {$user->email()} in the Control Panel"]);

    expect(Entry::find($entry->id()))->not->toBeNull();
});

it('refuses to delete when deletes are disabled (the default)', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    // config default: statamic.mcp.deletes = false

    $entry = makeDeletableBlogEntry();

    // Either the registration gate (shouldRegister) or the in-handler
    // re-check rejects the call — both surface as errors.
    Server::actingAs(Fixtures::makeSuper())
        ->tool(EntriesDelete::class, ['id' => $entry->id()])
        ->assertHasErrors();

    expect(Entry::find($entry->id()))->not->toBeNull();
});

it('re-checks the deletes gate inside the handler, not just at registration', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    // config default: statamic.mcp.deletes = false

    $entry = makeDeletableBlogEntry();

    // Call handle() directly, bypassing tools/list — exactly what a client
    // with a stale tool cache does after the server flipped deletes off.
    // The harness enforces shouldRegister(), so only a direct call can pin
    // the in-handler re-check (spec §6 layer 1).
    $response = (new EntriesDelete)->handle(new Request(['id' => $entry->id()]));

    expect($response->isError())->toBeTrue()
        ->and((string) $response->content())->toContain('statamic.mcp.deletes');

    expect(Entry::find($entry->id()))->not->toBeNull();
});

it('refuses to delete when the server is read-only even if deletes are enabled', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    config(['statamic.mcp.deletes' => true, 'statamic.mcp.read_only' => true]);

    $entry = makeDeletableBlogEntry();

    Server::actingAs(Fixtures::makeSuper())
        ->tool(EntriesDelete::class, ['id' => $entry->id()])
        ->assertHasErrors();

    expect(Entry::find($entry->id()))->not->toBeNull();
});

it('treats an entry in an unexposed collection as not found', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    config(['statamic.mcp.deletes' => true, 'statamic.mcp.resources.collections' => []]);

    $entry = makeDeletableBlogEntry();

    Server::actingAs(Fixtures::makeSuper())
        ->tool(EntriesDelete::class, ['id' => $entry->id()])
        ->assertHasErrors(["entry '{$entry->id()}' not found"]);

    expect(Entry::find($entry->id()))->not->toBeNull();
});

it('reports a clean error when an EntryDeleting listener cancels the delete', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    config(['statamic.mcp.deletes' => true]);

    $entry = makeDeletableBlogEntry();

    // Approval-workflow addons cancel deletes this way; delete() returns
    // false and the tool must never report success for it.
    Event::listen(EntryDeleting::class, fn () => false);

    Server::actingAs(Fixtures::makeSuper())
        ->tool(EntriesDelete::class, ['id' => $entry->id()])
        ->assertHasErrors(['the delete was cancelled by a listener on this site — the entry was not deleted']);

    expect(Entry::find($entry->id()))->not->toBeNull();
});

it('deletes an origin together with its localizations and says so', function () {
    Fixtures::multisite();
    Fixtures::tags();
    Fixtures::blog();

    config(['statamic.mcp.deletes' => true]);

    [$origin, $localization] = makeDeletableLocalizedPair();

    // Vendor Entry::delete() refuses origins with localizations outright —
    // the tool cascades explicitly (CP "Delete" behavior) and enumerates
    // what else it removed.
    Server::actingAs(Fixtures::makeSuper())
        ->tool(EntriesDelete::class, ['id' => $origin->id()])
        ->assertOk()
        ->assertSee('deleted_localizations')
        ->assertSee($localization->id())
        ->assertSee('"site":"de"');

    expect(Entry::find($origin->id()))->toBeNull()
        ->and(Entry::find($localization->id()))->toBeNull();
});

it('deletes a leaf localization without touching its origin', function () {
    Fixtures::multisite();
    Fixtures::tags();
    Fixtures::blog();

    config(['statamic.mcp.deletes' => true]);

    [$origin, $localization] = makeDeletableLocalizedPair();

    Server::actingAs(Fixtures::makeSuper())
        ->tool(EntriesDelete::class, ['id' => $localization->id()])
        ->assertOk()
        ->assertSee('"site":"de"')
        ->assertDontSee('deleted_localizations');

    expect(Entry::find($localization->id()))->toBeNull()
        ->and(Entry::find($origin->id()))->not->toBeNull();
});

it('refuses to cascade into a localization site the user cannot access', function () {
    Fixtures::multisite();
    Fixtures::tags();
    Fixtures::blog();

    config(['statamic.mcp.deletes' => true]);

    [$origin, $localization] = makeDeletableLocalizedPair();

    // 'en' is the default site (never gated); 'de' needs 'access de site'.
    // CP parity: the Delete action only offers cascade when the user can
    // access every descendant's site.
    $user = Fixtures::makeUser('delete blog entries');

    Server::actingAs($user)
        ->tool(EntriesDelete::class, ['id' => $origin->id()])
        ->assertHasErrors(["requires 'access de site' — grant it to a role of {$user->email()} in the Control Panel"]);

    expect(Entry::find($origin->id()))->not->toBeNull()
        ->and(Entry::find($localization->id()))->not->toBeNull();
});

it('keeps the origin when a listener cancels deleting a localization', function () {
    Fixtures::multisite();
    Fixtures::tags();
    Fixtures::blog();

    config(['statamic.mcp.deletes' => true]);

    [$origin, $localization] = makeDeletableLocalizedPair();

    // Cancel only the de localization's delete: the cascade must notice the
    // survivor and refuse to delete the origin (which would otherwise throw
    // vendor's "Cannot delete an entry with localizations").
    Event::listen(EntryDeleting::class, fn (EntryDeleting $event) => $event->entry->locale() === 'de' ? false : null);

    Server::actingAs(Fixtures::makeSuper())
        ->tool(EntriesDelete::class, ['id' => $origin->id()])
        ->assertHasErrors();

    expect(Entry::find($origin->id()))->not->toBeNull()
        ->and(Entry::find($localization->id()))->not->toBeNull();
});
