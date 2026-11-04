<?php

use Danielgnh\StatamicMcp\Tests\BootsPassportWithMcpOff;
use Illuminate\Support\Facades\Route;

uses(BootsPassportWithMcpOff::class);

it('leaves Passport its own routes when booted with the kill switch off, because the addon does not own them', function () {
    expect(collect(Route::getRoutes()->getRoutes())->map->uri())
        ->toContain('oauth/authorize', 'oauth/token')
        ->not->toContain('oauth/register')
        ->not->toContain('.well-known/oauth-authorization-server')
        ->not->toContain('mcp/statamic');
});
