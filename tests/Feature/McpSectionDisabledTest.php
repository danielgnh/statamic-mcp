<?php

use Danielgnh\StatamicMcp\Tests\DisablesMcp;
use Illuminate\Support\Facades\Route;

uses(DisablesMcp::class);

it('registers no tools → mcp pages when disabled', function () {
    expect(Route::has('statamic.cp.mcp.index'))->toBeFalse()
        ->and(Route::has('statamic.cp.mcp.guidelines.edit'))->toBeFalse()
        ->and(Route::has('statamic.cp.mcp.connections.index'))->toBeFalse();
});
