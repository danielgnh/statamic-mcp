<?php

use Danielgnh\StatamicMcp\Tests\UsesNullRoute;
use Illuminate\Support\Facades\Route;

uses(UsesNullRoute::class);

it('leaves no route group open when the route config is not a string, so later routes keep their own middleware', function () {
    expect(app('router')->hasGroupStack())->toBeFalse();

    $throttled = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => in_array('throttle:60,1', $route->middleware(), true));

    expect($throttled)->toBeEmpty();
});
