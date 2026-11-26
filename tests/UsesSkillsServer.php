<?php

namespace Danielgnh\StatamicMcp\Tests;

use Danielgnh\StatamicMcp\Tests\Support\Skills\SkillsServer;

trait UsesSkillsServer
{
    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('statamic.mcp.server', SkillsServer::class);
    }
}
