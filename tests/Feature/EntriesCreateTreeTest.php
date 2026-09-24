<?php

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tools\EntriesCreate;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Stache;

function storedPagesTree(string $site = 'en'): array
{
    // tree() would append entries missing from the stored tree; fileData()
    // is what is actually on disk.
    Stache::clear();

    return Collection::findByHandle('pages')->structure()->in($site)->fileData()['tree'];
}

function createdPageId(string $slug): string
{
    return Entry::query()->where('collection', 'pages')->where('slug', $slug)->first()->id();
}

it('places a new entry of a structured collection in its tree right away', function () {
    Fixtures::site();
    Fixtures::pages();
    Fixtures::structure();

    // Entries created outside the CP are missing from the stored tree;
    // Statamic only appends them when the tree is read.
    $home = Fixtures::page('home', 'Home');

    Server::actingAs(Fixtures::makeUser('create pages entries'))
        ->tool(EntriesCreate::class, ['collection' => 'pages', 'data' => ['title' => 'About']])
        ->assertOk()
        ->assertSee('"url":"/about"');

    expect(storedPagesTree())->toBe([['entry' => $home], ['entry' => createdPageId('about')]]);
});

it('leaves the tree of an orderable collection alone, as the CP does', function () {
    Fixtures::site();
    Fixtures::pages();
    Fixtures::structure(maxDepth: 1);

    Fixtures::page('home', 'Home');

    Server::actingAs(Fixtures::makeUser('create pages entries'))
        ->tool(EntriesCreate::class, ['collection' => 'pages', 'data' => ['title' => 'About']])
        ->assertOk()
        ->assertSee('"url":"/about"');

    expect(storedPagesTree())->toBe([]);
});
