<?php

namespace Danielgnh\StatamicMcp\Tests;

use Danielgnh\StatamicMcp\Tests\Support\Addon\SiteServer;

trait UsesSiteServer
{
    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('statamic.mcp.server', SiteServer::class);
    }
}
