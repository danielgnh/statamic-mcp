<?php

use Danielgnh\StatamicMcp\Tests\Support\Fixtures;

beforeEach(function () {
    config(['statamic.editions.pro' => true]);
});

it('lets super admins pick a page, with connections first because most visits are for it', function () {
    $this->actingAs(Fixtures::makeSuper())
        ->get(cp_route('mcp.index'))
        ->assertOk()
        ->assertSeeInOrder([
            cp_route('mcp.connections.index'),
            cp_route('mcp.guidelines.edit'),
        ], false);
});

it('leaves the $page variable of the statamic layout alone, because the control panel cannot boot without it', function () {
    $this->actingAs(Fixtures::makeSuper())
        ->get(cp_route('mcp.index'))
        ->assertSee('NonInertiaPage', false);
});

it('sends users who can only open connections straight there, since they have nothing to pick', function () {
    $this->actingAs(Fixtures::makeUser('access cp'))
        ->get(cp_route('mcp.index'))
        ->assertRedirect(cp_route('mcp.connections.index'));
});

it('answers the sidebar inertia visit of those users with a location, so connections renders once', function () {
    $this->actingAs(Fixtures::makeUser('access cp'))
        ->get(cp_route('mcp.index'), ['X-Inertia' => 'true', 'X-Inertia-Version' => inertia()->getVersion()])
        ->assertConflict()
        ->assertHeader('X-Inertia-Location', cp_route('mcp.connections.index'));
});

it('keeps users without access mcp out', function () {
    $this->actingAs(Fixtures::makeBareUser('access cp'))
        ->getJson(cp_route('mcp.index'))
        ->assertForbidden();
});

it('sends guests to the control panel login', function () {
    $this->get(cp_route('mcp.index'))
        ->assertRedirect(cp_route('login'));
});
