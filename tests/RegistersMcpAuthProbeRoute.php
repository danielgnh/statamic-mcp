<?php

namespace Danielgnh\StatamicMcp\Tests;

use Danielgnh\StatamicMcp\Middleware\AuthenticateMcpToken;
use Danielgnh\StatamicMcp\Middleware\EnsureMcpPermission;
use Statamic\Facades\User;

// The probe route must be registered pre-boot: Statamic's frontend catch-all
// ({segments?}, all verbs) is added in an app->booted() callback, and Laravel
// matches routes in registration order — a route added inside a test body
// (beforeEach) lands after the catch-all and 404s. Same trait-merge pattern
// as DisablesMcp / UsesOAuthMode.
trait RegistersMcpAuthProbeRoute
{
    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        // Same middleware stack the ServiceProvider mounts on the MCP route in
        // token mode. The body returns what Statamic thinks the current user
        // is — the visibility revision authorship and event listeners depend on.
        $app['router']->post('/mcp-auth-probe', function () {
            return response()->json(['email' => User::current()->email()]);
        })->middleware([AuthenticateMcpToken::class, EnsureMcpPermission::class]);
    }
}
