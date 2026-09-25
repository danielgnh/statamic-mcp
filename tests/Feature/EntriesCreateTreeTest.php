<?php

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tools\EntriesCreate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Statamic\Events\CollectionTreeSaving;
use Statamic\Events\EntrySaving;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Stache;
use Statamic\Stache\Indexes\Index;

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

    expect(Fixtures::storedPagesTree())->toBe([['entry' => $home], ['entry' => Fixtures::pageId('about')]]);
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

    expect(Fixtures::storedPagesTree())->toBe([]);
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

    expect(Fixtures::storedPagesTree())->toBe([
        ['entry' => $about, 'children' => [['entry' => Fixtures::pageId('team')]]],
        ['entry' => $contact],
    ]);
});

it('makes the acting user the author of an entry it nests under a parent', function () {
    Fixtures::site();
    Fixtures::pages();
    Fixtures::structure();
    Fixtures::authors('pages');

    $about = Fixtures::page('about', 'About');
    $user = Fixtures::makeUser('create pages entries');

    Server::actingAs($user)
        ->tool(EntriesCreate::class, ['collection' => 'pages', 'data' => ['title' => 'Team'], 'parent' => $about])
        ->assertOk()
        ->assertSee('"url":"/about/team"');

    expect(Entry::query()->where('collection', 'pages')->where('slug', 'team')->first()->get('author'))->toBe($user->id());
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

    expect(Fixtures::storedPagesTree())->toBe([['entry' => $about, 'children' => [['entry' => Fixtures::pageId('team')]]]]);
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
        ->tool(EntriesCreate::class, ['collection' => 'pages', 'data' => ['title' => 'Team'], 'parent' => Fixtures::pageId('about')])
        ->assertOk();

    Server::actingAs($user)
        ->tool(EntriesCreate::class, ['collection' => 'pages', 'data' => ['title' => 'Jobs'], 'parent' => Fixtures::pageId('team')])
        ->assertOk()
        ->assertSee('"url":"/about/team/jobs"');

    Stache::clear();

    expect(Entry::find(Fixtures::pageId('jobs'))->url())->toBe('/about/team/jobs');
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

    expect(Fixtures::storedPagesTree())->toBe([['entry' => $home], ['entry' => $about], ['entry' => Fixtures::pageId('contact')]]);
});

