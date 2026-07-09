<?php

namespace Danielgnh\StatamicMcp\Tests;

// Mirror of DisablesMcp: the auth mode is read once in bootAddon(), so it must
// be set pre-boot via getEnvironmentSetUp.
trait UsesOAuthMode
{
    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('statamic.mcp.auth', 'oauth');
    }
}
