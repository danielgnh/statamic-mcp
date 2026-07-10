<?php

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tools\TermsGet;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;

function longBardTagDescription(): array
{
    // ~1400 chars encoded — comfortably over the 500-byte preview threshold (T11).
    return [[
        'type' => 'paragraph',
        'content' => [['type' => 'text', 'text' => str_repeat('Statamic taxonomies explained at length. ', 40)]],
    ]];
}

// Overwrites the Fixtures::tags() blueprint with an added bard field.
function tagBlueprintWithBard(): void
{
    Blueprint::makeFromFields([
        'title' => ['type' => 'text', 'validate' => 'required'],
        'description' => ['type' => 'bard'],
    ])->setHandle('tag')->setNamespace('taxonomies.tags')->save();
}

it('gets raw term data by id', function () {
    Fixtures::site();
    Fixtures::tags();

    Term::make()->taxonomy('tags')->slug('php')->data(['title' => 'PHP'])->save();

    $user = Fixtures::makeUser('view tags terms');

    Server::actingAs($user)
        ->tool(TermsGet::class, ['id' => 'tags::php'])
        ->assertOk()
        ->assertSee('"format":"raw"')
        ->assertSee('"title":"PHP"')
        ->assertSee('"id":"tags::php"');
});

it('gets a term by taxonomy and slug', function () {
    Fixtures::site();
    Fixtures::tags();

    Term::make()->taxonomy('tags')->slug('php')->data(['title' => 'PHP'])->save();

    $user = Fixtures::makeUser('view tags terms');

    Server::actingAs($user)
        ->tool(TermsGet::class, ['taxonomy' => 'tags', 'slug' => 'php'])
        ->assertOk()
        ->assertSee('"id":"tags::php"');
});

it('reads a site localization override', function () {
    Fixtures::multisite();
    Fixtures::tags();
    Taxonomy::findByHandle('tags')->sites(['en', 'de'])->save();

    $term = Term::make()->taxonomy('tags')->slug('php')->data(['title' => 'PHP']);
    $term->dataForLocale('de', ['title' => 'PHP (DE)']);
    $term->save();

    $user = Fixtures::makeUser('view tags terms', 'access de site');

    Server::actingAs($user)
        ->tool(TermsGet::class, ['id' => 'tags::php', 'site' => 'de'])
        ->assertOk()
        ->assertSee('"title":"PHP (DE)"')
        ->assertSee('"origin_site":"en"');
});

it('annotates values inherited from the default site', function () {
    Fixtures::multisite();
    Fixtures::tags();
    Taxonomy::findByHandle('tags')->sites(['en', 'de'])->save();

    Term::make()->taxonomy('tags')->slug('php')->data(['title' => 'PHP'])->save();

    $user = Fixtures::makeUser('view tags terms', 'access de site');

    Server::actingAs($user)
        ->tool(TermsGet::class, ['id' => 'tags::php', 'site' => 'de'])
        ->assertOk()
        ->assertSee('"inherited":{"title":"PHP"}')
        ->assertSee('"data":[]'); // no local overrides yet — empty PHP array encodes as []
});

it('requires the site permission for a non-default site', function () {
    Fixtures::multisite();
    Fixtures::tags();
    Taxonomy::findByHandle('tags')->sites(['en', 'de'])->save();

    Term::make()->taxonomy('tags')->slug('php')->data(['title' => 'PHP'])->save();

    $user = Fixtures::makeUser('view tags terms'); // no 'access de site'

    Server::actingAs($user)
        ->tool(TermsGet::class, ['id' => 'tags::php', 'site' => 'de'])
        ->assertHasErrors(["requires 'access de site' — grant it to a role of {$user->email()} in the Control Panel"]);
});

it('marks augmented data as not writable', function () {
    Fixtures::site();
    Fixtures::tags();

    Term::make()->taxonomy('tags')->slug('php')->data(['title' => 'PHP'])->save();

    $user = Fixtures::makeUser('view tags terms');

    Server::actingAs($user)
        ->tool(TermsGet::class, ['id' => 'tags::php', 'format' => 'augmented'])
        ->assertOk()
        ->assertSee('augmented values are read-only');
});

it('names a remedy when the term does not exist', function () {
    Fixtures::site();
    Fixtures::tags();

    $user = Fixtures::makeUser('view tags terms');

    Server::actingAs($user)
        ->tool(TermsGet::class, ['id' => 'tags::nope'])
        ->assertHasErrors(["term 'tags::nope' not found — use terms_list with taxonomy 'tags' to see available terms"]);
});

