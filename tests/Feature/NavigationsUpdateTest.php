<?php

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tools\NavigationsGet;
use Danielgnh\StatamicMcp\Tools\NavigationsUpdate;
use Facades\Statamic\Structures\BranchIdGenerator;
use Illuminate\Support\Facades\Event;
use Laravel\Mcp\Request;
use Statamic\Events\NavTreeSaving;
use Statamic\Facades\Entry;
use Statamic\Facades\Nav;
use Statamic\Facades\Stache;

function storedNavTree(string $handle = 'main', string $site = 'en'): array
{
    // Rehydrate from disk: the Stache aliases in-request instances, so only a
    // cleared cache proves what was written.
    Stache::clear();

    return Nav::find($handle)->in($site)->tree();
}

it('replaces the tree, keeping the ids it is given and generating the missing ones', function () {
    Fixtures::site();
    Fixtures::pages();
    Fixtures::nav();

    $about = Fixtures::page('about', 'About');
    $team = Fixtures::page('team', 'Team');

    Nav::find('main')->in('en')->tree([['id' => 'b-about', 'entry' => $about]])->save();

    BranchIdGenerator::shouldReceive('generate')->andReturn('new-1', 'new-2', 'new-3');

    Server::actingAs(Fixtures::makeUser('edit main nav'))
        ->tool(NavigationsUpdate::class, ['handle' => 'main', 'tree' => [
            ['id' => 'b-about', 'entry' => $about, 'title' => 'About us', 'children' => [
                ['entry' => $team],
            ]],
            ['title' => 'Docs', 'url' => 'https://docs.example.com', 'data' => ['icon' => 'book']],
            ['title' => 'More'],
        ]])
        ->assertOk()
        ->assertSee(sprintf('"tree":[{"id":"b-about","entry":"%s","title":"About us","resolved":{"title":"About","url":"/about","status":"published"},"children":[{"id":"new-1","entry":"%s","resolved"', $about, $team))
        ->assertSee('updated — live')
        ->assertSee('"cp_edit_url":"http://localhost/cp/navigation/main"');

    expect(storedNavTree())->toBe([
        ['id' => 'b-about', 'entry' => $about, 'title' => 'About us', 'children' => [
            ['id' => 'new-1', 'entry' => $team],
        ]],
        ['id' => 'new-2', 'title' => 'Docs', 'url' => 'https://docs.example.com', 'data' => ['icon' => 'book']],
        ['id' => 'new-3', 'title' => 'More'],
    ]);
});

it('writes back what navigations_get returned as a no-op that saves nothing', function () {
    Fixtures::site();
    Fixtures::pages();
    Fixtures::nav();

    $about = Fixtures::page('about', 'About');

    Nav::find('main')->in('en')->tree([
        ['id' => 'b-about', 'entry' => $about, 'children' => [
            ['id' => 'b-docs', 'title' => 'Docs', 'url' => 'https://docs.example.com', 'data' => ['new_tab' => true]],
        ]],
    ])->save();

    $user = Fixtures::makeUser('view main nav', 'edit main nav');

    $this->actingAs($user);

    // The tree as the agent reads it, resolved blocks included.
    $tree = json_decode((string) (new NavigationsGet)->handle(new Request(['handle' => 'main']))->content(), true)['tree'];

    expect($tree[0])->toHaveKey('resolved');

    Event::fake([NavTreeSaving::class]);

    Server::actingAs($user)
        ->tool(NavigationsUpdate::class, ['handle' => 'main', 'tree' => $tree])
        ->assertOk()
        ->assertSee('no-op — the tree equals the current tree; nothing saved');

    Event::assertNotDispatched(NavTreeSaving::class);
});

it('stores branch data the way the navigation blueprint processes it', function () {
    Fixtures::site();
    Fixtures::pages();
    Fixtures::nav();

    Server::actingAs(Fixtures::makeUser('edit main nav'))
        ->tool(NavigationsUpdate::class, ['handle' => 'main', 'tree' => [
            ['id' => 'b-docs', 'title' => 'Docs', 'url' => 'https://docs.example.com', 'data' => ['new_tab' => 1, 'icon' => null]],
        ]])
        ->assertOk();

    // The toggle stores a boolean, and a cleared field is not stored at all.
    expect(storedNavTree())->toBe([
        ['id' => 'b-docs', 'title' => 'Docs', 'url' => 'https://docs.example.com', 'data' => ['new_tab' => true]],
    ]);
});

