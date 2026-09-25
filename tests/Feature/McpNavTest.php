<?php

use Danielgnh\StatamicMcp\CP\McpNav;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Statamic\CP\Navigation\Nav as NavInstance;
use Statamic\CP\Navigation\NavItem;
use Statamic\Facades\CP\Nav;

beforeEach(function () {
    config(['statamic.editions.pro' => true]);
});

/**
 * AddonTestCase mocks Nav::build(), so this swaps a real Nav back in and
 * registers the item on it before building.
 *
 * @return list<string>|null the children of Tools → MCP, or null without the item
 */
function mcpNavChildren(): ?array
{
    Nav::swap(new NavInstance);
    McpNav::register();

    $item = Nav::build()
        ->flatMap(fn (array $section) => $section['items'])
        ->first(fn (NavItem $item) => $item->display() === 'MCP');

    return $item?->children()->map->display()->values()->all();
}

it('lists connections under tools → mcp for users with access mcp', function () {
    $this->actingAs(Fixtures::makeUser('access cp'));

    expect(mcpNavChildren())->toBe(['Connections']);
});

it('leaves the item out for users without access mcp', function () {
    $this->actingAs(Fixtures::makeBareUser('access cp'));

    expect(mcpNavChildren())->toBeNull();
});
