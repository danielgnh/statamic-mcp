<?php

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tools\BlueprintsGet;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Nav;

it("returns the blueprint of a navigation, the fields a branch's data takes", function () {
    Fixtures::site();
    Fixtures::pages();
    Fixtures::nav();

    Server::actingAs(Fixtures::makeUser('view main nav'))
        ->tool(BlueprintsGet::class, ['type' => 'navigation', 'handle' => 'main'])
        ->assertOk()
        ->assertSee('"type":"navigation","handle":"main","blueprint":"main","available_blueprints":["main"]')
        ->assertSee('"fields":[{"handle":"icon","type":"text","required":false,"rules":["max:30","nullable"]},{"handle":"new_tab","type":"toggle","required":false,"rules":["nullable"]}]')
        ->assertSee('"example":{"icon":"Example text","new_tab":true}');
});

it("lists the sets of a navigation blueprint's replicator and returns one with its fields", function () {
    Fixtures::site();
    Fixtures::pages();
    Fixtures::nav();

    // a mega menu whose branches hold cards
    Blueprint::makeFromFields([
        'blocks' => [
            'type' => 'replicator',
            'display' => 'Cards',
            'instructions' => 'The cards shown under this menu item.',
            'sets' => [
                'blocks' => [
                    'sets' => [
                        'card' => [
                            'display' => 'Card',
                            'fields' => [
                                ['handle' => 'image', 'field' => ['type' => 'assets', 'container' => 'assets', 'max_files' => 1]],
                                ['handle' => 'eyebrow', 'field' => ['type' => 'text']],
                                ['handle' => 'title', 'field' => ['type' => 'text']],
                                ['handle' => 'link', 'field' => ['type' => 'link']],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ])->setHandle('main')->setNamespace('navigation')->save();

    Server::actingAs(Fixtures::makeUser('view main nav'))
        ->tool(BlueprintsGet::class, ['type' => 'navigation', 'handle' => 'main'])
        ->assertOk()
        ->assertSee('"fields":[{"handle":"blocks","type":"replicator","required":false,"rules":["array","nullable"],"instructions":"The cards shown under this menu item.","sets":[{"handle":"card","display":"Card","group":"Blocks"}]}]')
        ->assertSee('"example":{"blocks":null}')
        ->assertSee('"example_notes":{"blocks":"a list of sets, each an object with its type plus its field values (id and enabled are optional) — call blueprints_get with set: card, or another handle from sets, for a set\'s fields and an example"}');

    Server::actingAs(Fixtures::makeUser('view main nav'))
        ->tool(BlueprintsGet::class, ['type' => 'navigation', 'handle' => 'main', 'set' => 'card'])
        ->assertOk()
        ->assertSee('"type":"navigation","handle":"main","blueprint":"main","set":{"handle":"card","display":"Card","group":"Blocks","fields":['
            .'{"handle":"image","type":"assets","required":false,"rules":["array","max:1","nullable"],"container":"assets","max_files":1},'
            .'{"handle":"eyebrow","type":"text","required":false,"rules":["nullable"]},'
            .'{"handle":"title","type":"text","required":false,"rules":["nullable"]},'
            .'{"handle":"link","type":"link","required":false,"rules":["nullable"]}]}')
        ->assertSee('"example":{"type":"card","image":"REPLACE-WITH-REAL-ASSET-PATH","eyebrow":"Example text","title":"Example text","link":null}')
        ->assertSee('"link":"no example generated for fieldtype \'link\' — read a real value from existing content before writing this field"');
});

it('notes title and url as branch keys when a navigation blueprint defines them', function () {
    Fixtures::site();
    Fixtures::pages();
    Fixtures::nav();

    Blueprint::makeFromFields([
        'title' => ['type' => 'text', 'validate' => 'required'],
        'url' => ['type' => 'text'],
        'icon' => ['type' => 'text'],
    ])->setHandle('main')->setNamespace('navigation')->save();

    Server::actingAs(Fixtures::makeUser('view main nav'))
        ->tool(BlueprintsGet::class, ['type' => 'navigation', 'handle' => 'main'])
        ->assertOk()
        ->assertSee('"handle":"title","type":"text","required":true')
        ->assertSee('"example":{"icon":"Example text"}')
        ->assertSee('"example_notes":{"title":"pass title as a branch key of navigations_update, not inside data","url":"pass url as a branch key of navigations_update, not inside data"}');
});

it('returns no fields for a navigation without a blueprint', function () {
    Fixtures::site();
    Fixtures::pages();

    Nav::make('footer')->title('Footer')->collections(['pages'])->save();

    Server::actingAs(Fixtures::makeUser('view footer nav'))
        ->tool(BlueprintsGet::class, ['type' => 'navigation', 'handle' => 'footer'])
        ->assertOk()
        ->assertSee('"type":"navigation","handle":"footer","blueprint":"footer","available_blueprints":["footer"],"fields":[],"example":[]}');
});

it('leaves localizable out of a navigation blueprint, whose trees are per site', function () {
    Fixtures::multisite();
    Fixtures::pages();
    Fixtures::nav();

    Server::actingAs(Fixtures::makeUser('view main nav'))
        ->tool(BlueprintsGet::class, ['type' => 'navigation', 'handle' => 'main'])
        ->assertOk()
        ->assertSee('{"handle":"icon","type":"text","required":false,"rules":["max:30","nullable"]}')
        ->assertDontSee('localizable');
});

it("requires 'view {handle} nav', and treats an unexposed navigation as missing", function () {
    Fixtures::site();
    Fixtures::pages();
    Fixtures::nav('main');
    Fixtures::nav('footer');

    $user = Fixtures::makeUser('edit main nav');

    Server::actingAs($user)
        ->tool(BlueprintsGet::class, ['type' => 'navigation', 'handle' => 'main'])
        ->assertHasErrors(["requires 'view main nav' — grant it to a role of {$user->email()} in the Control Panel"]);

    config(['statamic.mcp.resources.navigations' => ['footer']]);

    Server::actingAs(Fixtures::makeSuper())
        ->tool(BlueprintsGet::class, ['type' => 'navigation', 'handle' => 'main'])
        ->assertHasErrors(["navigation 'main' not found — available: footer"]);
});