it('places an entry at the top level when parent is an empty string', function () {
    Fixtures::site();
    Fixtures::pages();
    Fixtures::structure();

    $about = Fixtures::page('about', 'About');

    Server::actingAs(Fixtures::makeUser('create pages entries'))
        ->tool(EntriesCreate::class, ['collection' => 'pages', 'data' => ['title' => 'Contact'], 'parent' => ''])
        ->assertOk()
        ->assertSee('"url":"/contact"')
        ->assertSee('"parent":null');

    expect(Fixtures::storedPagesTree())->toBe([['entry' => $about], ['entry' => Fixtures::pageId('contact')]]);
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
        ->assertHasErrors(["parent '{$team}' is at depth 2, so the entry would be at depth 3, past the 2 levels collection 'pages' allows (max_depth) — pick a parent higher up, or the top level"]);

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

it('keeps a tree change another call saved while it was creating an entry', function () {
    Fixtures::site();
    Fixtures::pages();
    Fixtures::structure();

    $about = Fixtures::page('about', 'About');
    $contact = Fixtures::page('contact', 'Contact');

    Collection::findByHandle('pages')->structure()->in('en')->tree([['entry' => $about], ['entry' => $contact]])->save();

    // After this call read the tree, another one moves Contact under About.
    $moved = false;

    Event::listen(EntrySaving::class, function () use (&$moved, $about, $contact) {
        if ($moved) {
            return;
        }

        $moved = true;

        Collection::findByHandle('pages')->structure()
            ->makeTree('en', [['entry' => $about, 'children' => [['entry' => $contact]]]])
            ->save();
    });

    Server::actingAs(Fixtures::makeUser('create pages entries'))
        ->tool(EntriesCreate::class, ['collection' => 'pages', 'data' => ['title' => 'Team'], 'parent' => $about])
        ->assertOk();

    expect(Fixtures::storedPagesTree())->toBe([
        ['entry' => $about, 'children' => [['entry' => $contact], ['entry' => Fixtures::pageId('team')]]],
    ]);
});

it('saves the tree under the collection lock of its site', function () {
    Fixtures::site();
    Fixtures::pages();
    Fixtures::structure();

    $locked = null;

    Event::listen(CollectionTreeSaving::class, function () use (&$locked) {
        $locked ??= ! Cache::lock('statamic-mcp-tree:pages:en', 10)->get();
    });

    Server::actingAs(Fixtures::makeUser('create pages entries'))
        ->tool(EntriesCreate::class, ['collection' => 'pages', 'data' => ['title' => 'About']])
        ->assertOk();

    expect($locked)->toBeTrue();
});

it('refuses a URL another entry already has, as the CP does', function () {
    Fixtures::site();
    Fixtures::blog();
    Fixtures::pages();
    Fixtures::structure();

    $post = tap(Entry::make()->collection('blog')->slug('hello')->data(['title' => 'Hello']))->save()->id();
    $blog = Fixtures::page('blog', 'Blog');

    Server::actingAs(Fixtures::makeUser('create pages entries'))
        ->tool(EntriesCreate::class, ['collection' => 'pages', 'data' => ['title' => 'Hello'], 'parent' => $blog])
        ->assertHasErrors([sprintf("URL '/blog/hello' already belongs to entry '%s' in collection 'blog' — pick another slug or parent", $post)]);

    expect(Entry::query()->where('collection', 'pages')->where('slug', 'hello')->count())->toBe(0);
});

it('refuses a URL another entry already has in a collection without a tree', function () {
    Fixtures::site();
    Fixtures::blog();
    Fixtures::pages();

    Collection::findByHandle('blog')->routes('/{slug}')->save();

    $post = tap(Entry::make()->collection('blog')->slug('hello')->data(['title' => 'Hello']))->save()->id();

    Server::actingAs(Fixtures::makeUser('create pages entries'))
        ->tool(EntriesCreate::class, ['collection' => 'pages', 'data' => ['title' => 'Hello']])
        ->assertHasErrors([sprintf("URL '/hello' already belongs to entry '%s' in collection 'blog' — pick another slug", $post)]);

    expect(Entry::query()->where('collection', 'pages')->where('slug', 'hello')->count())->toBe(0);
});

it('gives a page a slug another page has under a different parent, as the CP does', function () {
    Fixtures::site();
    Fixtures::pages();
    Fixtures::structure();

    $products = Fixtures::page('products', 'Products');
    $services = Fixtures::page('services', 'Services');
    $overview = Fixtures::page('overview', 'Overview');

    Collection::findByHandle('pages')->structure()->in('en')->tree([
        ['entry' => $products, 'children' => [['entry' => $overview]]],
        ['entry' => $services],
    ])->save();

    Server::actingAs(Fixtures::makeUser('create pages entries'))
        ->tool(EntriesCreate::class, ['collection' => 'pages', 'data' => ['title' => 'Overview'], 'parent' => $services])
        ->assertOk()
        ->assertSee('"slug":"overview"')
        ->assertSee('"url":"/services/overview"');

    // The same URL is still refused.
    Server::actingAs(Fixtures::makeUser('create pages entries'))
        ->tool(EntriesCreate::class, ['collection' => 'pages', 'data' => ['title' => 'Overview'], 'parent' => $products])
        ->assertHasErrors([sprintf("URL '/products/overview' already belongs to entry '%s' in collection 'pages' — pick another slug or parent", $overview)]);

    $created = Entry::query()->where('collection', 'pages')->where('slug', 'overview')->get()->map->id()->reject(fn (string $id) => $id === $overview)->sole();

    expect(Fixtures::storedPagesTree())->toBe([
        ['entry' => $products, 'children' => [['entry' => $overview]]],
        ['entry' => $services, 'children' => [['entry' => $created]]],
    ])
        ->and(Entry::find($overview)->url())->toBe('/products/overview')
        ->and(Entry::find($created)->url())->toBe('/services/overview');
});

it('keeps slugs unique when the blueprint asks for it with unique_entry_value', function () {
    Fixtures::site();
    Fixtures::pages();
    Fixtures::structure();

    Blueprint::find('collections.pages.page')
        ->ensureFieldHasConfig('slug', ['validate' => ['required', 'max:200', 'new \Statamic\Rules\UniqueEntryValue({collection}, {id}, {site})']])
        ->save();

    $products = Fixtures::page('products', 'Products');
    $services = Fixtures::page('services', 'Services');
    $overview = Fixtures::page('overview', 'Overview');

    Collection::findByHandle('pages')->structure()->in('en')->tree([
        ['entry' => $products, 'children' => [['entry' => $overview]]],
        ['entry' => $services],
    ])->save();

    Server::actingAs(Fixtures::makeUser('create pages entries'))
        ->tool(EntriesCreate::class, ['collection' => 'pages', 'data' => ['title' => 'Overview'], 'parent' => $services])
        ->assertHasErrors(['validation failed: {"slug":["This value has already been taken."]}']);

    expect(Entry::query()->where('collection', 'pages')->where('slug', 'overview')->count())->toBe(1);
});

it('keeps the entries another process placed while its Stache still lacks them', function () {
    Fixtures::site();
    Fixtures::pages();
    Fixtures::structure();

    $about = Fixtures::page('about', 'About');

    Collection::findByHandle('pages')->structure()->in('en')->tree([['entry' => $about]])->save();

    // Another process nests a new entry under About after this request
    // loaded the Stache: the shared cache knows the entry, and this
    // process's memoized indexes, loaded earlier, don't.
    $moved = false;

    Event::listen(EntrySaving::class, function () use (&$moved, $about) {
        if ($moved) {
            return;
        }

        $moved = true;

        $contact = Fixtures::page('contact', 'Contact');

        Collection::findByHandle('pages')->structure()
            ->makeTree('en', [['entry' => $about, 'children' => [['entry' => $contact]]]])
            ->save();

        app('stache.indexes')->each(fn (Index $index) => (function () use ($contact) {
            unset($this->items[$contact]);
        })->call($index));
    });

    Server::actingAs(Fixtures::makeUser('create pages entries'))
        ->tool(EntriesCreate::class, ['collection' => 'pages', 'data' => ['title' => 'Team'], 'parent' => $about])
        ->assertOk();

    expect(Fixtures::storedPagesTree())->toBe([
        ['entry' => $about, 'children' => [['entry' => Fixtures::pageId('contact')], ['entry' => Fixtures::pageId('team')]]],
    ]);
});
