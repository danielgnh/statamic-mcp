<?php

use Danielgnh\StatamicMcp\CP\McpNav;
use Danielgnh\StatamicMcp\Tests\CachesRoutes;
use Danielgnh\StatamicMcp\Tests\DisablesMcp;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Statamic\CP\Navigation\Nav as NavInstance;
use Statamic\Facades\CP\Nav;

uses(DisablesMcp::class, CachesRoutes::class);

it('keeps the control panel working when the route cache was built without the mcp routes', function () {
    config(['statamic.editions.pro' => true, 'cache.default' => 'array']);

    $this->cacheRoutes();

    config(['statamic.mcp.enabled' => true]);

    Nav::swap(new NavInstance);
    McpNav::register();

    $this->actingAs(Fixtures::makeBareUser('access cp'));

    expect(json_encode(Nav::build()))->not->toContain('mcp');
});