it('treats a null branch key as an absent one', function () {
    Fixtures::site();
    Fixtures::pages();
    Fixtures::nav();

    Server::actingAs(Fixtures::makeUser('edit main nav'))
        ->tool(NavigationsUpdate::class, ['handle' => 'main', 'tree' => [
            ['id' => 'b-docs', 'entry' => null, 'title' => 'Docs', 'url' => '/docs', 'data' => null, 'children' => null],
        ]])
        ->assertOk();

    expect(storedNavTree())->toBe([['id' => 'b-docs', 'title' => 'Docs', 'url' => '/docs']]);
});

it('clears the tree when sent an empty list', function () {
    Fixtures::site();
    Fixtures::pages();
    Fixtures::nav();

    Nav::find('main')->in('en')->tree([['id' => 'b-docs', 'title' => 'Docs']])->save();

    Server::actingAs(Fixtures::makeUser('edit main nav'))
        ->tool(NavigationsUpdate::class, ['handle' => 'main', 'tree' => []])
        ->assertOk()
        ->assertSee('"tree":[]');

    expect(storedNavTree())->toBe([]);
});

it('creates the first tree of a navigation that has none', function () {
    Fixtures::site();
    Fixtures::pages();

    Nav::make('footer')->title('Footer')->collections(['pages'])->save();

    Server::actingAs(Fixtures::makeUser('edit footer nav'))
        ->tool(NavigationsUpdate::class, ['handle' => 'footer', 'tree' => [['id' => 'b-imprint', 'title' => 'Imprint', 'url' => '/imprint']]])
        ->assertOk();

    expect(storedNavTree('footer'))->toBe([['id' => 'b-imprint', 'title' => 'Imprint', 'url' => '/imprint']]);
});

it('rejects malformed trees before anything is saved', function (array $tree, string $error) {
    Fixtures::site();
    Fixtures::pages();
    Fixtures::nav(maxDepth: 2);

    Nav::find('main')->in('en')->tree([['id' => 'b-home', 'title' => 'Home', 'url' => '/']])->save();

    Server::actingAs(Fixtures::makeUser('edit main nav'))
        ->tool(NavigationsUpdate::class, ['handle' => 'main', 'tree' => $tree])
        ->assertHasErrors([$error]);

    expect(storedNavTree())->toBe([['id' => 'b-home', 'title' => 'Home', 'url' => '/']]);
})->with([
    'a tree that is not a list' => [
        ['home' => ['title' => 'Home']],
        'tree must be a list of branches',
    ],
    'a branch that is not an object' => [
        ['Home'],
        'tree.0 must be a branch object: {id?, entry?, title?, url?, data?, children?}',
    ],
    'children that are not a list' => [
        [['title' => 'Home', 'children' => ['title' => 'Team']]],
        'tree.0.children must be a list of branches',
    ],
    'an unknown branch key' => [
        [['label' => 'Home', 'url' => '/']],
        'unknown key label in tree.0 — valid branch keys: children, data, entry, id, title, url',
    ],
    'a title that is not a string' => [
        [['title' => ['en' => 'Home']]],
        'tree.0.title must be a string',
    ],
    'a branch with nothing to show' => [
        [['title' => 'Home'], ['data' => ['icon' => 'x']]],
        'tree.1 has no entry, url, or title — link an entry by id, or give a link its url and title (a title alone makes a text item)',
    ],
    'a branch deeper than max_depth' => [
        [['title' => 'Home', 'children' => [['title' => 'Team', 'children' => [['title' => 'Jobs', 'url' => '/jobs']]]]]],
        "tree.0.children.0.children.0 is at depth 3 — navigation 'main' allows 2 levels (max_depth)",
    ],
    'data that is not an object' => [
        [['title' => 'Home', 'data' => ['book']]],
        'tree.0.data must be an object keyed by field handle',
    ],
    'a title inside data' => [
        [['url' => '/', 'data' => ['title' => 'Home']]],
        'pass title as a branch key, not inside tree.0.data',
    ],
    'an unknown data field' => [
        [['title' => 'Home', 'children' => [['title' => 'Team', 'data' => ['icn' => 'users']]]]],
        "unknown field icn in tree.0.children.0.data — valid handles: icon, new_tab — did you mean 'icon' instead of 'icn'?",
    ],
    'data the blueprint rejects' => [
        [['title' => 'Home'], ['title' => 'Team', 'data' => ['icon' => str_repeat('x', 31)]]],
        'tree.1: validation failed: {"icon":["The Icon field must not be greater than 30 characters."]}',
    ],
    'a duplicate branch id' => [
        [['id' => 'b-home', 'title' => 'Home'], ['id' => 'b-team', 'title' => 'Team', 'children' => [['id' => 'b-home', 'title' => 'Home again']]]],
        "branch id 'b-home' is used by tree.0 and tree.1.children.0 — every branch needs its own id; omit id on new branches",
    ],
]);

