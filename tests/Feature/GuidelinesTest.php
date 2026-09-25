<?php

declare(strict_types=1);

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Support\AgentGuidelines;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tools\BlueprintsGet;
use Danielgnh\StatamicMcp\Tools\StatamicOverview;
use Illuminate\Support\Facades\File;

/**
 * @param  array<string, mixed>  $values
 */
function guidelines(array $values): void
{
    app(AgentGuidelines::class)->save($values);
}

/**
 * @param  list<string>  $collections
 * @param  list<string>  $taxonomies
 * @return array<string, mixed>
 */
function row(array $collections, string $guidelines, array $taxonomies = []): array
{
    return ['type' => 'resource', 'enabled' => true, 'collections' => $collections, 'taxonomies' => $taxonomies, 'guidelines' => $guidelines];
}

it('returns the site guidelines from statamic_overview', function () {
    Fixtures::site();

    guidelines(['site' => "# Voice\n\nFriendly, never salesy.\n"]);

    Server::actingAs(Fixtures::makeSuper())
        ->tool(StatamicOverview::class, [])
        ->assertOk()
        ->assertSee('"guidelines":"# Voice\n\nFriendly, never salesy."');
});

it('leaves guidelines out of statamic_overview when the site has none', function () {
    Fixtures::site();

    Server::actingAs(Fixtures::makeSuper())
        ->tool(StatamicOverview::class, [])
        ->assertOk()
        ->assertDontSee('"guidelines":');

    guidelines(['site' => "  \n"]);

    Server::actingAs(Fixtures::makeSuper())
        ->tool(StatamicOverview::class, [])
        ->assertOk()
        ->assertDontSee('"guidelines":');
});

it('returns the rows naming the collection from blueprints_get, in their order', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    guidelines(['resources' => [
        row(['blog'], 'Every post ends with a question.'),
        row(['pages'], 'Pages open with a hero.'),
        row(['pages', 'blog'], "Short paragraphs.\n"),
    ]]);

    Server::actingAs(Fixtures::makeUser('view blog entries'))
        ->tool(BlueprintsGet::class, ['type' => 'collection', 'handle' => 'blog'])
        ->assertOk()
        ->assertSee('"available_blueprints":["article"],"guidelines":"Every post ends with a question.\n\nShort paragraphs.","fields"')
        ->assertDontSee('Pages open with a hero.');
});

it('returns the rows naming the taxonomy from blueprints_get', function () {
    Fixtures::site();
    Fixtures::tags();

    guidelines(['resources' => [
        row(['tags'], 'A collection called tags, not the taxonomy.'),
        row([], 'Tags are lowercase nouns.', ['tags']),
    ]]);

    Server::actingAs(Fixtures::makeUser('view tags terms'))
        ->tool(BlueprintsGet::class, ['type' => 'taxonomy', 'handle' => 'tags'])
        ->assertOk()
        ->assertSee('"guidelines":"Tags are lowercase nouns."');
});

it('skips rows that are switched off', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    guidelines(['resources' => [
        [...row(['blog'], 'An old rule nobody follows any more.'), 'enabled' => false],
        row(['blog'], 'Every post ends with a question.'),
    ]]);

    Server::actingAs(Fixtures::makeUser('view blog entries'))
        ->tool(BlueprintsGet::class, ['type' => 'collection', 'handle' => 'blog'])
        ->assertOk()
        ->assertSee('"guidelines":"Every post ends with a question."')
        ->assertDontSee('An old rule');
});

it('keeps guidelines behind the same permission as the blueprint', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    guidelines(['resources' => [row(['blog'], 'Secret editorial plan.')]]);

    Server::actingAs(Fixtures::makeUser())
        ->tool(BlueprintsGet::class, ['type' => 'collection', 'handle' => 'blog'])
        ->assertHasErrors()
        ->assertDontSee('Secret editorial plan.');
});

// Statamic renders addon settings through Antlers as it loads them, so a
// hand-edited file with a broken tag throws. Agents carry on without
// guidelines rather than losing statamic_overview.
it('serves statamic_overview without guidelines when the settings fail to load', function () {
    Fixtures::site();

    File::ensureDirectoryExists(resource_path('addons'));
    File::put(resource_path('addons/statamic-mcp.yaml'), "guidelines:\n  site: 'Never type {{ in copy.'\n");

    Server::actingAs(Fixtures::makeSuper())
        ->tool(StatamicOverview::class, [])
        ->assertOk()
        ->assertDontSee('"guidelines":');
});

// A 0.6.0 site keeps its guidelines in a global set until mcp:guidelines moves
// them, and agents must not lose them in between.
it('serves the guidelines of a 0.6.0 global set until they are moved', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    Fixtures::legacyGuidelinesSet(['en' => [
        'site' => 'Friendly, never salesy.',
        'resources' => [row(['blog'], 'Every post ends with a question.')],
    ]]);

    Server::actingAs(Fixtures::makeSuper())
        ->tool(StatamicOverview::class, [])
        ->assertOk()
        ->assertSee('"guidelines":"Friendly, never salesy."');

    Server::actingAs(Fixtures::makeUser('view blog entries'))
        ->tool(BlueprintsGet::class, ['type' => 'collection', 'handle' => 'blog'])
        ->assertOk()
        ->assertSee('"guidelines":"Every post ends with a question."');

    guidelines(['site' => 'Written on the new page.']);

    Server::actingAs(Fixtures::makeSuper())
        ->tool(StatamicOverview::class, [])
        ->assertOk()
        ->assertSee('"guidelines":"Written on the new page."')
        ->assertDontSee('never salesy');
});
