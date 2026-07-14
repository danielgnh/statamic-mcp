<?php

namespace Danielgnh\StatamicMcp\Tests;

use Danielgnh\StatamicMcp\Middleware\AuthenticateOAuth;
use Danielgnh\StatamicMcp\Middleware\EnsureMcpPermission;
use Danielgnh\StatamicMcp\OAuth\PassportBearerGuard;
use Laravel\Passport\PassportServiceProvider;
use Statamic\Facades\User;

/**
 * OAuth-mode twin of RegistersMcpAuthProbeRoute: boots the app in oauth mode
 * (so the ServiceProvider wires the addon guard exactly as in production),
 * registers Passport's provider (AddonTestCase skips package discovery), and
 * mounts a probe route behind the SAME middleware stack the MCP route gets.
 * Pre-boot registration, because Statamic's frontend catch-all swallows
 * routes registered any later.
 */
trait RegistersOAuthProbeRoute
{
    protected function getPackageProviders($app)
    {
        return array_merge(parent::getPackageProviders($app), [
            PassportServiceProvider::class,
        ]);
    }

    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('statamic.mcp.auth', 'oauth');

        $app['router']->post('/mcp-oauth-probe', fn () => response()->json([
            'email' => User::current()->email(),
            'guard' => PassportBearerGuard::GUARD,
        ]))->middleware([AuthenticateOAuth::class, EnsureMcpPermission::class]);
    }
}
