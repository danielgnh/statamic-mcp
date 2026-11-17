<?php

use Danielgnh\StatamicMcp\ServiceProvider;

it("merges its config defaults at register time, so another add-on's AddonTestCase gets them without booting this addon", function () {
    config(['statamic.mcp' => []]);

    (new ServiceProvider(app()))->register();

    expect(config('statamic.mcp.resources.collections'))->toBeTrue()
        ->and(config('statamic.mcp.read_only'))->toBeFalse();
});