it('rejects data on a navigation whose blueprint has no fields', function () {
    Fixtures::site();
    Fixtures::pages();

    tap(Nav::make('footer')->title('Footer')->collections(['pages']))->save()->makeTree('en')->save();

    Server::actingAs(Fixtures::makeUser('edit footer nav'))
        ->tool(NavigationsUpdate::class, ['handle' => 'footer', 'tree' => [['title' => 'Imprint', 'data' => ['icon' => 'x']]]])
        ->assertHasErrors(["navigation 'footer' defines no branch fields — omit tree.0.data"]);
});

it('rejects an entry branch whose entry does not exist', function () {
    Fixtures::site();
    Fixtures::pages();
    Fixtures::nav();

    Server::actingAs(Fixtures::makeUser('edit main nav'))
        ->tool(NavigationsUpdate::class, ['handle' => 'main', 'tree' => [['entry' => 'no-such-entry']]])
        ->assertHasErrors(["entry 'no-such-entry' in tree.0 not found — drop the branch, or link an existing entry (linkable collections: pages)"]);

    expect(storedNavTree())->toBe([]);
});

it('rejects an entry of a collection the navigation does not link', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();
    Fixtures::pages();
    Fixtures::nav();

    $post = tap(Entry::make()->collection('blog')->slug('hello')->data(['title' => 'Hello']))->save()->id();

    Server::actingAs(Fixtures::makeUser('edit main nav'))
        ->tool(NavigationsUpdate::class, ['handle' => 'main', 'tree' => [['title' => 'Home', 'url' => '/'], ['entry' => $post]]])
        ->assertHasErrors(["entry '{$post}' in tree.1 is in collection 'blog', which navigation 'main' does not link — linkable collections: pages"]);

    expect(storedNavTree())->toBe([]);
});

it('rejects an entry of another site', function () {
    Fixtures::multisite();
    Fixtures::pages();
    Fixtures::nav();

    $about = Fixtures::page('about', 'About');

    Server::actingAs(Fixtures::makeUser('edit main nav', 'access de site'))
        ->tool(NavigationsUpdate::class, ['handle' => 'main', 'site' => 'de', 'tree' => [['entry' => $about]]])
        ->assertHasErrors(["entry '{$about}' in tree.0 belongs to site 'en', not 'de' — link its 'de' localization, which has its own id"]);
});

it('links an entry of another site when the navigation selects across sites', function () {
    Fixtures::multisite();
    Fixtures::pages();
    Fixtures::nav();

    Nav::find('main')->canSelectAcrossSites(true)->save();

    $about = Fixtures::page('about', 'About');

    Server::actingAs(Fixtures::makeUser('edit main nav', 'access de site'))
        ->tool(NavigationsUpdate::class, ['handle' => 'main', 'site' => 'de', 'tree' => [['id' => 'b-about', 'entry' => $about]]])
        ->assertOk();

    expect(storedNavTree(site: 'de'))->toBe([['id' => 'b-about', 'entry' => $about]]);
});

it('keeps children off the root page of a navigation that expects one', function () {
    Fixtures::site();
    Fixtures::pages();
    Fixtures::nav(root: true);

    $home = Fixtures::page('home', 'Home');

    Server::actingAs(Fixtures::makeUser('edit main nav'))
        ->tool(NavigationsUpdate::class, ['handle' => 'main', 'tree' => [
            ['entry' => $home, 'children' => [['title' => 'Team', 'url' => '/team']]],
        ]])
        ->assertHasErrors(["root page cannot have children — navigation 'main' expects a root page, its first branch: move the children to the top level"]);

    Server::actingAs(Fixtures::makeUser('edit main nav'))
        ->tool(NavigationsUpdate::class, ['handle' => 'main', 'tree' => [
            ['id' => 'b-home', 'entry' => $home],
            ['id' => 'b-team', 'title' => 'Team', 'url' => '/team'],
        ]])
        ->assertOk();
});

