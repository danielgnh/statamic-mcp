<?php

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tools\TermsList;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;

it('lists terms in an exposed taxonomy with summary columns', function () {
    Fixtures::site();
    Fixtures::tags();

    Term::make()->taxonomy('tags')->slug('php')->data(['title' => 'PHP'])->save();
    Term::make()->taxonomy('tags')->slug('laravel')->data(['title' => 'Laravel'])->save();

    $user = Fixtures::makeUser('view tags terms');

    Server::actingAs($user)
        ->tool(TermsList::class, ['taxonomy' => 'tags'])
        ->assertOk()
        ->assertSee('"slug":"php"')
        ->assertSee('"slug":"laravel"')
        ->assertSee('"title":"PHP"')
        ->assertSee('"id":"tags::php"')
        ->assertSee('"url"')
        ->assertSee('"updated_at"')
        ->assertSee('"total":2');
});

it('paginates alphabetically by title and reports the next page, capping per_page at 100', function () {
    Fixtures::site();
    Fixtures::tags();

    // Saved out of alphabetical order on purpose — the listing must sort by title.
    Term::make()->taxonomy('tags')->slug('gamma')->data(['title' => 'Gamma'])->save();
    Term::make()->taxonomy('tags')->slug('alpha')->data(['title' => 'Alpha'])->save();
    Term::make()->taxonomy('tags')->slug('beta')->data(['title' => 'Beta'])->save();

    $user = Fixtures::makeUser('view tags terms');

    Server::actingAs($user)
        ->tool(TermsList::class, ['taxonomy' => 'tags', 'limit' => 2, 'page' => 1])
        ->assertOk()
        ->assertSee('"total":3')
        ->assertSee('"next_page":2')
        ->assertSee('"slug":"alpha"')
        ->assertSee('"slug":"beta"')
        ->assertDontSee('"slug":"gamma"');

    Server::actingAs($user)
        ->tool(TermsList::class, ['taxonomy' => 'tags', 'limit' => 2, 'page' => 2])
        ->assertOk()
        ->assertSee('"slug":"gamma"')
        ->assertDontSee('"slug":"alpha"')
        ->assertSee('"next_page":null');

    // An over-limit value does not error — it is silently clamped to 100,
    // mirroring entries_list (04-entries Task 10).
    Server::actingAs($user)
        ->tool(TermsList::class, ['taxonomy' => 'tags', 'limit' => 500])
        ->assertOk()
        ->assertSee('"per_page":100');
});

it('filters terms by title search', function () {
    Fixtures::site();
    Fixtures::tags();

    Term::make()->taxonomy('tags')->slug('php')->data(['title' => 'PHP'])->save();
    Term::make()->taxonomy('tags')->slug('laravel')->data(['title' => 'Laravel'])->save();

    $user = Fixtures::makeUser('view tags terms');

    Server::actingAs($user)
        ->tool(TermsList::class, ['taxonomy' => 'tags', 'search' => 'lara'])
        ->assertOk()
        ->assertSee('"slug":"laravel"')
        ->assertSee('"total":1')
        ->assertDontSee('"slug":"php"');
});

it('lists the requested site\'s view of each term', function () {
    Fixtures::multisite();
    Fixtures::tags();
    Taxonomy::findByHandle('tags')->sites(['en', 'de'])->save();

    // One term, localized: the de localization overrides the title.
    $term = Term::make()->taxonomy('tags')->slug('php')->data(['title' => 'PHP']);
    $term->dataForLocale('de', ['title' => 'PHP auf Deutsch']);
    $term->save();

    $user = Fixtures::makeUser('view tags terms', 'access de site');

    Server::actingAs($user)
        ->tool(TermsList::class, ['taxonomy' => 'tags', 'site' => 'de'])
        ->assertOk()
        ->assertSee('"site":"de"')
        ->assertSee('"title":"PHP auf Deutsch"')
        ->assertSee('"total":1');

    // The default (en) listing shows the en view of the same term.
    Server::actingAs($user)
        ->tool(TermsList::class, ['taxonomy' => 'tags'])
        ->assertOk()
        ->assertSee('"site":"en"')
        ->assertSee('"title":"PHP"')
        ->assertSee('"total":1');
});

it('rejects a configured site the taxonomy is not available in', function () {
    Fixtures::multisite();
    Fixtures::tags();
    Taxonomy::findByHandle('tags')->sites(['en'])->save();

    // 'de' is a configured site, but the tags taxonomy only lives in 'en'.
    Server::actingAs(Fixtures::makeSuper())
        ->tool(TermsList::class, ['taxonomy' => 'tags', 'site' => 'de'])
        ->assertHasErrors(["site 'de' not found — available: en"]);
});

it('rejects an unknown site naming the available ones', function () {
    Fixtures::site();
    Fixtures::tags();

    Server::actingAs(Fixtures::makeSuper())
        ->tool(TermsList::class, ['taxonomy' => 'tags', 'site' => 'fr'])
        ->assertHasErrors(["site 'fr' not found — available: en"]);
});

it("requires 'access {site} site' for non-default sites", function () {
    Fixtures::multisite();
    Fixtures::tags();
    Taxonomy::findByHandle('tags')->sites(['en', 'de'])->save();

    $user = Fixtures::makeUser('view tags terms');

    Server::actingAs($user)
        ->tool(TermsList::class, ['taxonomy' => 'tags', 'site' => 'de'])
        ->assertHasErrors(["requires 'access de site' — grant it to a role of {$user->email()} in the Control Panel"]);

    Server::actingAs(Fixtures::makeUser('view tags terms', 'access de site'))
        ->tool(TermsList::class, ['taxonomy' => 'tags', 'site' => 'de'])
        ->assertOk()
        ->assertSee('"total":0');
});

it('denies listing without the view permission', function () {
    Fixtures::site();
    Fixtures::tags();

    $user = Fixtures::makeUser(); // 'access mcp' only

    Server::actingAs($user)
        ->tool(TermsList::class, ['taxonomy' => 'tags'])
        ->assertHasErrors(["requires 'view tags terms' — grant it to a role of {$user->email()} in the Control Panel"]);
});

it('treats an unexposed taxonomy as missing, listing only exposed handles', function () {
    Fixtures::site();
    Fixtures::tags();
    tap(Taxonomy::make('secrets')->title('Secrets'))->save();

    config(['statamic.mcp.resources.taxonomies' => ['tags']]);

    Server::actingAs(Fixtures::makeSuper())
        ->tool(TermsList::class, ['taxonomy' => 'secrets'])
        ->assertHasErrors(["taxonomy 'secrets' not found — available: tags"]);
});
