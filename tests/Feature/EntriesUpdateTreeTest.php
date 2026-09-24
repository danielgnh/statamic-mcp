<?php

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tokens\TokenRepository;
use Danielgnh\StatamicMcp\Tools\EntriesUpdate;
use Illuminate\Support\Facades\Event;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Events\CollectionTreeSaving;
use Statamic\Events\EntrySaving;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Stache;

function storePagesTree(array $tree, string $site = 'en'): void
{
    Collection::findByHandle('pages')->structure()->in($site)->tree($tree)->save();
}

function updateEntryOverHttp(UserContract $user, array $arguments): array
{
    $token = app(TokenRepository::class)->issue($user, 'http')->token;

    return test()->withHeaders([
        'Authorization' => "Bearer {$token}",
        'Accept' => 'application/json, text/event-stream',
    ])->postJson('/mcp/statamic', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => ['name' => 'entries_update', 'arguments' => $arguments],
    ])->json();
}

it('moves an entry under another entry', function () {
    Fixtures::site();
    Fixtures::pages();
    Fixtures::structure();

    $about = Fixtures::page('about', 'About');
    $contact = Fixtures::page('contact', 'Contact');

    storePagesTree([['entry' => $about], ['entry' => $contact]]);

    Server::actingAs(Fixtures::makeUser('edit pages entries', 'reorder pages entries'))
        ->tool(EntriesUpdate::class, ['id' => $contact, 'data' => [], 'parent' => $about])
        ->assertOk()
        ->assertSee('"url":"/about/contact"')
        ->assertSee('"result":"moved — data unchanged, nothing else saved"')
        ->assertSee(sprintf('"parent":"%s","move":"moved under \'%s\'"', $about, $about));

    expect(Fixtures::storedPagesTree())->toBe([['entry' => $about, 'children' => [['entry' => $contact]]]]);
});

it('moves an entry together with its children, as the last child of its new parent', function () {
    Fixtures::site();
    Fixtures::pages();
    Fixtures::structure();

    $about = Fixtures::page('about', 'About');
    $team = Fixtures::page('team', 'Team');
    $jobs = Fixtures::page('jobs', 'Jobs');
    $contact = Fixtures::page('contact', 'Contact');
    $office = Fixtures::page('office', 'Office');

    storePagesTree([
        ['entry' => $about, 'children' => [['entry' => $team, 'children' => [['entry' => $jobs]]]]],
        ['entry' => $contact, 'children' => [['entry' => $office]]],
    ]);

    Server::actingAs(Fixtures::makeUser('edit pages entries', 'reorder pages entries'))
        ->tool(EntriesUpdate::class, ['id' => $team, 'data' => [], 'parent' => $contact])
        ->assertOk()
        ->assertSee('"url":"/contact/team"');

    expect(Fixtures::storedPagesTree())->toBe([
        ['entry' => $about],
        ['entry' => $contact, 'children' => [['entry' => $office], ['entry' => $team, 'children' => [['entry' => $jobs]]]]],
    ])
        ->and(Entry::find($jobs)->url())->toBe('/contact/team/jobs');
});

it('moves an entry to the top level when parent is an empty string', function () {
    Fixtures::site();
    Fixtures::pages();
    Fixtures::structure();

    $about = Fixtures::page('about', 'About');
    $team = Fixtures::page('team', 'Team');

    storePagesTree([['entry' => $about, 'children' => [['entry' => $team]]]]);

    Server::actingAs(Fixtures::makeUser('edit pages entries', 'reorder pages entries'))
        ->tool(EntriesUpdate::class, ['id' => $team, 'data' => [], 'parent' => ''])
        ->assertOk()
        ->assertSee('"url":"/team"')
        ->assertSee('"parent":null,"move":"moved to the top level"');

    expect(Fixtures::storedPagesTree())->toBe([['entry' => $about], ['entry' => $team]]);
});

