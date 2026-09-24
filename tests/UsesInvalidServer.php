<?php

namespace Danielgnh\StatamicMcp\Tests;

// Mirror of DisablesMcp: simulates a host app pointing 'server' at a class
// that is not a laravel/mcp Server — the provider must fail closed at boot.
trait UsesInvalidServer
{
    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('statamic.mcp.server', \stdClass::class);
    }
}
