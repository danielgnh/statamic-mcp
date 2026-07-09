<?php

use Danielgnh\StatamicMcp\ServiceProvider;
use Statamic\Facades\Addon;

it('boots the addon service provider in testbench', function () {
    expect($this->app->providerIsLoaded(ServiceProvider::class))->toBeTrue();
});

it('registers the addon with statamic via the faked manifest', function () {
    expect(Addon::get('danielgnh/statamic-mcp'))->not->toBeNull();
});
