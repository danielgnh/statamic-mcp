<?php

namespace Danielgnh\StatamicMcp\Tests;

trait UsesSerializingStacheStore
{
    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('cache.stores.serializing', ['driver' => 'array', 'serialize' => true]);
        $app['config']->set('statamic.stache.cache_store', 'serializing');
    }
}
