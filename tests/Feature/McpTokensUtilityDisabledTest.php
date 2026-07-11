<?php

use Danielgnh\StatamicMcp\Tests\DisablesMcp;
use Illuminate\Support\Facades\Route;

uses(DisablesMcp::class);

it('does not register the mcp tokens utility when disabled', function () {
    expect(Route::has('statamic.cp.utilities.mcp-tokens'))->toBeFalse();
});
