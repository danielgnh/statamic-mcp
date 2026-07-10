<?php

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tools\TermsUpdate;
use Illuminate\Support\Facades\Event;
use Statamic\Events\TermSaving;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Entry;
use Statamic\Facades\Stache;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;

function makeTopicsTaxonomy(): void
{
    tap(Taxonomy::make('topics')->title('Topics'))->save();

    Blueprint::makeFromFields([
        'title' => ['type' => 'text', 'validate' => 'required'],
        'description' => ['type' => 'textarea'],
    ])->setHandle('topic')->setNamespace('taxonomies.topics')->save();
}

it('shallow-merges data, preserving untouched top-level keys', function () {
    Fixtures::site();
    makeTopicsTaxonomy();

    Term::make()->taxonomy('topics')->slug('alpha')
        ->data(['title' => 'Alpha', 'description' => 'Old'])->save();

    $user = Fixtures::makeUser('edit topics terms');

    Server::actingAs($user)
        ->tool(TermsUpdate::class, ['id' => 'topics::alpha', 'data' => ['description' => 'New']])
        ->assertOk()
        ->assertSee('"title":"Alpha"')
        ->assertSee('"description":"New"')
        ->assertSee('updated — live');

    $fresh = Term::find('topics::alpha')->in('en');

    expect($fresh->data()->get('description'))->toBe('New')
        ->and($fresh->data()->get('title'))->toBe('Alpha')
        ->and($fresh->data()->get('updated_by'))->toBe($user->id()); // CP parity: updates carry updated_by/updated_at
});

it('stores an explicit null to clear an optional field', function () {
    Fixtures::site();
    makeTopicsTaxonomy();

    Term::make()->taxonomy('topics')->slug('alpha')
        ->data(['title' => 'Alpha', 'description' => 'Old'])->save();

    Server::actingAs(Fixtures::makeUser('edit topics terms'))
        ->tool(TermsUpdate::class, ['id' => 'topics::alpha', 'data' => ['description' => null]])
        ->assertOk()
        ->assertSee('"description":null');
});

it('rejects clearing a required field via merged validation', function () {
    Fixtures::site();
    makeTopicsTaxonomy();

    Term::make()->taxonomy('topics')->slug('alpha')->data(['title' => 'Alpha'])->save();

    Server::actingAs(Fixtures::makeUser('edit topics terms'))
        ->tool(TermsUpdate::class, ['id' => 'topics::alpha', 'data' => ['title' => null]])
        ->assertHasErrors()
        ->assertSee('validation failed');

    expect(Term::find('topics::alpha')->in('en')->data()->get('title'))->toBe('Alpha');
});

it('is a no-op when the merged result equals current data', function () {
    Fixtures::site();
    makeTopicsTaxonomy();

    Term::make()->taxonomy('topics')->slug('alpha')->data(['title' => 'Alpha'])->save();

    Server::actingAs(Fixtures::makeUser('edit topics terms'))
        ->tool(TermsUpdate::class, ['id' => 'topics::alpha', 'data' => ['title' => 'Alpha']])
        ->assertOk()
        ->assertSee('no-op — merged data equals current data; nothing saved');

    // Nothing was saved: a real save would have stamped updated_at/updated_by.
    expect(Term::find('topics::alpha')->in('en')->data()->has('updated_at'))->toBeFalse();
});

it('treats Statamic-managed metadata in the patch as no change', function () {
    Fixtures::site();
    makeTopicsTaxonomy();

    Term::make()->taxonomy('topics')->slug('alpha')->data(['title' => 'Alpha'])->save();

    // Stale copies of terms_get output may carry updated_at/updated_by —
    // stripped, never merged, never treated as a change.
    Server::actingAs(Fixtures::makeUser('edit topics terms'))
        ->tool(TermsUpdate::class, ['id' => 'topics::alpha', 'data' => ['title' => 'Alpha', 'updated_at' => 123, 'updated_by' => 'someone']])
        ->assertOk()
        ->assertSee('no-op — merged data equals current data; nothing saved');

    expect(Term::find('topics::alpha')->in('en')->data()->has('updated_at'))->toBeFalse();
});

it('rejects preview objects round-tripped from terms_get', function () {
    Fixtures::site();
    makeTopicsTaxonomy();

    Term::make()->taxonomy('topics')->slug('alpha')->data(['title' => 'Alpha'])->save();

    Server::actingAs(Fixtures::makeUser('edit topics terms'))
        ->tool(TermsUpdate::class, [
            'id' => 'topics::alpha',
            'data' => ['description' => ['__preview' => 'A long…', 'truncated' => true, 'note' => 'NOT writable']],
        ])
        ->assertHasErrors()
        ->assertSee('is a truncated preview object');
});

