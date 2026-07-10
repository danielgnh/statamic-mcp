<?php

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tools\TermsDelete;
use Illuminate\Support\Facades\Event;
use Laravel\Mcp\Request;
use Statamic\Events\TermDeleting;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Entry;
use Statamic\Facades\Stache;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;

function makeDoomedTag(): void
{
    Term::make()->taxonomy('tags')->slug('php')->data(['title' => 'PHP'])->save();
}

it('deletes a term when deletes are enabled', function () {
    config(['statamic.mcp.deletes' => true]);

    Fixtures::site();
    Fixtures::tags();
    makeDoomedTag();

    $user = Fixtures::makeUser('delete tags terms');

    Server::actingAs($user)
        ->tool(TermsDelete::class, ['id' => 'tags::php'])
        ->assertOk()
        ->assertSee('"deleted":true')
        ->assertSee('"id":"tags::php"')
        ->assertSee('"taxonomy":"tags"')
        ->assertSee('"slug":"php"')
        ->assertSee('cannot be undone')
        // Amended spec exception: no cp_edit_url on deletes — the CP page
        // for a deleted term would 404.
        ->assertDontSee('cp_edit_url');

    expect(Term::find('tags::php'))->toBeNull();
});

it('is not registered when deletes are disabled', function () {
    // config default: statamic.mcp.deletes = false
    Fixtures::site();
    Fixtures::tags();
    makeDoomedTag();

    Server::actingAs(Fixtures::makeSuper())
        ->tool(TermsDelete::class, ['id' => 'tags::php'])
        ->assertHasErrors(['Tool [terms_delete] not found']);

    expect(Term::find('tags::php'))->not->toBeNull();
});

it('denies deleting without the delete permission', function () {
    config(['statamic.mcp.deletes' => true]);

    Fixtures::site();
    Fixtures::tags();
    makeDoomedTag();

    $user = Fixtures::makeUser('edit tags terms'); // can edit, cannot delete

    Server::actingAs($user)
        ->tool(TermsDelete::class, ['id' => 'tags::php'])
        ->assertHasErrors(["requires 'delete tags terms' — grant it to a role of {$user->email()} in the Control Panel"]);

    expect(Term::find('tags::php'))->not->toBeNull();
});

it('re-checks the deletes gate inside the handler, not just at registration', function () {
    Fixtures::site();
    Fixtures::tags();
    makeDoomedTag();

    // config default: statamic.mcp.deletes = false

    // Call handle() directly, bypassing tools/list — exactly what a client
    // with a stale tool cache does after the server flipped deletes off.
    // The harness enforces shouldRegister(), so only a direct call can pin
    // the in-handler re-check (spec §6 layer 1).
    $response = (new TermsDelete)->handle(new Request(['id' => 'tags::php']));

    expect($response->isError())->toBeTrue()
        ->and((string) $response->content())->toContain('statamic.mcp.deletes');

    expect(Term::find('tags::php'))->not->toBeNull();
});

it('refuses to delete when the server is read-only even if deletes are enabled', function () {
    config(['statamic.mcp.deletes' => true, 'statamic.mcp.read_only' => true]);

    Fixtures::site();
    Fixtures::tags();
    makeDoomedTag();

    Server::actingAs(Fixtures::makeSuper())
        ->tool(TermsDelete::class, ['id' => 'tags::php'])
        ->assertHasErrors();

    expect(Term::find('tags::php'))->not->toBeNull();
});

it('treats a term in an unexposed taxonomy as not found', function () {
    config(['statamic.mcp.deletes' => true, 'statamic.mcp.resources.taxonomies' => []]);

    Fixtures::site();
    Fixtures::tags();
    makeDoomedTag();

    Server::actingAs(Fixtures::makeSuper())
        ->tool(TermsDelete::class, ['id' => 'tags::php'])
        ->assertHasErrors(["taxonomy 'tags' not found"]);

    expect(Term::find('tags::php'))->not->toBeNull();
});

it('rejects a malformed id with the expected shape', function () {
    config(['statamic.mcp.deletes' => true]);

    Fixtures::site();
    Fixtures::tags();

    Server::actingAs(Fixtures::makeSuper())
        ->tool(TermsDelete::class, ['id' => 'php'])
        ->assertHasErrors(["term ids look like '{taxonomy}::{slug}', e.g. 'tags::php' — got 'php'"]);
});

it('reports a clean error when a TermDeleting listener cancels the delete', function () {
    config(['statamic.mcp.deletes' => true]);

    Fixtures::site();
    Fixtures::tags();
    makeDoomedTag();

    // Approval-workflow addons cancel deletes this way; delete() returns
    // false and the tool must never report success for it.
    Event::listen(TermDeleting::class, fn () => false);

    Server::actingAs(Fixtures::makeSuper())
        ->tool(TermsDelete::class, ['id' => 'tags::php'])
        ->assertHasErrors(['the delete was cancelled by a listener — the term was not deleted']);

    expect(Term::find('tags::php'))->not->toBeNull();
});

