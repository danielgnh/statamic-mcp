<?php

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tools\NavigationsGet;
use Illuminate\Support\Facades\Event;
use Statamic\Events\NavTreeSaving;
use Statamic\Facades\Nav;
use Statamic\Facades\Stache;

it('returns the stored tree with read-only resolved info on entry branches', function () {
    Fixtures::site();
    Fixtures::pages();
    Fixtures::nav();

    $about = Fixtures::page('about', 'About');
    $team = Fixtures::page('team', 'Team', published: false);

    Nav::find('main')->in('en')->tree([
        ['id' => 'b-about', 'entry' => $about, 'title' => 'About us', 'children' => [
            ['id' => 'b-team', 'entry' => $team],
        ]],
        ['id' => 'b-docs', 'title' => 'Docs', 'url' => 'https://docs.example.com', 'data' => ['new_tab' => true]],
    ])->save();

    Server::actingAs(Fixtures::makeUser('view main nav'))
        ->tool(NavigationsGet::class, ['handle' => 'main'])
        ->assertOk()
        ->assertSee('"handle":"main","title":"Main","site":"en","max_depth":null,"expects_root":false,"collections":["pages"]')
        ->assertSee('"fields":[{"handle":"icon","type":"text","required":false},{"handle":"new_tab","type":"toggle","required":false}]')
        ->assertSee(sprintf(
            '"tree":[{"id":"b-about","entry":"%s","title":"About us","resolved":{"title":"About","url":"/about","status":"published"},"children":[{"id":"b-team","entry":"%s","resolved":{"title":"Team","url":"/team","status":"draft"}}]},{"id":"b-docs","title":"Docs","url":"https://docs.example.com","data":{"new_tab":true}}]',
            $about,
            $team,
        ))
        ->assertSee('"cp_edit_url":"http://localhost/cp/navigation/main"');
});

it('reports the limits a write must respect', function () {
    Fixtures::site();
    Fixtures::pages();
    Fixtures::nav(maxDepth: 2, root: true);

    Server::actingAs(Fixtures::makeUser('view main nav'))
        ->tool(NavigationsGet::class, ['handle' => 'main'])
        ->assertOk()
        ->assertSee('"max_depth":2,"expects_root":true');
});

it('marks an entry branch whose entry no longer exists as missing', function () {
    Fixtures::site();
    Fixtures::pages();
    Fixtures::nav();

    Nav::find('main')->in('en')->tree([['id' => 'b-gone', 'entry' => 'deleted-entry']])->save();

    Server::actingAs(Fixtures::makeUser('view main nav'))
        ->tool(NavigationsGet::class, ['handle' => 'main'])
        ->assertOk()
        ->assertSee('"tree":[{"id":"b-gone","entry":"deleted-entry","resolved":{"status":"missing"}}]');
});

it('never saves the tree, not even to give branches their missing ids', function () {
    Fixtures::site();
    Fixtures::pages();
    Fixtures::nav();

    $about = Fixtures::page('about', 'About');

    // Hand-written YAML trees often lack ids. The CP's tree index calls
    // NavTree::ensureBranchIds(), which saves; a read must not.
    Nav::find('main')->in('en')->tree([['entry' => $about]])->save();

    Event::fake([NavTreeSaving::class]);

    Server::actingAs(Fixtures::makeUser('view main nav'))
        ->tool(NavigationsGet::class, ['handle' => 'main'])
        ->assertOk()
        ->assertSee(sprintf('"tree":[{"entry":"%s","resolved"', $about));

    Event::assertNotDispatched(NavTreeSaving::class);

    Stache::clear();

    expect(Nav::find('main')->in('en')->tree())->toBe([['entry' => $about]]);
});

it('returns an empty default-site tree for a navigation without any tree, without creating one', function () {
    Fixtures::site();
    Fixtures::pages();

    Nav::make('footer')->title('Footer')->collections(['pages'])->save();

    Server::actingAs(Fixtures::makeUser('view footer nav'))
        ->tool(NavigationsGet::class, ['handle' => 'footer'])
        ->assertOk()
        ->assertSee('"site":"en"')
        ->assertSee('"fields":[],"tree":[]');

    expect(Nav::find('footer')->sites()->all())->toBe([]);
});

it('reads the tree of the requested site', function () {
    Fixtures::multisite();
    Fixtures::pages();
    Fixtures::nav();

    $ueber = Fixtures::page('ueber-uns', 'Über uns', site: 'de');

    Nav::find('main')->in('de')->tree([['id' => 'b-ueber', 'entry' => $ueber]])->save();

    Server::actingAs(Fixtures::makeUser('view main nav', 'access de site'))
        ->tool(NavigationsGet::class, ['handle' => 'main', 'site' => 'de'])
        ->assertOk()
        ->assertSee('"site":"de"')
        ->assertSee(sprintf('"tree":[{"id":"b-ueber","entry":"%s","resolved":{"title":"Über uns","url":"/de/ueber-uns","status":"published"}}]', $ueber))
        ->assertSee('"cp_edit_url":"http://localhost/cp/navigation/main?site=de"');
});

it("requires 'access {site} site' on multisite, the default site included", function () {
    Fixtures::multisite();
    Fixtures::pages();
    Fixtures::nav();

    $user = Fixtures::makeUser('view main nav');

    Server::actingAs($user)
        ->tool(NavigationsGet::class, ['handle' => 'main', 'site' => 'de'])
        ->assertHasErrors(["requires 'access de site' — grant it to a role of {$user->email()} in the Control Panel"]);

    // CP parity: NavTreePolicy gates the default site like any other.
    Server::actingAs($user)
        ->tool(NavigationsGet::class, ['handle' => 'main'])
        ->assertHasErrors(["requires 'access en site' — grant it to a role of {$user->email()} in the Control Panel"]);

    Server::actingAs(Fixtures::makeUser('view main nav', 'access en site'))
        ->tool(NavigationsGet::class, ['handle' => 'main'])
        ->assertOk()
        ->assertSee('"site":"en"');
});

it('rejects a site the navigation has no tree in', function () {
    Fixtures::multisite();
    Fixtures::pages();
    Fixtures::nav();

    Nav::find('main')->in('de')->delete();

    Server::actingAs(Fixtures::makeUser('view main nav', 'access de site'))
        ->tool(NavigationsGet::class, ['handle' => 'main', 'site' => 'de'])
        ->assertHasErrors(["site 'de' is not available for this resource — available: en"]);
});

it('denies reading without the view permission, naming it', function () {
    Fixtures::site();
    Fixtures::pages();
    Fixtures::nav();

    $user = Fixtures::makeUser('edit footer nav');

    Server::actingAs($user)
        ->tool(NavigationsGet::class, ['handle' => 'main'])
        ->assertHasErrors(["requires 'view main nav' — grant it to a role of {$user->email()} in the Control Panel"]);
});

it('treats an unexposed navigation as missing', function () {
    Fixtures::site();
    Fixtures::pages();
    Fixtures::nav('main');
    Fixtures::nav('footer');

    config(['statamic.mcp.resources.navigations' => ['footer']]);

    Server::actingAs(Fixtures::makeSuper())
        ->tool(NavigationsGet::class, ['handle' => 'main'])
        ->assertHasErrors(["navigation 'main' not found — available: footer"]);
});
