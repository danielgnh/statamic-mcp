<?php

namespace Danielgnh\StatamicMcp\Tests;

// The kill switch is read once, in bootAddon() — flipping config inside a test
// body is too late. getEnvironmentSetUp runs before package providers boot, and
// a file-level uses(DisablesMcp::class) merges this trait into the test case
// (Pest v4 forbids overriding the folder-level TestCase binding with a class).
trait DisablesMcp
{
    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('statamic.mcp.enabled', false);
    }
}
