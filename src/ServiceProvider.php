<?php

namespace Danielgnh\StatamicMcp;

use Danielgnh\StatamicMcp\Console\Doctor;
use Danielgnh\StatamicMcp\Console\IssueToken;
use Danielgnh\StatamicMcp\Console\ListTokens;
use Danielgnh\StatamicMcp\Console\RevokeToken;
use Danielgnh\StatamicMcp\Middleware\AuthenticateMcpToken;
use Danielgnh\StatamicMcp\Middleware\EnsureMcpPermission;
use Danielgnh\StatamicMcp\Middleware\EnsureOAuthConfigured;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Passport\Passport;
use Statamic\Facades\Permission;
use Statamic\Providers\AddonServiceProvider;
use Throwable;

class ServiceProvider extends AddonServiceProvider
{
    protected $config = false;

    protected $commands = [
        IssueToken::class,
        ListTokens::class,
        RevokeToken::class,
        Doctor::class,
    ];

    public function bootAddon()
    {
        $this->mergeConfigFrom(__DIR__.'/../config/mcp.php', 'statamic.mcp');

        $this->publishes([
            __DIR__.'/../config/mcp.php' => config_path('statamic/mcp.php'),
        ], 'statamic-mcp-config');

        $this->registerPermission();

        if (! config('statamic.mcp.enabled')) {
            return;
        }

        try {
            $this->registerMcpRoutes();
        } catch (Throwable $e) {
            report($e); // misconfiguration must never brick the host site (spec §5)
        }
    }

    protected function registerPermission(): void
    {
        Permission::extend(function () {
            Permission::group('mcp', 'MCP', function () {
                Permission::register('access mcp')->label('Access MCP');
            });
        });
    }

    protected function registerMcpRoutes(): void
    {
        $oauth = config('statamic.mcp.auth') === 'oauth';

        Mcp::web(config('statamic.mcp.route'), Server::class)->middleware([
            ...config('statamic.mcp.middleware', []),
            ...($oauth
                // Preflight answers 503-with-remedy BEFORE auth:api can throw on a missing guard.
                ? [EnsureOAuthConfigured::class, 'auth:api']
                : [AuthenticateMcpToken::class]),
            EnsureMcpPermission::class, // 'access mcp', checked after auth in both modes (spec §5)
        ]);

        if ($oauth && class_exists(Passport::class)) {
            Mcp::oauthRoutes(); // hard-requires Passport — guarded so bootAddon never throws
        }
    }
}