it('leaves the entry in place when parent is omitted or null', function (array $parent) {
    Fixtures::site();
    Fixtures::pages();
    Fixtures::structure();

    $about = Fixtures::page('about', 'About');
    $team = Fixtures::page('team', 'Team');

    storePagesTree([['entry' => $about, 'children' => [['entry' => $team]]]]);

    // No reorder permission: a call that does not move needs none.
    Server::actingAs(Fixtures::makeUser('edit pages entries'))
        ->tool(EntriesUpdate::class, ['id' => $team, 'data' => ['title' => 'The team'], ...$parent])
        ->assertOk()
        ->assertDontSee('"move"');

    expect(Fixtures::storedPagesTree())->toBe([['entry' => $about, 'children' => [['entry' => $team]]]])
        ->and(Entry::find($team)->get('title'))->toBe('The team');
})->with([
    'omitted' => [[]],
    'null' => [['parent' => null]],
]);

it('tells a JSON null parent from an empty one over HTTP', function () {
    Fixtures::site();
    Fixtures::pages();
    Fixtures::structure();

    $about = Fixtures::page('about', 'About');
    $team = Fixtures::page('team', 'Team');

    storePagesTree([['entry' => $about, 'children' => [['entry' => $team]]]]);

    $user = Fixtures::makeUser('edit pages entries', 'reorder pages entries');

    // The HTTP transport decodes the raw body, so Laravel's
    // ConvertEmptyStringsToNull never turns "" into null.
    $kept = updateEntryOverHttp($user, ['id' => $team, 'data' => ['title' => 'The team'], 'parent' => null]);

    expect(data_get($kept, 'result.isError'))->toBeFalse()
        ->and(Fixtures::storedPagesTree())->toBe([['entry' => $about, 'children' => [['entry' => $team]]]]);

    $moved = updateEntryOverHttp($user, ['id' => $team, 'data' => [], 'parent' => '']);

    expect(data_get($moved, 'result.isError'))->toBeFalse()
        ->and(data_get($moved, 'result.content.0.text'))->toContain('"move":"moved to the top level"')
        ->and(Fixtures::storedPagesTree())->toBe([['entry' => $about], ['entry' => $team]]);
});

it('is a no-op when the entry is already under that parent', function () {
    Fixtures::site();
    Fixtures::pages();
    Fixtures::structure();

    $about = Fixtures::page('about', 'About');
    $team = Fixtures::page('team', 'Team');

    storePagesTree([['entry' => $about, 'children' => [['entry' => $team]]]]);

    Event::fake([CollectionTreeSaving::class]);

    Server::actingAs(Fixtures::makeUser('edit pages entries', 'reorder pages entries'))
        ->tool(EntriesUpdate::class, ['id' => $team, 'data' => [], 'parent' => $about])
        ->assertOk()
        ->assertSee('no-op — merged result equals the current entry; nothing saved')
        ->assertSee(sprintf('"move":"no-op — already under \'%s\'"', $about));

    Event::assertNotDispatched(CollectionTreeSaving::class);

    Server::actingAs(Fixtures::makeUser('edit pages entries', 'reorder pages entries'))
        ->tool(EntriesUpdate::class, ['id' => $about, 'data' => [], 'parent' => ''])
        ->assertOk()
        ->assertSee('"move":"no-op — already at the top level"');
});

it('applies a data change and a move in one call', function () {
    Fixtures::site();
    Fixtures::pages();
    Fixtures::structure();

    $about = Fixtures::page('about', 'About');
    $contact = Fixtures::page('contact', 'Contact');

    storePagesTree([['entry' => $about], ['entry' => $contact]]);

    Server::actingAs(Fixtures::makeUser('edit pages entries', 'reorder pages entries'))
        ->tool(EntriesUpdate::class, ['id' => $contact, 'data' => ['title' => 'Contact us'], 'parent' => $about])
        ->assertOk()
        ->assertSee('"url":"/about/contact","result":"published"')
        ->assertSee(sprintf('"move":"moved under \'%s\'"', $about));

    expect(Fixtures::storedPagesTree())->toBe([['entry' => $about, 'children' => [['entry' => $contact]]]])
        ->and(Entry::find($contact)->get('title'))->toBe('Contact us');
});