it('rejects unknown field keys with a did-you-mean hint', function () {
    Fixtures::site();
    makeTopicsTaxonomy();

    Term::make()->taxonomy('topics')->slug('alpha')->data(['title' => 'Alpha'])->save();

    Server::actingAs(Fixtures::makeUser('edit topics terms'))
        ->tool(TermsUpdate::class, ['id' => 'topics::alpha', 'data' => ['descriptoin' => 'x']])
        ->assertHasErrors(["unknown field descriptoin — valid handles: description, title — did you mean 'description' instead of 'descriptoin'?"]);
});

it('creates a site localization override transparently on first write', function () {
    Fixtures::multisite();
    makeTopicsTaxonomy();
    Taxonomy::findByHandle('topics')->sites(['en', 'de'])->save();

    Term::make()->taxonomy('topics')->slug('alpha')->data(['title' => 'Alpha'])->save();

    Server::actingAs(Fixtures::makeUser('edit topics terms', 'access de site'))
        ->tool(TermsUpdate::class, ['id' => 'topics::alpha', 'data' => ['title' => 'Alpha DE'], 'site' => 'de'])
        ->assertOk()
        ->assertSee('"site":"de"')
        ->assertSee('"title":"Alpha DE"')
        ->assertSee('"localization":"created"');

    $term = Term::find('topics::alpha');

    expect($term->in('de')->data()->get('title'))->toBe('Alpha DE')
        ->and($term->in('en')->data()->get('title'))->toBe('Alpha'); // default site untouched
});

it('merges onto the existing site override bucket when one exists', function () {
    Fixtures::multisite();
    makeTopicsTaxonomy();
    Taxonomy::findByHandle('topics')->sites(['en', 'de'])->save();

    // No tap() chain here: single-argument tap returns a HigherOrderTapProxy,
    // which would silently reroute ->in('de')->data() to the default locale.
    $seed = Term::make()->taxonomy('topics')->slug('alpha')->data(['title' => 'Alpha']);
    $seed->in('de')->data(['title' => 'Alpha DE', 'description' => 'DE only']);
    $seed->save();

    Server::actingAs(Fixtures::makeUser('edit topics terms', 'access de site'))
        ->tool(TermsUpdate::class, ['id' => 'topics::alpha', 'data' => ['title' => 'Neu DE'], 'site' => 'de'])
        ->assertOk()
        ->assertSee('"localization":"amended"');

    $term = Term::find('topics::alpha');

    expect($term->in('de')->data()->get('title'))->toBe('Neu DE')
        ->and($term->in('de')->data()->get('description'))->toBe('DE only') // untouched override survives the merge
        ->and($term->in('en')->data()->get('title'))->toBe('Alpha');
});

it('denies updating without the edit permission', function () {
    Fixtures::site();
    makeTopicsTaxonomy();

    Term::make()->taxonomy('topics')->slug('alpha')->data(['title' => 'Alpha'])->save();

    $user = Fixtures::makeUser('view topics terms');

    Server::actingAs($user)
        ->tool(TermsUpdate::class, ['id' => 'topics::alpha', 'data' => ['title' => 'X']])
        ->assertHasErrors(["requires 'edit topics terms' — grant it to a role of {$user->email()} in the Control Panel"]);
});

it('denies a localized write without access to that site', function () {
    Fixtures::multisite();
    makeTopicsTaxonomy();
    Taxonomy::findByHandle('topics')->sites(['en', 'de'])->save();

    Term::make()->taxonomy('topics')->slug('alpha')->data(['title' => 'Alpha'])->save();

    $user = Fixtures::makeUser('edit topics terms'); // no 'access de site'

    Server::actingAs($user)
        ->tool(TermsUpdate::class, ['id' => 'topics::alpha', 'data' => ['title' => 'Nein'], 'site' => 'de'])
        ->assertHasErrors(["requires 'access de site' — grant it to a role of {$user->email()} in the Control Panel"]);

    expect(Term::find('topics::alpha')->in('de')->data()->all())->toBe([]);
});

it('changes the slug on the default site: new id, file moved, old id gone', function () {
    Fixtures::site();
    makeTopicsTaxonomy();

    Term::make()->taxonomy('topics')->slug('alpha')->data(['title' => 'Alpha'])->save();

    // Rehydrate from disk (fresh-process reality): a file-hydrated term has no
    // synced original state, so rename detection in the Stache store depends
    // on the tool syncing it BEFORE changing the slug (CP parity).
    Stache::store('terms')->store('topics')->forgetItem('en::alpha');

    Server::actingAs(Fixtures::makeUser('edit topics terms'))
        ->tool(TermsUpdate::class, ['id' => 'topics::alpha', 'data' => [], 'slug' => 'renamed'])
        ->assertOk()
        ->assertSee('"id":"topics::renamed"')
        ->assertSee('"previous_id":"topics::alpha"');

    // Rebuild the Stache from disk before asserting: the test-env array cache
    // stores live object references, so in-process lookups after a rename can
    // alias the mutated instance (production cache stores serialize).
    Stache::clear();

    expect(Term::find('topics::renamed'))->not->toBeNull()
        ->and(Term::find('topics::alpha'))->toBeNull() // the old id is gone — no orphaned duplicate file
        ->and(Term::query()->where('taxonomy', 'topics')->count())->toBe(1);
});

