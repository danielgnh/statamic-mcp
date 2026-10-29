<?php

namespace Danielgnh\StatamicMcp\Tests;

trait UsesBearerKeyedThrottle
{
    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('statamic.mcp.auth', 'oauth');
        $app['config']->set('statamic.mcp.middleware', ['throttle:mcp']);
    }
}
