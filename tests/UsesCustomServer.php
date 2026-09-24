<?php

namespace Danielgnh\StatamicMcp\Tests;

use Danielgnh\StatamicMcp\Tests\Support\CustomServer;

// Mirror of DisablesMcp: the server class is read once in bootAddon(), so it
// must be set pre-boot via getEnvironmentSetUp.
trait UsesCustomServer
{
    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('statamic.mcp.server', CustomServer::class);
    }
}
