<?php

use Danielgnh\StatamicMcp\Support\AgentGuidelines;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
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

it('moves the guidelines out of the 0.6.0 global set and deletes the set', function () {
    Fixtures::site();

    Fixtures::legacyGuidelinesSet(['en' => [
        'site' => 'Friendly, never salesy.',
        'resources' => [['id' => 'a', 'type' => 'resource', 'enabled' => true, 'collections' => ['blog'], 'guidelines' => 'Every post ends with a question.']],
    ]]);

    expect(guidelinesOutput())->toContain('Moved  the guidelines from the guidelines global set to Tools → MCP → Guidelines.');

    $guidelines = app(AgentGuidelines::class);

    expect($guidelines->site())->toBe('Friendly, never salesy.')
        ->and($guidelines->for('collections', 'blog'))->toBe('Every post ends with a question.')
        ->and(GlobalSet::find('guidelines'))->toBeNull()
        ->and(Blueprint::find('globals.guidelines'))->toBeNull();
});

it('moves the set named by the old guidelines config key', function () {
    Fixtures::site();

    config(['statamic.mcp.guidelines' => 'agent_rules']);

    Fixtures::legacyGuidelinesSet(['en' => ['site' => 'Friendly, never salesy.']], 'agent_rules');

    expect(guidelinesOutput())->toContain('from the agent_rules global set');

    expect(app(AgentGuidelines::class)->site())->toBe('Friendly, never salesy.')
        ->and(GlobalSet::find('agent_rules'))->toBeNull();
});

it('leaves a guidelines set with other fields alone, since it is the site content', function () {
    Fixtures::site();

    Blueprint::make('guidelines')->setNamespace('globals')->setContents(['tabs' => ['main' => ['sections' => [['fields' => [
        ['handle' => 'site', 'field' => ['type' => 'text']],
        ['handle' => 'brand_colors', 'field' => ['type' => 'text']],
    ]]]]]])->save();
    GlobalSet::make('guidelines')->title('Brand guidelines')->save();
    GlobalSet::find('guidelines')->makeLocalization('en')->data(['site' => 'Our brand book.'])->save();

    expect(guidelinesOutput())->not->toContain('global set from 0.6.0');

    expect(GlobalSet::find('guidelines'))->not->toBeNull()
        ->and(app(AgentGuidelines::class)->values())->toBe([]);
});

it('still moves the set after a save with nothing in it on the new page', function () {
    Fixtures::site();

    app(AgentGuidelines::class)->save(['site' => null, 'resources' => []]);
    Fixtures::legacyGuidelinesSet(['en' => ['site' => 'Friendly, never salesy.']]);

    expect(guidelinesOutput())->toContain('Moved  the guidelines');

    expect(app(AgentGuidelines::class)->site())->toBe('Friendly, never salesy.');
});

it('keeps the set when the guidelines page already has guidelines', function () {
    Fixtures::site();

    app(AgentGuidelines::class)->save(['site' => 'Written on the new page.']);
    Fixtures::legacyGuidelinesSet(['en' => ['site' => 'Friendly, never salesy.']]);

    expect(Str::squish(guidelinesOutput()))->toContain('already has guidelines, so agents no longer read the guidelines global set from 0.6.0.');

    expect(app(AgentGuidelines::class)->site())->toBe('Written on the new page.')
        ->and(GlobalSet::find('guidelines'))->not->toBeNull();
});

it('keeps the set when its text would run as template code in addon settings', function () {
    Fixtures::site();

    Fixtures::legacyGuidelinesSet(['en' => [
        'resources' => [['id' => 'a', 'type' => 'resource', 'collections' => ['blog'], 'guidelines' => 'End with {{ partial:cta }}.']],
    ]]);

    expect(Str::squish(guidelinesOutput()))->toContain('The guidelines global set from 0.6.0 stays, and agents keep reading it: its text contains');

    expect(app(AgentGuidelines::class)->values())->toBe([])
        ->and(GlobalSet::find('guidelines'))->not->toBeNull();
});

it('copies the guidelines but keeps the set when its other sites hold text', function () {
    Fixtures::multisite();

    Fixtures::legacyGuidelinesSet([
        'en' => ['site' => 'Friendly, never salesy.'],
        'de' => ['site' => 'Freundlich, nie werblich.'],
    ]);

    expect(Str::squish(guidelinesOutput()))->toContain('Copied the guidelines from the guidelines global set')
        ->toContain('The set stays: its other sites hold text too');

    expect(app(AgentGuidelines::class)->site())->toBe('Friendly, never salesy.')
        ->and(GlobalSet::find('guidelines'))->not->toBeNull();
});

it('deletes the set when its other sites hold nothing', function () {
    Fixtures::multisite();

    Fixtures::legacyGuidelinesSet([
        'en' => ['site' => 'Friendly, never salesy.'],
        'de' => ['site' => null, 'resources' => []],
    ]);

    expect(guidelinesOutput())->toContain('Moved  the guidelines');

    expect(GlobalSet::find('guidelines'))->toBeNull();
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
