<?php

namespace Danielgnh\StatamicMcp\Tests;

use Danielgnh\StatamicMcp\ServiceProvider;
use Statamic\Testing\AddonTestCase;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

// Do not run the suite with pest --parallel: the dev-null sandbox directory is shared and torn down wholesale per test.
abstract class TestCase extends AddonTestCase
{
    use PreventsSavingStacheItemsToDisk;

    protected string $addonServiceProvider = ServiceProvider::class;

    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        // Blueprints and roles/groups are not Stache stores, so PreventsSavingStacheItemsToDisk
        // does not redirect them. Point them into the per-test dev-null sandbox too, otherwise
        // fixture saves leak into the shared testbench skeleton in vendor/.
        $fixtures = __DIR__.'/__fixtures__/dev-null';
        $app['config']->set('statamic.system.blueprints_path', $fixtures.'/blueprints');
        $app['config']->set('statamic.users.repositories.file.paths.roles', $fixtures.'/users/roles.yaml');
        $app['config']->set('statamic.users.repositories.file.paths.groups', $fixtures.'/users/groups.yaml');
    }
}