it('moves the live tree at once while the data change goes to a working copy', function () {
    Fixtures::site();
    Fixtures::pages();
    Fixtures::structure();
    Fixtures::revisions('pages');

    $about = Fixtures::page('about', 'About');
    $contact = Fixtures::page('contact', 'Contact');

    storePagesTree([['entry' => $about], ['entry' => $contact]]);

    Server::actingAs(Fixtures::makeUser('edit pages entries', 'reorder pages entries'))
        ->tool(EntriesUpdate::class, ['id' => $contact, 'data' => ['title' => 'Contact us'], 'parent' => $about])
        ->assertOk()
        ->assertSee('working copy created — live entry unchanged')
        ->assertSee(sprintf('"move":"moved under \'%s\' — in the live tree at once; working copies do not stage tree position"', $about))
        ->assertSee('"url":"/about/contact"');

    expect(Fixtures::storedPagesTree())->toBe([['entry' => $about, 'children' => [['entry' => $contact]]]]);

    $live = Entry::find($contact);

    expect($live->get('title'))->toBe('Contact')
        ->and($live->hasWorkingCopy())->toBeTrue();
});

it('rejects a move under the entry itself or one of its descendants', function (string $target, string $error) {
    Fixtures::site();
    Fixtures::pages();
    Fixtures::structure();

    $ids = [
        'about' => Fixtures::page('about', 'About'),
        'team' => Fixtures::page('team', 'Team'),
        'jobs' => Fixtures::page('jobs', 'Jobs'),
    ];

    storePagesTree([['entry' => $ids['about'], 'children' => [['entry' => $ids['team'], 'children' => [['entry' => $ids['jobs']]]]]]]);

    Server::actingAs(Fixtures::makeUser('edit pages entries', 'reorder pages entries'))
        ->tool(EntriesUpdate::class, ['id' => $ids['about'], 'data' => [], 'parent' => $ids[$target]])
        ->assertHasErrors([strtr($error, ['{about}' => $ids['about'], '{target}' => $ids[$target]])]);

    expect(Fixtures::storedPagesTree())->toBe([['entry' => $ids['about'], 'children' => [['entry' => $ids['team'], 'children' => [['entry' => $ids['jobs']]]]]]]);
})->with([
    'itself' => ['about', "entry '{about}' can't be its own parent — pass another entry's id, or \"\" for the top level"],
    'a child' => ['team', "parent '{target}' is inside entry '{about}' — an entry can't move under one of its own descendants"],
    'a grandchild' => ['jobs', "parent '{target}' is inside entry '{about}' — an entry can't move under one of its own descendants"],
]);

it('rejects a move that would take the deepest descendant past max_depth', function () {
    Fixtures::site();
    Fixtures::pages();
    Fixtures::structure(maxDepth: 3);

    $about = Fixtures::page('about', 'About');
    $team = Fixtures::page('team', 'Team');
    $jobs = Fixtures::page('jobs', 'Jobs');
    $contact = Fixtures::page('contact', 'Contact');
    $office = Fixtures::page('office', 'Office');

    storePagesTree([
        ['entry' => $about, 'children' => [['entry' => $team, 'children' => [['entry' => $jobs]]]]],
        ['entry' => $contact, 'children' => [['entry' => $office]]],
    ]);

    $user = Fixtures::makeUser('edit pages entries', 'reorder pages entries');

    Server::actingAs($user)
        ->tool(EntriesUpdate::class, ['id' => $team, 'data' => [], 'parent' => $office])
        ->assertHasErrors(["parent '{$office}' is at depth 2, so the entry's deepest descendant would be at depth 4, past the 3 levels collection 'pages' allows (max_depth) — pick a parent higher up, or the top level"]);

    Server::actingAs($user)
        ->tool(EntriesUpdate::class, ['id' => $jobs, 'data' => [], 'parent' => $office])
        ->assertOk()
        ->assertSee('"url":"/contact/office/jobs"');
});

