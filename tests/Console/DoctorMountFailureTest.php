<?php

use Danielgnh\StatamicMcp\Tests\DisablesMcp;

uses(DisablesMcp::class);

it('fails when MCP is enabled but the route never mounted', function () {
    // DisablesMcp boots the app with zero MCP routes; flipping the config back
    // on afterwards reproduces the enabled-but-failed-to-mount state the
    // ServiceProvider logs about ('Statamic MCP failed to mount').
    config(['statamic.mcp.enabled' => true]);

    $this->artisan('statamic:mcp:doctor')
        ->expectsOutputToContain('[FAIL] MCP is enabled but its route is not mounted')
        ->assertExitCode(1);
});