it('writes the tree of the requested site only', function () {
    Fixtures::multisite();
    Fixtures::pages();
    Fixtures::nav();

    $ueber = Fixtures::page('ueber-uns', 'Über uns', site: 'de');

    Server::actingAs(Fixtures::makeUser('edit main nav', 'access de site'))
        ->tool(NavigationsUpdate::class, ['handle' => 'main', 'site' => 'de', 'tree' => [['id' => 'b-ueber', 'entry' => $ueber]]])
        ->assertOk()
        ->assertSee('"site":"de"')
        ->assertSee('"cp_edit_url":"http://localhost/cp/navigation/main?site=de"');

    expect(storedNavTree(site: 'de'))->toBe([['id' => 'b-ueber', 'entry' => $ueber]])
        ->and(storedNavTree(site: 'en'))->toBe([]);
});

it("requires 'access {site} site' on multisite, the default site included", function () {
    Fixtures::multisite();
    Fixtures::pages();
    Fixtures::nav();

    $user = Fixtures::makeUser('edit main nav');

    Server::actingAs($user)
        ->tool(NavigationsUpdate::class, ['handle' => 'main', 'site' => 'de', 'tree' => []])
        ->assertHasErrors(["requires 'access de site' — grant it to a role of {$user->email()} in the Control Panel"]);

    // CP parity: NavTreePolicy gates the default site like any other.
    Server::actingAs($user)
        ->tool(NavigationsUpdate::class, ['handle' => 'main', 'tree' => [['id' => 'b-docs', 'title' => 'Docs', 'url' => '/docs']]])
        ->assertHasErrors(["requires 'access en site' — grant it to a role of {$user->email()} in the Control Panel"]);

    expect(storedNavTree())->toBe([]);

    Server::actingAs(Fixtures::makeUser('edit main nav', 'access en site'))
        ->tool(NavigationsUpdate::class, ['handle' => 'main', 'tree' => [['id' => 'b-docs', 'title' => 'Docs', 'url' => '/docs']]])
        ->assertOk();

    expect(storedNavTree())->toBe([['id' => 'b-docs', 'title' => 'Docs', 'url' => '/docs']]);
});

it('denies updating without the edit permission, naming it', function () {
    Fixtures::site();
    Fixtures::pages();
    Fixtures::nav();

    $user = Fixtures::makeUser('view main nav');

    Server::actingAs($user)
        ->tool(NavigationsUpdate::class, ['handle' => 'main', 'tree' => [['title' => 'Docs', 'url' => '/docs']]])
        ->assertHasErrors(["requires 'edit main nav' — grant it to a role of {$user->email()} in the Control Panel"]);

    expect(storedNavTree())->toBe([]);
});

it('treats an unexposed navigation as missing', function () {
    Fixtures::site();
    Fixtures::pages();
    Fixtures::nav();

    config(['statamic.mcp.resources.navigations' => false]);

    Server::actingAs(Fixtures::makeSuper())
        ->tool(NavigationsUpdate::class, ['handle' => 'main', 'tree' => []])
        ->assertHasErrors(["navigation 'main' not found — available: (none exposed)"]);
});

it('is hidden when the server is read-only', function () {
    Fixtures::site();
    Fixtures::pages();
    Fixtures::nav();

    config(['statamic.mcp.read_only' => true]);

    // Either the registration gate (shouldRegister) or the in-handler
    // re-check rejects the call — both are errors, which is all that matters.
    Server::actingAs(Fixtures::makeSuper())
        ->tool(NavigationsUpdate::class, ['handle' => 'main', 'tree' => [['title' => 'Docs', 'url' => '/docs']]])
        ->assertHasErrors();

    expect(storedNavTree())->toBe([]);
});

it('reports a listener-cancelled save instead of claiming success', function () {
    Fixtures::site();
    Fixtures::pages();
    Fixtures::nav();

    // Approval-workflow addons cancel saves by returning false from
    // NavTreeSaving; NavTree::save() then returns false.
    Event::listen(NavTreeSaving::class, fn () => false);

    Server::actingAs(Fixtures::makeUser('edit main nav'))
        ->tool(NavigationsUpdate::class, ['handle' => 'main', 'tree' => [['title' => 'Docs', 'url' => '/docs']]])
        ->assertHasErrors(['the save was cancelled by a listener — the navigation was not updated']);

    expect(storedNavTree())->toBe([]);
});
