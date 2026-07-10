<?php

namespace Danielgnh\StatamicMcp\Tests;

// Mirror of DisablesMcp: simulates a host app publishing the config and setting
// 'middleware' to a bare string instead of an array — the route must still
// mount authenticated (Arr::wrap in the provider), never fail open.
trait UsesStringMiddlewareConfig
{
    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('statamic.mcp.middleware', 'throttle:60,1');
    }
}