it('rewrites entry references to the renamed term', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    Term::make()->taxonomy('tags')->slug('php')->data(['title' => 'PHP'])->save();

    $entry = tap(
        Entry::make()->collection('blog')->slug('post')->data(['title' => 'Post', 'topic' => 'php'])
    )->save();

    // Rehydrate the term from disk (fresh-process reality): a file-hydrated
    // term has no synced original state, so the reference rewrite below only
    // happens because the tool syncs it BEFORE renaming (CP parity).
    Stache::store('terms')->store('tags')->forgetItem('en::php');

    Server::actingAs(Fixtures::makeUser('edit tags terms'))
        ->tool(TermsUpdate::class, ['id' => 'tags::php', 'data' => [], 'slug' => 'php-lang'])
        ->assertOk()
        ->assertSee('"id":"tags::php-lang"');

    // Statamic's UpdateTermReferences listener (TermSaved) rewrites the
    // entry's term field — it keys off getOriginal('slug'), which only works
    // because the tool syncs original state before renaming.
    expect(Entry::find($entry->id())->get('topic'))->toBe('php-lang');
});

it('stores a localized slug override on a non-default site without changing the id', function () {
    Fixtures::multisite();
    makeTopicsTaxonomy();
    Taxonomy::findByHandle('topics')->sites(['en', 'de'])->save();

    Term::make()->taxonomy('topics')->slug('alpha')->data(['title' => 'Alpha'])->save();

    Server::actingAs(Fixtures::makeUser('edit topics terms', 'access de site'))
        ->tool(TermsUpdate::class, ['id' => 'topics::alpha', 'data' => [], 'slug' => 'alpha-de', 'site' => 'de'])
        ->assertOk()
        ->assertSee('"id":"topics::alpha"') // id keeps the default-site slug
        ->assertSee('"slug":"alpha-de"');

    $term = Term::find('topics::alpha');

    expect($term->in('de')->slug())->toBe('alpha-de')
        ->and($term->in('de')->data()->get('slug'))->toBe('alpha-de') // stored as a data override
        ->and($term->in('en')->slug())->toBe('alpha');
});

it('rejects a rename that collides with an existing term', function () {
    Fixtures::site();
    makeTopicsTaxonomy();

    Term::make()->taxonomy('topics')->slug('alpha')->data(['title' => 'Alpha'])->save();
    Term::make()->taxonomy('topics')->slug('beta')->data(['title' => 'Beta'])->save();

    Server::actingAs(Fixtures::makeUser('edit topics terms'))
        ->tool(TermsUpdate::class, ['id' => 'topics::alpha', 'data' => [], 'slug' => 'Beta'])
        ->assertHasErrors(["term 'beta' already exists in taxonomy 'topics' — pick another slug"]);

    expect(Term::find('topics::alpha'))->not->toBeNull()
        ->and(Term::find('topics::beta')->in('en')->data()->get('title'))->toBe('Beta'); // nothing overwritten
});

it('reports a listener-cancelled save instead of claiming success', function () {
    Fixtures::site();
    makeTopicsTaxonomy();

    Term::make()->taxonomy('topics')->slug('alpha')->data(['title' => 'Alpha'])->save();

    // Approval-workflow addons cancel saves by returning false from
    // TermSaving; Term::save() then returns false.
    Event::listen(TermSaving::class, fn () => false);

    Server::actingAs(Fixtures::makeUser('edit topics terms'))
        ->tool(TermsUpdate::class, ['id' => 'topics::alpha', 'data' => ['title' => 'Nope']])
        ->assertHasErrors(['the save was cancelled by a listener — the term was not updated']);

    // Rehydrate from disk: the array cache aliases the in-request instance
    // (mutated before the cancelled save); the persisted term is untouched.
    Stache::clear();

    expect(Term::find('topics::alpha')->in('en')->data()->get('title'))->toBe('Alpha');
});

it('is hidden when the server is read-only', function () {
    Fixtures::site();
    makeTopicsTaxonomy();

    Term::make()->taxonomy('topics')->slug('alpha')->data(['title' => 'Alpha'])->save();

    config(['statamic.mcp.read_only' => true]);

    // Either the registration gate (shouldRegister) or the in-handler
    // re-check rejects the call — both are errors, which is all that matters.
    Server::actingAs(Fixtures::makeSuper())
        ->tool(TermsUpdate::class, ['id' => 'topics::alpha', 'data' => ['title' => 'Nope']])
        ->assertHasErrors();

    expect(Term::find('topics::alpha')->in('en')->data()->get('title'))->toBe('Alpha');
});
