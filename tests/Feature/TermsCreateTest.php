<?php

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tools\TermsCreate;
use Illuminate\Support\Facades\Event;
use Statamic\Events\TermCreating;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;

it('creates a term with a slug generated from the title', function () {
    Fixtures::site();
    Fixtures::tags();

    $user = Fixtures::makeUser('create tags terms');

    Server::actingAs($user)
        ->tool(TermsCreate::class, ['taxonomy' => 'tags', 'data' => ['title' => 'New Tag']])
        ->assertOk()
        ->assertSee('"id":"tags::new-tag"')
        ->assertSee('created — live')
        ->assertSee('"cp_edit_url"');

    $term = Term::find('tags::new-tag');

    expect($term)->not->toBeNull()
        ->and($term->value('title'))->toBe('New Tag')
        ->and($term->value('updated_by'))->toBe($user->id()); // CP parity: creates carry updated_by/updated_at
});

it('reports a slug collision with the existing id and remedy', function () {
    Fixtures::site();
    Fixtures::tags();

    Term::make()->taxonomy('tags')->slug('php')->data(['title' => 'PHP'])->save();

    $user = Fixtures::makeUser('create tags terms');

    Server::actingAs($user)
        ->tool(TermsCreate::class, ['taxonomy' => 'tags', 'data' => ['title' => 'PHP']])
        ->assertHasErrors(["term 'php' already exists — use terms_update with id 'tags::php'"]);
});

it('normalizes the slug the way Statamic will persist it before checking collisions', function () {
    Fixtures::site();
    Fixtures::tags();

    Term::make()->taxonomy('tags')->slug('hello-world')->data(['title' => 'Hello'])->save();

    // Term::slug() re-normalizes 'Hello World' to 'hello-world' on save — a
    // raw-value collision query would miss this and overwrite the existing term.
    Server::actingAs(Fixtures::makeUser('create tags terms'))
        ->tool(TermsCreate::class, ['taxonomy' => 'tags', 'data' => ['title' => 'Fresh'], 'slug' => 'Hello World'])
        ->assertHasErrors(["term 'hello-world' already exists — use terms_update with id 'tags::hello-world'"]);
});

it('rejects a title that normalizes to an empty slug', function () {
    Fixtures::site();
    Fixtures::tags();

    Server::actingAs(Fixtures::makeUser('create tags terms'))
        ->tool(TermsCreate::class, ['taxonomy' => 'tags', 'data' => ['title' => '🎉🎉🎉']])
        ->assertHasErrors(['pass a slug, or include a title in data so one can be generated']);

    expect(Term::query()->where('taxonomy', 'tags')->count())->toBe(0);
});

it('rejects unknown field keys with a did-you-mean hint', function () {
    Fixtures::site();
    Fixtures::tags();

    $user = Fixtures::makeUser('create tags terms');

    Server::actingAs($user)
        ->tool(TermsCreate::class, ['taxonomy' => 'tags', 'data' => ['titel' => 'PHP']])
        ->assertHasErrors(["unknown field titel — valid handles: title — did you mean 'title' instead of 'titel'?"]);
});

it('rejects reserved handles inside data', function () {
    Fixtures::site();
    Fixtures::tags();

    Server::actingAs(Fixtures::makeUser('create tags terms'))
        ->tool(TermsCreate::class, ['taxonomy' => 'tags', 'data' => ['title' => 'Hi', 'id' => 'tags::sneaky']])
        ->assertHasErrors(['field id is reserved — never writable via data']);
});

it('surfaces blueprint validation failures as field messages', function () {
    Fixtures::site();
    Fixtures::tags();

    $user = Fixtures::makeUser('create tags terms');

    // title is required by the tag blueprint
    Server::actingAs($user)
        ->tool(TermsCreate::class, ['taxonomy' => 'tags', 'data' => ['title' => ''], 'slug' => 'untitled'])
        ->assertHasErrors()
        ->assertSee('validation failed');
});

it('denies creating without the create permission', function () {
    Fixtures::site();
    Fixtures::tags();

    $user = Fixtures::makeUser('view tags terms'); // can view, cannot create

    Server::actingAs($user)
        ->tool(TermsCreate::class, ['taxonomy' => 'tags', 'data' => ['title' => 'Nope']])
        ->assertHasErrors(["requires 'create tags terms' — grant it to a role of {$user->email()} in the Control Panel"]);
});

it('refuses a non-default site and points to terms_update for localization', function () {
    Fixtures::multisite();
    Fixtures::tags();
    Taxonomy::findByHandle('tags')->sites(['en', 'de'])->save();

    $user = Fixtures::makeUser('create tags terms', 'access de site');

    Server::actingAs($user)
        ->tool(TermsCreate::class, ['taxonomy' => 'tags', 'data' => ['title' => 'Neu'], 'site' => 'de'])
        ->assertHasErrors(["terms are created in the default site 'en' — create the term first, then localize it with terms_update and site 'de'"]);
});

it("denies creation when the user cannot access the taxonomy's origin site", function () {
    Fixtures::multisite();
    Fixtures::tags();
    // Origin ('de', the FIRST configured site) is not the global default —
    // the created term would live in a site this user cannot access.
    Taxonomy::findByHandle('tags')->sites(['de', 'en'])->save();

    $user = Fixtures::makeUser('create tags terms'); // no 'access de site'

    Server::actingAs($user)
        ->tool(TermsCreate::class, ['taxonomy' => 'tags', 'data' => ['title' => 'Neu']])
        ->assertHasErrors(["requires 'access de site' — grant it to a role of {$user->email()} in the Control Panel"]);

    expect(Term::query()->where('taxonomy', 'tags')->count())->toBe(0);
});

it('accepts the origin site even when it is not the global default', function () {
    Fixtures::multisite();
    Fixtures::tags();
    Taxonomy::findByHandle('tags')->sites(['de', 'en'])->save();

    // site 'de' is accepted because the comparison targets the taxonomy's
    // FIRST configured site, not the global default ('en').
    Server::actingAs(Fixtures::makeUser('create tags terms', 'access de site'))
        ->tool(TermsCreate::class, ['taxonomy' => 'tags', 'data' => ['title' => 'Neu'], 'site' => 'de'])
        ->assertOk()
        ->assertSee('"id":"tags::neu"')
        ->assertSee('"site":"de"')
        ->assertSee('created — live');

    expect(Term::find('tags::neu'))->not->toBeNull();
});

it('reports a listener-cancelled save instead of claiming success', function () {
    Fixtures::site();
    Fixtures::tags();

    // Approval-workflow addons cancel saves by returning false from
    // TermCreating/TermSaving; Term::save() then returns false.
    Event::listen(TermCreating::class, fn () => false);

    Server::actingAs(Fixtures::makeUser('create tags terms'))
        ->tool(TermsCreate::class, ['taxonomy' => 'tags', 'data' => ['title' => 'Hi']])
        ->assertHasErrors(['the save was cancelled by a listener — nothing was created']);

    expect(Term::query()->where('taxonomy', 'tags')->count())->toBe(0);
});

it('is hidden when the server is read-only', function () {
    Fixtures::site();
    Fixtures::tags();

    config(['statamic.mcp.read_only' => true]);

    // Either the registration gate (shouldRegister) or the in-handler
    // re-check rejects the call — both are errors, which is all that matters.
    Server::actingAs(Fixtures::makeSuper())
        ->tool(TermsCreate::class, ['taxonomy' => 'tags', 'data' => ['title' => 'Nope']])
        ->assertHasErrors();

    expect(Term::query()->where('taxonomy', 'tags')->count())->toBe(0);
});
