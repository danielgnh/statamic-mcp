<?php

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\Addon\AddonEntryTool;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Statamic\Facades\Entry;

beforeEach(function () {
    Fixtures::multisite();
    Fixtures::tags();
    Fixtures::blog();

    Server::registerAddonTools(AddonEntryTool::class);

    $this->origin = tap(Entry::make()->collection('blog')->slug('hello')->locale('en')->data(['title' => 'Hello']))->save();
    $this->localization = tap($this->origin->makeLocalization('de')->data(['title' => 'Hallo']))->save();
});

it('resolves an entry for an add-on tool that uses none of the internal traits', function () {
    Server::actingAs(Fixtures::makeUser('edit blog entries', 'access de site'))
        ->tool(AddonEntryTool::class, ['id' => $this->localization->id()])
        ->assertOk()
        ->assertSee('"title":"Hallo"');
});

it("enforces site access for an add-on tool's entry lookup", function () {
    $user = Fixtures::makeUser('edit blog entries');

    Server::actingAs($user)
        ->tool(AddonEntryTool::class, ['id' => $this->localization->id()])
        ->assertHasErrors(["requires 'access de site' — grant it to a role of {$user->email()} in the Control Panel"]);
});

it("enforces the resources allowlist for an add-on tool's entry lookup", function () {
    config(['statamic.mcp.resources.collections' => ['pages']]);

    Server::actingAs(Fixtures::makeSuper())
        ->tool(AddonEntryTool::class, ['id' => $this->origin->id()])
        ->assertHasErrors(["entry '{$this->origin->id()}' not found"]);
});

it("enforces the collection permission for an add-on tool's entry lookup", function () {
    $user = Fixtures::makeUser('view blog entries', 'access de site');

    Server::actingAs($user)
        ->tool(AddonEntryTool::class, ['id' => $this->localization->id()])
        ->assertHasErrors(["requires 'edit blog entries' — grant it to a role of {$user->email()} in the Control Panel"]);
});
