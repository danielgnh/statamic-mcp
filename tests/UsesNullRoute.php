<?php

namespace Danielgnh\StatamicMcp\Tests;

trait UsesNullRoute
{
    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('statamic.mcp.route', null);
    }
}
