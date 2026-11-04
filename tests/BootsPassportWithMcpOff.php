<?php

namespace Danielgnh\StatamicMcp\Tests;

use Laravel\Passport\PassportServiceProvider;

trait BootsPassportWithMcpOff
{
    protected function getPackageProviders($app)
    {
        return array_merge(parent::getPackageProviders($app), [
            PassportServiceProvider::class,
        ]);
    }

    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('statamic.mcp.auth', 'oauth');
        $app['config']->set('statamic.mcp.enabled', false);
    }
}