it('deletes every site localization of the term at once', function () {
    config(['statamic.mcp.deletes' => true]);

    Fixtures::multisite();
    Fixtures::tags();
    Taxonomy::findByHandle('tags')->sites(['en', 'de'])->save();

    // No tap() chain here: single-argument tap returns a HigherOrderTapProxy,
    // which would silently reroute ->in('de')->data() to the default locale.
    $seed = Term::make()->taxonomy('tags')->slug('php')->data(['title' => 'PHP']);
    $seed->in('de')->data(['title' => 'PHP DE']);
    $seed->save();

    Server::actingAs(Fixtures::makeSuper())
        ->tool(TermsDelete::class, ['id' => 'tags::php'])
        ->assertOk()
        ->assertSee('every site');

    // Localizations are data overrides within the one term — no site view
    // can survive its deletion. Rebuild past the Stache's live references
    // before asserting persisted state.
    Stache::clear();

    expect(Term::find('tags::php'))->toBeNull()
        ->and(Term::query()->where('taxonomy', 'tags')->where('site', 'de')->count())->toBe(0);
});

it('needs no site access beyond the delete permission (CP parity)', function () {
    config(['statamic.mcp.deletes' => true]);

    Fixtures::multisite();
    Fixtures::tags();
    Taxonomy::findByHandle('tags')->sites(['en', 'de'])->save();

    $seed = Term::make()->taxonomy('tags')->slug('php')->data(['title' => 'PHP']);
    $seed->in('de')->data(['title' => 'PHP DE']);
    $seed->save();

    // Vendor TermPolicy::delete gates on 'delete {taxonomy} terms' alone —
    // no site sweep (unlike entries' per-localization cascade). A user
    // without 'access de site' may still delete, taking the de view with it.
    Server::actingAs(Fixtures::makeUser('delete tags terms'))
        ->tool(TermsDelete::class, ['id' => 'tags::php'])
        ->assertOk()
        ->assertSee('"deleted":true');

    expect(Term::find('tags::php'))->toBeNull();
});

it('removes entry references to the deleted term', function () {
    config(['statamic.mcp.deletes' => true]);

    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();
    makeDoomedTag();

    $entry = tap(
        Entry::make()->collection('blog')->slug('post')->data(['title' => 'Post', 'topic' => 'php'])
    )->save();

    // Rehydrate the term from disk (fresh-process reality): a file-hydrated
    // term has no synced original state, so the reference removal below only
    // happens because the tool syncs it BEFORE deleting (same fact as T20's
    // rename path).
    Stache::store('terms')->store('tags')->forgetItem('en::php');

    Server::actingAs(Fixtures::makeUser('delete tags terms'))
        ->tool(TermsDelete::class, ['id' => 'tags::php'])
        ->assertOk();

    // Statamic's UpdateTermReferences listener (TermDeleted) strips the
    // dangling reference from the entry's term field.
    expect(Entry::find($entry->id())->get('topic'))->toBeNull()
        ->and(Entry::find($entry->id()))->not->toBeNull();
});

it('strips only the deleted term from a multi-term field, re-indexed', function () {
    config(['statamic.mcp.deletes' => true]);

    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();
    makeDoomedTag();
    Term::make()->taxonomy('tags')->slug('laravel')->data(['title' => 'Laravel'])->save();

    // A multi-term field (no max_items cap) stores an ARRAY of slugs —
    // vendor's replaceValuesInArray path, which the single-term string-shape
    // test above cannot reach. Pin: only the deleted slug goes, and the
    // survivor list comes back re-indexed (a keyed remainder like [1 =>
    // 'laravel'] would serialize as a JSON object, not an array).
    Blueprint::makeFromFields([
        'title' => ['type' => 'text', 'validate' => 'required'],
        'topics' => ['type' => 'terms', 'taxonomies' => ['tags']],
    ])->setHandle('article')->setNamespace('collections.blog')->save();

    $entry = tap(
        Entry::make()->collection('blog')->slug('post')->data(['title' => 'Post', 'topics' => ['php', 'laravel']])
    )->save();

    // Rehydrate from disk (fresh-process reality) — same fact as the
    // single-term test: reference removal needs the tool's pre-delete sync.
    Stache::store('terms')->store('tags')->forgetItem('en::php');

    Server::actingAs(Fixtures::makeUser('delete tags terms'))
        ->tool(TermsDelete::class, ['id' => 'tags::php'])
        ->assertOk();

    expect(Entry::find($entry->id())->get('topics'))->toBe(['laravel']);
});
