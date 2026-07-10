<?php

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tools\EntriesGet;
use Statamic\Facades\Entry;

/**
 * @return array{0: Statamic\Contracts\Entries\Entry, 1: Statamic\Contracts\Entries\Entry} [origin (en), localization (de)]
 */
function makeLocalizedBlogEntry(): array
{
    $origin = tap(
        Entry::make()
            ->collection('blog')
            ->slug('hello')
            ->locale('en')
            ->data(['title' => 'Hello', 'hero_image' => 'hero.jpg'])
            ->published(true)
    )->save();

    // de overrides only the title; hero_image stays inherited from the origin
    $localization = tap(
        $origin->makeLocalization('de')->data(['title' => 'Hallo'])
    )->save();

    return [$origin, $localization];
}

it('rejects a site that does not match the entry id, listing localization ids', function () {
    Fixtures::multisite();
    Fixtures::tags();
    Fixtures::blog();

    [$origin, $localization] = makeLocalizedBlogEntry();

    Server::actingAs(Fixtures::makeSuper())
        ->tool(EntriesGet::class, ['id' => $origin->id(), 'site' => 'de'])
        ->assertHasErrors([
            "entry '{$origin->id()}' belongs to site 'en', not 'de' — pass the matching localization id instead (or omit site). Localizations: en => {$origin->id()}; de => {$localization->id()}",
        ]);
});

it('accepts a site that matches the entry id', function () {
    Fixtures::multisite();
    Fixtures::tags();
    Fixtures::blog();

    [$origin] = makeLocalizedBlogEntry();

    Server::actingAs(Fixtures::makeSuper())
        ->tool(EntriesGet::class, ['id' => $origin->id(), 'site' => 'en'])
        ->assertOk()
        ->assertSee('"site":"en"');
});

it('annotates inherited vs local fields with the origin id', function () {
    Fixtures::multisite();
    Fixtures::tags();
    Fixtures::blog();

    [$origin, $localization] = makeLocalizedBlogEntry();

    Server::actingAs(Fixtures::makeSuper())
        ->tool(EntriesGet::class, ['id' => $localization->id()])
        ->assertOk()
        ->assertSee('"origin_id":"'.$origin->id().'"')
        ->assertSee('"local_overrides":["title"]')
        ->assertSee('"inherited_from_origin":["hero_image"]')
        ->assertSee('"hero_image":"hero.jpg"')  // inherited value is shown
        ->assertSee('"title":"Hallo"');         // local override wins
});

it('keeps the origin annotation out of origin entries and augmented output', function () {
    Fixtures::multisite();
    Fixtures::tags();
    Fixtures::blog();

    [$origin, $localization] = makeLocalizedBlogEntry();

    // the origin itself has no origin — nothing is inherited
    Server::actingAs(Fixtures::makeSuper())
        ->tool(EntriesGet::class, ['id' => $origin->id()])
        ->assertOk()
        ->assertDontSee('origin_id');

    // augmented output is display-only — the raw write-shape annotation would mislead
    Server::actingAs(Fixtures::makeSuper())
        ->tool(EntriesGet::class, ['id' => $localization->id(), 'format' => 'augmented'])
        ->assertOk()
        ->assertDontSee('local_overrides');
});

it("requires 'access {site} site' to read a non-default-site entry by id", function () {
    Fixtures::multisite();
    Fixtures::tags();
    Fixtures::blog();

    [, $localization] = makeLocalizedBlogEntry();

    $user = Fixtures::makeUser('view blog entries');

    Server::actingAs($user)
        ->tool(EntriesGet::class, ['id' => $localization->id()])
        ->assertHasErrors(["requires 'access de site' — grant it to a role of {$user->email()} in the Control Panel"]);

    Server::actingAs(Fixtures::makeUser('view blog entries', 'access de site'))
        ->tool(EntriesGet::class, ['id' => $localization->id()])
        ->assertOk();
});

it('selects the localization via site with collection + slug', function () {
    Fixtures::multisite();
    Fixtures::tags();
    Fixtures::blog();

    makeLocalizedBlogEntry();

    Server::actingAs(Fixtures::makeSuper())
        ->tool(EntriesGet::class, ['collection' => 'blog', 'slug' => 'hello', 'site' => 'de'])
        ->assertOk()
        ->assertSee('"site":"de"')
        ->assertSee('"title":"Hallo"');
});

// Pins the trait's mismatch-before-access ordering: the correction (with the ids to
// use instead) beats the denial — safe, since the denial names the site anyway.
it('reports the site mismatch before denying site access', function () {
    Fixtures::multisite();
    Fixtures::tags();
    Fixtures::blog();

    [$origin, $localization] = makeLocalizedBlogEntry();

    Server::actingAs(Fixtures::makeUser('view blog entries')) // no 'access de site'
        ->tool(EntriesGet::class, ['id' => $localization->id(), 'site' => 'en'])
        ->assertHasErrors([
            "entry '{$localization->id()}' belongs to site 'de', not 'en' — pass the matching localization id instead (or omit site). Localizations: en => {$origin->id()}; de => {$localization->id()}",
        ])
        ->assertDontSee('access de site');
});

// Regression pin (T11 re-review): exposure is checked before the site match, so an
// unexposed entry with a mismatched site must stay indistinguishable from missing.
it('reports a plain not-found for an unexposed entry even with a mismatched site', function () {
    Fixtures::multisite();
    Fixtures::tags();
    Fixtures::blog();

    [$origin] = makeLocalizedBlogEntry();

    config(['statamic.mcp.resources.collections' => []]);

    Server::actingAs(Fixtures::makeSuper())
        ->tool(EntriesGet::class, ['id' => $origin->id(), 'site' => 'de'])
        ->assertHasErrors(["entry '{$origin->id()}' not found"])
        ->assertDontSee('belongs to site');
});
