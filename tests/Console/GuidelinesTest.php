<?php

use Danielgnh\StatamicMcp\Support\GuidelineFiles;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Illuminate\Support\Facades\File;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Fieldset;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Taxonomy;

function guidelinesPath(string $file = ''): string
{
    return config('statamic.mcp.guidelines_path').($file === '' ? '' : '/'.$file);
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

it('creates site.md and a file per exposed collection', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    Collection::make('secrets')->title('Secrets')->save();

    config(['statamic.mcp.resources.collections' => ['blog']]);

    $this->artisan('statamic:mcp:guidelines')
        ->expectsOutputToContain('Created  '.guidelinesPath('site.md'))
        ->expectsOutputToContain('Created  '.guidelinesPath('collections/blog.md'))
        ->assertExitCode(0);

    expect(File::exists(guidelinesPath('collections/secrets.md')))->toBeFalse()
        ->and(File::get(guidelinesPath('collections/blog.md')))->toContain('Guidelines for AI agents writing blog entries')
        // a stub is all comment, so agents get nothing until someone writes below it
        ->and(app(GuidelineFiles::class)->site())->toBeNull()
        ->and(app(GuidelineFiles::class)->for('collections', 'blog', 'article'))->toBeNull();
});

it('never overwrites a guideline file', function () {
    Fixtures::site();

    File::ensureDirectoryExists(guidelinesPath());
    File::put(guidelinesPath('site.md'), 'Friendly, never salesy.');

    $this->artisan('statamic:mcp:guidelines')
        ->expectsOutputToContain('Guideline files already exist in '.guidelinesPath().'.')
        ->assertExitCode(0);

    expect(File::get(guidelinesPath('site.md')))->toBe('Friendly, never salesy.');
});

it('lists blocks without instructions once, however many blueprints share them', function () {
    Fixtures::site();

    pageBuilder();

    $this->artisan('statamic:mcp:guidelines')
        // hero and columns have instructions; the hidden old_banner is not counted
        ->expectsOutputToContain('2 of 4 blocks have instructions.')
        ->expectsTable(['Block', 'Field', 'Blueprints'], [
            ['logo_wall', 'page_builder', 'collections.landing.landing, collections.pages.page'],
            ['text', 'page_builder.columns.items', 'collections.landing.landing, collections.pages.page'],
        ])
        ->assertExitCode(0);
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

    $this->artisan('statamic:mcp:guidelines')
        ->expectsTable(['Block', 'Field', 'Blueprints'], [
            ['quote', 'body', 'taxonomies.tags.tag'],
            ['cta', 'blocks', 'globals.settings'],
        ])
        ->assertExitCode(0);

    config(['statamic.mcp.resources.globals' => []]);

    $this->artisan('statamic:mcp:guidelines')
        ->expectsTable(['Block', 'Field', 'Blueprints'], [
            ['quote', 'body', 'taxonomies.tags.tag'],
        ])
        ->assertExitCode(0);
});
