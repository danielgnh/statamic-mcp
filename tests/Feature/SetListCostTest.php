<?php

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\CountedSetId;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tools\EntriesUpdate;
use Danielgnh\StatamicMcp\Tools\GlobalsUpdate;
use Danielgnh\StatamicMcp\Tools\TermsUpdate;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Entry;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;

// Each test stores 500 sets and allows a few reads of each stored id. A
// lookup that scans the stored list for every set sent reads them 500 times.
const LONG_LIST = 500;
const READS_PER_SET = 3;

/**
 * @param  Closure(int): array<string, mixed>  $set
 * @return list<array<string, mixed>>
 */
function longSetList(string $prefix, Closure $set): array
{
    return array_map(fn (int $i) => ['id' => sprintf('%s%05d', $prefix, $i), ...$set($i)], range(1, LONG_LIST));
}

/**
 * @param  list<array<string, mixed>>  $sets
 * @return list<array{id: string}>
 */
function idsInReverse(array $sets): array
{
    return array_map(fn (array $set) => ['id' => $set['id']], array_reverse($sets));
}

/**
 * The landing entry with the list stored, and counted ids on the instance
 * the tools read: the Stache array cache hands out the same object.
 *
 * @param  list<array<string, mixed>>  $sets
 */
function landingEntryWith(string $handle, array $sets, string $idKey = 'id'): string
{
    Fixtures::site();
    Fixtures::assetContainer('images');
    Fixtures::landing();

    $id = tap(Entry::make()->collection('landing')->slug('home')->data(['title' => 'Home', $handle => $sets]))->save()->id();

    Entry::find($id)->set($handle, CountedSetId::in($sets, $idKey));
    CountedSetId::$reads = 0;

    return $id;
}

function blocksBlueprint(string $handle, string $namespace): void
{
    Blueprint::makeFromFields([
        'title' => ['type' => 'text'],
        'blocks' => ['type' => 'replicator', 'sets' => ['main' => ['sets' => ['line' => ['fields' => [
            ['handle' => 'text', 'field' => ['type' => 'text']],
        ]]]]]],
    ])->setHandle($handle)->setNamespace($namespace)->save();
}

it('reorders a long page builder reading each stored set a fixed number of times', function (string $handle, array $sets) {
    $id = landingEntryWith($handle, $sets);

    Server::actingAs(Fixtures::makeUser('edit landing entries'))
        ->tool(EntriesUpdate::class, ['id' => $id, 'data' => [$handle => idsInReverse($sets)]])
        ->assertOk();

    expect(CountedSetId::$reads)->toBeGreaterThan(0)->toBeLessThanOrEqual(READS_PER_SET * LONG_LIST)
        ->and(array_column(Entry::find($id)->get($handle), 'id'))->toBe(array_column(array_reverse($sets), 'id'));
})->with([
    'Replicator sets' => ['page_builder', longSetList('blk', fn (int $i) => ['type' => 'section_text_block', 'text' => "Block {$i}"])],
    'Grid rows' => ['facts', longSetList('row', fn (int $i) => ['label' => "Row {$i}"])],
]);

it('merges into every set of a long page builder by id reading each stored set a fixed number of times', function () {
    $sets = longSetList('blk', fn (int $i) => ['type' => 'section_text_block', 'text' => "Block {$i}"]);
    $id = landingEntryWith('page_builder', $sets);

    $patches = array_fill_keys(array_column($sets, 'id'), ['text' => 'Changed']);

    Server::actingAs(Fixtures::makeUser('edit landing entries'))
        ->tool(EntriesUpdate::class, ['id' => $id, 'data' => ['page_builder' => $patches]])
        ->assertOk();

    expect(CountedSetId::$reads)->toBeGreaterThan(0)->toBeLessThanOrEqual(READS_PER_SET * LONG_LIST)
        ->and(array_unique(array_column(Entry::find($id)->get('page_builder'), 'text')))->toBe(['Changed']);
});

it('rewrites a Bard value with many sets reading each stored set a fixed number of times', function () {
    $nodes = array_map(
        fn (array $set) => ['type' => 'set', 'attrs' => ['id' => $set['id'], 'values' => ['type' => 'callout', 'text' => $set['text']]]],
        longSetList('bset', fn (int $i) => ['text' => "Callout {$i}"]),
    );
    $id = landingEntryWith('body', $nodes, 'attrs.id');

    Server::actingAs(Fixtures::makeUser('edit landing entries'))
        ->tool(EntriesUpdate::class, ['id' => $id, 'data' => ['body' => array_reverse($nodes)]])
        ->assertOk();

    expect(CountedSetId::$reads)->toBeGreaterThan(0)->toBeLessThanOrEqual(READS_PER_SET * LONG_LIST)
        ->and(data_get(Entry::find($id)->get('body'), '0.attrs.id'))->toBe(sprintf('bset%05d', LONG_LIST));
});

it('reorders a long set list on a term reading each stored set a fixed number of times', function () {
    Fixtures::site();
    tap(Taxonomy::make('tags')->title('Tags'))->save();
    blocksBlueprint('tag', 'taxonomies.tags');

    $sets = longSetList('lin', fn (int $i) => ['type' => 'line', 'text' => "Line {$i}"]);
    Term::make('php')->taxonomy('tags')->dataForLocale('en', ['title' => 'PHP', 'blocks' => $sets])->save();

    Term::find('tags::php')->set('blocks', CountedSetId::in($sets));
    CountedSetId::$reads = 0;

    Server::actingAs(Fixtures::makeUser('edit tags terms'))
        ->tool(TermsUpdate::class, ['id' => 'tags::php', 'data' => ['blocks' => idsInReverse($sets)]])
        ->assertOk();

    expect(CountedSetId::$reads)->toBeGreaterThan(0)->toBeLessThanOrEqual(READS_PER_SET * LONG_LIST)
        ->and(array_column(Term::find('tags::php')->get('blocks'), 'id'))->toBe(array_column(array_reverse($sets), 'id'));
});

it('reorders a long set list on a global set reading each stored set a fixed number of times', function () {
    Fixtures::site();
    blocksBlueprint('settings', 'globals');

    $sets = longSetList('lin', fn (int $i) => ['type' => 'line', 'text' => "Line {$i}"]);
    $global = tap(GlobalSet::make('settings')->title('Settings'))->save();
    $global->makeLocalization(Site::default()->handle())->data(['blocks' => $sets])->save();

    GlobalSet::findByHandle('settings')->in('en')->set('blocks', CountedSetId::in($sets));
    CountedSetId::$reads = 0;

    Server::actingAs(Fixtures::makeUser('edit settings globals'))
        ->tool(GlobalsUpdate::class, ['handle' => 'settings', 'data' => ['blocks' => idsInReverse($sets)]])
        ->assertOk();

    expect(CountedSetId::$reads)->toBeGreaterThan(0)->toBeLessThanOrEqual(READS_PER_SET * LONG_LIST)
        ->and(array_column(GlobalSet::findByHandle('settings')->in('en')->get('blocks'), 'id'))->toBe(array_column(array_reverse($sets), 'id'));
});