it('denies reading without the view permission', function () {
    Fixtures::site();
    Fixtures::tags();

    Term::make()->taxonomy('tags')->slug('php')->data(['title' => 'PHP'])->save();

    $user = Fixtures::makeUser(); // 'access mcp' only

    Server::actingAs($user)
        ->tool(TermsGet::class, ['id' => 'tags::php'])
        ->assertHasErrors(["requires 'view tags terms' — grant it to a role of {$user->email()} in the Control Panel"]);
});

it('treats an unexposed taxonomy as missing, listing only exposed handles', function () {
    Fixtures::site();
    Fixtures::tags();
    tap(Taxonomy::make('secrets')->title('Secrets'))->save();

    Term::make()->taxonomy('secrets')->slug('hush')->data(['title' => 'Hush'])->save();

    config(['statamic.mcp.resources.taxonomies' => ['tags']]);

    Server::actingAs(Fixtures::makeSuper())
        ->tool(TermsGet::class, ['id' => 'secrets::hush'])
        ->assertHasErrors(["taxonomy 'secrets' not found — available: tags"]);
});

it('truncates long bard values to preview objects', function () {
    Fixtures::site();
    Fixtures::tags();
    tagBlueprintWithBard();

    Term::make()->taxonomy('tags')->slug('php')
        ->data(['title' => 'PHP', 'description' => longBardTagDescription()])
        ->save();

    $user = Fixtures::makeUser('view tags terms');

    Server::actingAs($user)
        ->tool(TermsGet::class, ['id' => 'tags::php'])
        ->assertOk()
        ->assertSee('__preview')
        ->assertSee('"truncated":true')
        ->assertSee('NOT writable — fetch raw field before editing');
});

it('returns the full raw bard value when requested via fields', function () {
    Fixtures::site();
    Fixtures::tags();
    tagBlueprintWithBard();

    Term::make()->taxonomy('tags')->slug('php')
        ->data(['title' => 'PHP', 'description' => longBardTagDescription()])
        ->save();

    $user = Fixtures::makeUser('view tags terms');

    Server::actingAs($user)
        ->tool(TermsGet::class, ['id' => 'tags::php', 'fields' => ['description']])
        ->assertOk()
        ->assertSee('"type":"paragraph"')
        ->assertSee('"type":"text"')
        // fields is a selection, not just a truncation bypass — unselected data stays out
        ->assertDontSee('"title":"PHP"');
});

it('rejects unknown field handles naming valid ones', function () {
    Fixtures::site();
    Fixtures::tags();

    Term::make()->taxonomy('tags')->slug('php')->data(['title' => 'PHP'])->save();

    $user = Fixtures::makeUser('view tags terms');

    Server::actingAs($user)
        ->tool(TermsGet::class, ['id' => 'tags::php', 'fields' => ['titel']])
        ->assertHasErrors(['unknown field titel — valid handles: title']);
});

it('reports updated_at with default-locale fallback and strips it from raw data', function () {
    Fixtures::multisite();
    Fixtures::tags();
    Taxonomy::findByHandle('tags')->sites(['en', 'de'])->save();

    $term = Term::make()->taxonomy('tags')->slug('php')
        ->data(['title' => 'PHP', 'updated_at' => 1700000000, 'updated_by' => 'someone']);
    $term->dataForLocale('de', ['title' => 'PHP (DE)']);
    $term->save();

    $user = Fixtures::makeUser('view tags terms', 'access de site');

    // de has no local updated_at — value('updated_at') recurses to the default
    // locale (T17 coherence decision), while the raw data/inherited blocks stay
    // free of Statamic-managed metadata so agents can't round-trip stale values.
    Server::actingAs($user)
        ->tool(TermsGet::class, ['id' => 'tags::php', 'site' => 'de'])
        ->assertOk()
        ->assertSee('"updated_at":"2023-11-14T22:13:20+00:00"')
        ->assertDontSee('"updated_at":1700000000')
        ->assertDontSee('"updated_by"');

    // The default-site view carries the metadata in its OWN data — it must be
    // stripped there too, not just from the inherited diff.
    Server::actingAs($user)
        ->tool(TermsGet::class, ['id' => 'tags::php'])
        ->assertOk()
        ->assertSee('"updated_at":"2023-11-14T22:13:20+00:00"')
        ->assertDontSee('"updated_at":1700000000')
        ->assertDontSee('"updated_by"');
});
