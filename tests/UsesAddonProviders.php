<?php

namespace Danielgnh\StatamicMcp\Tests;

use Danielgnh\StatamicMcp\Tests\Support\Addon\FirstAddonProvider;
use Danielgnh\StatamicMcp\Tests\Support\Addon\SecondAddonProvider;

trait UsesAddonProviders
{
    protected function getPackageProviders($app)
    {
        return [
            FirstAddonProvider::class,
            ...parent::getPackageProviders($app),
            SecondAddonProvider::class,
        ];
    }
}
