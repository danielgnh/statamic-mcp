<?php

use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Illuminate\Support\Facades\Artisan;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Fieldset;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Taxonomy;

function guidelinesOutput(): string
{
    expect(Artisan::call('statamic:mcp:guidelines'))->toBe(0);

    return Artisan::output();
}

function pageBuilder(): void
{
    Fieldset::make('page_builder')->setContents(['fields' => [
        ['handle' => 'page_builder', 'field' => [
            'type' => 'replicator',
            'sets' => [
                'main' => ['sets' => [
                    'hero' => ['display' => 'Hero', 'instructions' => 'First block only.'],
                    'logo_wall' => ['display' => 'Logo Wall'],
                    'columns' => ['display' => 'Columns', 'instructions' => 'Two to four columns.', 'fields' => [
                        ['handle' => 'items', 'field' => ['type' => 'replicator', 'sets' => [
                            'main' => ['sets' => ['text' => ['display' => 'Text']]],
                        ]]],
                    ]],
                    'old_banner' => ['display' => 'Old Banner', 'hide' => true],
                ]],
            ],
        ]],
    ]])->save();

    foreach (['pages' => 'page', 'landing' => 'landing'] as $collection => $blueprint) {
        Collection::make($collection)->title(ucfirst($collection))->save();

        Blueprint::make($blueprint)->setNamespace("collections.{$collection}")->setContents([
            'tabs' => ['main' => ['sections' => [['fields' => [
                ['handle' => 'title', 'field' => ['type' => 'text']],
                ['import' => 'page_builder'],
            ]]]]],
        ])->save();
    }
}

it('points to the guidelines page', function () {
    Fixtures::site();

    expect(guidelinesOutput())->toContain('under Tools → MCP → Guidelines.')
        ->toContain(cp_route('mcp.guidelines.edit'));
});

it('lists blocks without instructions once, under the blueprints that share them', function () {
    Fixtures::site();

    pageBuilder();

    // hero and columns have instructions; the hidden old_banner is not counted
    expect(guidelinesOutput())
        ->toContain('2 of 4 blocks have instructions.')
        ->toContain("  In collections.landing.landing, collections.pages.page\n    page_builder: logo_wall\n    page_builder.columns.items: text\n")
        ->not->toContain("In collections.pages.page\n");
});

it('lists the sets of one field on one line, wrapped to the terminal', function () {
    Fixtures::site();

    Collection::make('pages')->title('Pages')->save();

    $sets = collect(range(1, 12))->mapWithKeys(fn (int $i) => ["block_number_{$i}" => ['display' => "Block {$i}"]]);

    Blueprint::makeFromFields([
        'title' => ['type' => 'text'],
        'page_builder' => ['type' => 'replicator', 'sets' => ['main' => ['sets' => $sets->all()]]],
    ])->setHandle('page')->setNamespace('collections.pages')->save();

    putenv('COLUMNS=80');

    try {
        $output = guidelinesOutput();
    } finally {
        putenv('COLUMNS');
    }

    expect($output)
        ->toContain("  In collections.pages.page\n    page_builder: block_number_1, block_number_2, block_number_3,\n      block_number_4, ");
});

it('says so when every block has instructions', function () {
    Fixtures::site();

    Collection::make('pages')->title('Pages')->save();

    Blueprint::makeFromFields([
        'title' => ['type' => 'text'],
        'page_builder' => ['type' => 'replicator', 'sets' => ['main' => ['sets' => [
            'hero' => ['display' => 'Hero', 'instructions' => 'First block only.'],
        ]]]],
    ])->setHandle('page')->setNamespace('collections.pages')->save();

    $this->artisan('statamic:mcp:guidelines')
        ->expectsOutputToContain('All 1 blocks have instructions.')
        ->assertExitCode(0);
});

it('says so when the site has no page builder', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $this->artisan('statamic:mcp:guidelines')
        ->expectsOutputToContain('No page builder blocks found.')
        ->assertExitCode(0);
});

it('skips blocks in collections that are not exposed', function () {
    Fixtures::site();

    pageBuilder();

    config(['statamic.mcp.resources.collections' => []]);

    $this->artisan('statamic:mcp:guidelines')
        ->expectsOutputToContain('No page builder blocks found.')
        ->assertExitCode(0);
});

it('scans taxonomy and global blueprints, respecting exposure', function () {
    Fixtures::site();

    tap(Taxonomy::make('tags')->title('Tags'))->save();

    Blueprint::makeFromFields([
        'title' => ['type' => 'text'],
        'body' => ['type' => 'bard', 'sets' => ['main' => ['sets' => ['quote' => ['display' => 'Quote']]]]],
    ])->setHandle('tag')->setNamespace('taxonomies.tags')->save();

    Blueprint::makeFromFields([
        'blocks' => ['type' => 'replicator', 'sets' => ['cta' => ['display' => 'CTA']]],
    ])->setHandle('settings')->setNamespace('globals')->save();

    GlobalSet::make('settings')->title('Settings')->save();
    GlobalSet::make('empty')->title('Empty')->save();

    expect(guidelinesOutput())
        ->toContain("  In taxonomies.tags.tag\n    body: quote\n")
        ->toContain("  In globals.settings\n    blocks: cta\n");

    config(['statamic.mcp.resources.globals' => []]);

    expect(guidelinesOutput())
        ->toContain("  In taxonomies.tags.tag\n    body: quote\n")
        ->not->toContain('globals.settings');
});

it('lists blocks inside grid and group fields', function () {
    Fixtures::site();

    Collection::make('pages')->title('Pages')->save();

    Blueprint::makeFromFields([
        'title' => ['type' => 'text'],
        'rows' => ['type' => 'grid', 'fields' => [
            ['handle' => 'cells', 'field' => ['type' => 'replicator', 'sets' => ['main' => ['sets' => [
                'badge' => ['display' => 'Badge'],
            ]]]]],
        ]],
        'seo' => ['type' => 'group', 'fields' => [
            ['handle' => 'extras', 'field' => ['type' => 'replicator', 'sets' => ['main' => ['sets' => [
                'chip' => ['display' => 'Chip'],
            ]]]]],
        ]],
    ])->setHandle('page')->setNamespace('collections.pages')->save();

    expect(guidelinesOutput())
        ->toContain('0 of 2 blocks have instructions.')
        ->toContain("  In collections.pages.page\n    rows.cells: badge\n    seo.extras: chip\n");
});

it('counts blocks of unrelated blueprints apart, even at the same path', function () {
    Fixtures::site();

    foreach (['pages' => 'Home page hero.', 'guides' => null] as $collection => $instructions) {
        Collection::make($collection)->title(ucfirst($collection))->save();

        Blueprint::makeFromFields([
            'title' => ['type' => 'text'],
            'page_builder' => ['type' => 'replicator', 'sets' => ['main' => ['sets' => [
                'hero' => array_filter(['display' => 'Hero', 'instructions' => $instructions]),
            ]]]],
        ])->setHandle('page')->setNamespace("collections.{$collection}")->save();
    }

    expect(guidelinesOutput())
        ->toContain('1 of 2 blocks have instructions.')
        ->toContain("  In collections.guides.page\n    page_builder: hero\n");
});