it('treats the root page as the top level, and never moves the root page', function () {
    Fixtures::site();
    Fixtures::pages();
    Fixtures::structure(root: true);

    $home = Fixtures::page('home', 'Home');
    $about = Fixtures::page('about', 'About');
    $team = Fixtures::page('team', 'Team');

    storePagesTree([['entry' => $home], ['entry' => $about, 'children' => [['entry' => $team]]]]);

    $user = Fixtures::makeUser('edit pages entries', 'reorder pages entries');

    Server::actingAs($user)
        ->tool(EntriesUpdate::class, ['id' => $team, 'data' => [], 'parent' => $home])
        ->assertOk()
        ->assertSee('"url":"/team"')
        ->assertSee('"parent":null,"move":"moved to the top level"');

    Server::actingAs($user)
        ->tool(EntriesUpdate::class, ['id' => $home, 'data' => [], 'parent' => $about])
        ->assertHasErrors(["entry '{$home}' is the root page of collection 'pages' — the root page can't move under another page"]);

    expect(Fixtures::storedPagesTree())->toBe([['entry' => $home], ['entry' => $about], ['entry' => $team]]);
});

it('rejects parent on a collection whose entries do not nest', function (?int $maxDepth, string $error) {
    Fixtures::site();
    Fixtures::pages();

    if ($maxDepth) {
        Fixtures::structure(maxDepth: $maxDepth);
    }

    $about = Fixtures::page('about', 'About');
    $team = Fixtures::page('team', 'Team');

    Server::actingAs(Fixtures::makeUser('edit pages entries', 'reorder pages entries'))
        ->tool(EntriesUpdate::class, ['id' => $team, 'data' => ['title' => 'The team'], 'parent' => $about])
        ->assertHasErrors([$error]);

    expect(Entry::find($team)->get('title'))->toBe('Team');
})->with([
    'no structure' => [null, "collection 'pages' has no tree — omit parent; only structured collections nest entries"],
    'an orderable structure' => [1, "collection 'pages' is a flat, orderable list (max_depth 1) — omit parent"],
]);

it('rejects a parent that is not an entry of the collection in its site', function () {
    Fixtures::multisite();
    Fixtures::tags();
    Fixtures::blog();
    Fixtures::pages();
    Fixtures::structure();

    $team = Fixtures::page('team', 'Team');
    $ueber = Fixtures::page('ueber-uns', 'Über uns', site: 'de');
    $post = tap(Entry::make()->collection('blog')->slug('hello')->data(['title' => 'Hello']))->save()->id();

    foreach (['no-such-entry', $post, $ueber] as $parent) {
        Server::actingAs(Fixtures::makeUser('edit pages entries', 'reorder pages entries', 'access en site'))
            ->tool(EntriesUpdate::class, ['id' => $team, 'data' => [], 'parent' => $parent])
            ->assertHasErrors(["parent '{$parent}' not found in collection 'pages' (site 'en') — pass the id of one of its entries in that site"]);
    }

    expect(Fixtures::storedPagesTree())->toBe([]);
});

it('requires the reorder permission to move an entry, as the CP tree does', function () {
    Fixtures::site();
    Fixtures::pages();
    Fixtures::structure();

    $about = Fixtures::page('about', 'About');
    $contact = Fixtures::page('contact', 'Contact');

    storePagesTree([['entry' => $about], ['entry' => $contact]]);

    $user = Fixtures::makeUser('edit pages entries');

    Server::actingAs($user)
        ->tool(EntriesUpdate::class, ['id' => $contact, 'data' => ['title' => 'Contact us'], 'parent' => $about])
        ->assertHasErrors(["requires 'reorder pages entries' — grant it to a role of {$user->email()} in the Control Panel"]);

    expect(Fixtures::storedPagesTree())->toBe([['entry' => $about], ['entry' => $contact]])
        ->and(Entry::find($contact)->get('title'))->toBe('Contact');
});

