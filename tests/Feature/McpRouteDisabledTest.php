<?php

use Danielgnh\StatamicMcp\Tests\DisablesMcp;
use Illuminate\Support\Facades\Route;

uses(DisablesMcp::class);

it('does not register the mcp route when disabled', function () {
    $route = collect(Route::getRoutes()->getRoutes())
        ->first(fn ($route) => $route->uri() === 'mcp/statamic');

    expect($route)->toBeNull();
});
