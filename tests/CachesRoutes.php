<?php

namespace Danielgnh\StatamicMcp\Tests;

use Illuminate\Routing\RouteCollection;

trait CachesRoutes
{
    protected function cacheRoutes(): void
    {
        $router = app('router');

        foreach ($router->getRoutes() as $route) {
            $route->prepareForSerialization();
        }

        $compiled = $router->getRoutes()->compile();

        $router->setRoutes(new RouteCollection);
        $router->setCompiledRoutes($compiled);
    }
}