it("requires 'edit other authors pages entries' to move someone else's entry, on top of reorder", function () {
    Fixtures::site();
    Fixtures::pages();
    Fixtures::structure();
    Fixtures::authors('pages');

    $about = Fixtures::page('about', 'About');
    $contact = tap(Entry::find(Fixtures::page('contact', 'Contact'))->set('author', Fixtures::makeUser()->id()))->save()->id();

    storePagesTree([['entry' => $about], ['entry' => $contact]]);

    $user = Fixtures::makeUser('edit pages entries', 'reorder pages entries');

    Server::actingAs($user)
        ->tool(EntriesUpdate::class, ['id' => $contact, 'data' => [], 'parent' => $about])
        ->assertHasErrors(["requires 'edit other authors pages entries' — grant it to a role of {$user->email()} in the Control Panel"]);

    // reorder is collection-wide (CollectionPolicy::reorder): it has no other-authors variant.
    $editor = Fixtures::makeUser('edit pages entries', 'edit other authors pages entries');

    Server::actingAs($editor)
        ->tool(EntriesUpdate::class, ['id' => $contact, 'data' => [], 'parent' => $about])
        ->assertHasErrors(["requires 'reorder pages entries' — grant it to a role of {$editor->email()} in the Control Panel"]);

    expect(Fixtures::storedPagesTree())->toBe([['entry' => $about], ['entry' => $contact]]);

    Server::actingAs(Fixtures::makeUser('edit pages entries', 'edit other authors pages entries', 'reorder pages entries'))
        ->tool(EntriesUpdate::class, ['id' => $contact, 'data' => [], 'parent' => $about])
        ->assertOk()
        ->assertSee(sprintf('"move":"moved under \'%s\'"', $about));

    expect(Fixtures::storedPagesTree())->toBe([['entry' => $about, 'children' => [['entry' => $contact]]]]);
});

it('moves entries the stored tree does not list yet', function () {
    Fixtures::site();
    Fixtures::pages();
    Fixtures::structure();

    // Neither is in the stored tree: Tree::move() alone would find no branch
    // to move, or drop the entry when the parent is the missing one.
    $about = Fixtures::page('about', 'About');
    $contact = Fixtures::page('contact', 'Contact');

    Server::actingAs(Fixtures::makeUser('edit pages entries', 'reorder pages entries'))
        ->tool(EntriesUpdate::class, ['id' => $contact, 'data' => [], 'parent' => $about])
        ->assertOk()
        ->assertSee('"url":"/about/contact"');

    expect(Fixtures::storedPagesTree())->toBe([['entry' => $about, 'children' => [['entry' => $contact]]]]);
});

it('reports a listener-cancelled move instead of claiming success', function () {
    Fixtures::site();
    Fixtures::pages();
    Fixtures::structure();

    $about = Fixtures::page('about', 'About');
    $contact = Fixtures::page('contact', 'Contact');

    storePagesTree([['entry' => $about], ['entry' => $contact]]);

    Event::listen(CollectionTreeSaving::class, fn () => false);

    Server::actingAs(Fixtures::makeUser('edit pages entries', 'reorder pages entries'))
        ->tool(EntriesUpdate::class, ['id' => $contact, 'data' => ['title' => 'Contact us'], 'parent' => $about])
        ->assertHasErrors(['the move was cancelled by a listener on this site — the entry was not moved, and any other change in this update was saved']);

    Stache::clear();

    expect(Fixtures::storedPagesTree())->toBe([['entry' => $about], ['entry' => $contact]])
        ->and(Entry::find($contact)->get('title'))->toBe('Contact us');
});

it('keeps a tree change another call saved while it was moving an entry', function () {
    Fixtures::site();
    Fixtures::pages();
    Fixtures::structure();

    $about = Fixtures::page('about', 'About');
    $contact = Fixtures::page('contact', 'Contact');
    $team = Fixtures::page('team', 'Team');

    storePagesTree([['entry' => $about], ['entry' => $contact], ['entry' => $team]]);

    // After this call read the tree, another one moves Contact under About.
    $moved = false;

    Event::listen(EntrySaving::class, function () use (&$moved, $about, $contact, $team) {
        if ($moved) {
            return;
        }

        $moved = true;

        Collection::findByHandle('pages')->structure()
            ->makeTree('en', [['entry' => $about, 'children' => [['entry' => $contact]]], ['entry' => $team]])
            ->save();
    });

    Server::actingAs(Fixtures::makeUser('edit pages entries', 'reorder pages entries'))
        ->tool(EntriesUpdate::class, ['id' => $team, 'data' => ['title' => 'The team'], 'parent' => $about])
        ->assertOk();

    expect(Fixtures::storedPagesTree())->toBe([
        ['entry' => $about, 'children' => [['entry' => $contact], ['entry' => $team]]],
    ]);
});
