<?php

declare(strict_types=1);

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Support\GuidelinesSet;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tools\BlueprintsGet;
use Danielgnh\StatamicMcp\Tools\StatamicOverview;
use Statamic\Facades\GlobalSet;

/**
 * @param  array<string, mixed>  $data
 */
function guidelines(array $data): void
{
    $set = app(GuidelinesSet::class);

    $set->create();

    GlobalSet::find($set->handle())->makeLocalization('en')->data($data)->save();
}

/**
 * @param  list<string>  $collections
 * @param  list<string>  $taxonomies
 * @return array<string, mixed>
 */
function row(array $collections, string $guidelines, array $taxonomies = []): array
{
    return ['type' => 'resource', 'collections' => $collections, 'taxonomies' => $taxonomies, 'guidelines' => $guidelines];
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

    // the set itself is listed under globals; only the guidelines key must be missing
    Server::actingAs(Fixtures::makeSuper())
        ->tool(StatamicOverview::class, [])
        ->assertOk()
        ->assertSee('"handle":"guidelines"')
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

it('reads the global set named in config', function () {
    Fixtures::site();

    config(['statamic.mcp.guidelines' => 'agent_rules']);

    guidelines(['site' => 'Friendly, never salesy.']);

    expect(GlobalSet::find('agent_rules'))->not->toBeNull();

    Server::actingAs(Fixtures::makeSuper())
        ->tool(StatamicOverview::class, [])
        ->assertOk()
        ->assertSee('"guidelines":"Friendly, never salesy."');
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
