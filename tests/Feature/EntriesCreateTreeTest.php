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

it('nests a new entry under its parent', function () {
    Fixtures::site();
    Fixtures::pages();
    Fixtures::structure();

    $about = Fixtures::page('about', 'About');
    $contact = Fixtures::page('contact', 'Contact');

    Collection::findByHandle('pages')->structure()->in('en')->tree([['entry' => $about], ['entry' => $contact]])->save();

    Server::actingAs(Fixtures::makeUser('create pages entries'))
        ->tool(EntriesCreate::class, ['collection' => 'pages', 'data' => ['title' => 'Team'], 'parent' => $about])
        ->assertOk()
        ->assertSee('"url":"/about/team"')
        ->assertSee(sprintf('"parent":"%s"', $about));

    expect(storedPagesTree())->toBe([
        ['entry' => $about, 'children' => [['entry' => createdPageId('team')]]],
        ['entry' => $contact],
    ]);
});

it('nests under a parent the stored tree does not list yet', function () {
    Fixtures::site();
    Fixtures::pages();
    Fixtures::structure();

    // appendTo() alone would find no such branch in the stored tree and
    // drop the child at the top level.
    $about = Fixtures::page('about', 'About');

    Server::actingAs(Fixtures::makeUser('create pages entries'))
        ->tool(EntriesCreate::class, ['collection' => 'pages', 'data' => ['title' => 'Team'], 'parent' => $about])
        ->assertOk()
        ->assertSee('"url":"/about/team"');

    expect(storedPagesTree())->toBe([['entry' => $about, 'children' => [['entry' => createdPageId('team')]]]]);
});

it('builds nested pages one create after another', function () {
    Fixtures::site();
    Fixtures::pages();
    Fixtures::structure();

    $user = Fixtures::makeUser('create pages entries');

    Server::actingAs($user)
        ->tool(EntriesCreate::class, ['collection' => 'pages', 'data' => ['title' => 'About']])
        ->assertOk();

    Server::actingAs($user)
        ->tool(EntriesCreate::class, ['collection' => 'pages', 'data' => ['title' => 'Team'], 'parent' => createdPageId('about')])
        ->assertOk();

    Server::actingAs($user)
        ->tool(EntriesCreate::class, ['collection' => 'pages', 'data' => ['title' => 'Jobs'], 'parent' => createdPageId('team')])
        ->assertOk()
        ->assertSee('"url":"/about/team/jobs"');

    Stache::clear();

    expect(Entry::find(createdPageId('jobs'))->url())->toBe('/about/team/jobs');
});

it('places an entry whose parent is the root page at the top level', function () {
    Fixtures::site();
    Fixtures::pages();
    Fixtures::structure(root: true);

    $home = Fixtures::page('home', 'Home');
    $about = Fixtures::page('about', 'About');

    Collection::findByHandle('pages')->structure()->in('en')->tree([['entry' => $home], ['entry' => $about]])->save();

    Server::actingAs(Fixtures::makeUser('create pages entries'))
        ->tool(EntriesCreate::class, ['collection' => 'pages', 'data' => ['title' => 'Contact'], 'parent' => $home])
        ->assertOk()
        ->assertSee('"url":"/contact"')
        ->assertSee('"parent":null');

    expect(storedPagesTree())->toBe([['entry' => $home], ['entry' => $about], ['entry' => createdPageId('contact')]]);
});

it('rejects a parent that is not an entry of the collection', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();
    Fixtures::pages();
    Fixtures::structure();

    $post = tap(Entry::make()->collection('blog')->slug('hello')->data(['title' => 'Hello']))->save()->id();

    foreach (['no-such-entry', $post] as $parent) {
        Server::actingAs(Fixtures::makeUser('create pages entries'))
            ->tool(EntriesCreate::class, ['collection' => 'pages', 'data' => ['title' => 'Team'], 'parent' => $parent])
            ->assertHasErrors(["parent '{$parent}' not found in collection 'pages' (site 'en') — pass the id of one of its entries in that site"]);
    }

    expect(Entry::query()->where('collection', 'pages')->count())->toBe(0);
});

it('rejects a parent from another site', function () {
    Fixtures::multisite();
    Fixtures::pages();
    Fixtures::structure();

    $about = Fixtures::page('about', 'About');

    Server::actingAs(Fixtures::makeUser('create pages entries', 'access de site'))
        ->tool(EntriesCreate::class, ['collection' => 'pages', 'data' => ['title' => 'Team'], 'parent' => $about, 'site' => 'de'])
        ->assertHasErrors(["parent '{$about}' not found in collection 'pages' (site 'de') — pass the id of one of its entries in that site"]);
});

it('rejects a parent that would nest the entry deeper than max_depth', function () {
    Fixtures::site();
    Fixtures::pages();
    Fixtures::structure(maxDepth: 2);

    $about = Fixtures::page('about', 'About');
    $team = Fixtures::page('team', 'Team');

    Collection::findByHandle('pages')->structure()->in('en')->tree([['entry' => $about, 'children' => [['entry' => $team]]]])->save();

    Server::actingAs(Fixtures::makeUser('create pages entries'))
        ->tool(EntriesCreate::class, ['collection' => 'pages', 'data' => ['title' => 'Jobs'], 'parent' => $team])
        ->assertHasErrors(["parent '{$team}' is at depth 2 and collection 'pages' allows 2 levels (max_depth) — pick a parent higher up, or omit parent for the top level"]);

    Server::actingAs(Fixtures::makeUser('create pages entries'))
        ->tool(EntriesCreate::class, ['collection' => 'pages', 'data' => ['title' => 'Jobs'], 'parent' => $about])
        ->assertOk()
        ->assertSee('"url":"/about/jobs"');
});

it('rejects parent on a collection whose entries do not nest', function (?int $maxDepth, string $error) {
    Fixtures::site();
    Fixtures::pages();

    if ($maxDepth) {
        Fixtures::structure(maxDepth: $maxDepth);
    }

    $about = Fixtures::page('about', 'About');

    Server::actingAs(Fixtures::makeUser('create pages entries'))
        ->tool(EntriesCreate::class, ['collection' => 'pages', 'data' => ['title' => 'Team'], 'parent' => $about])
        ->assertHasErrors([$error]);

    expect(Entry::query()->where('collection', 'pages')->count())->toBe(1);
})->with([
    'no structure' => [null, "collection 'pages' has no tree — omit parent; only structured collections nest entries"],
    'an orderable structure' => [1, "collection 'pages' is a flat, orderable list (max_depth 1) — omit parent"],
]);
