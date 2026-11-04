<?php

namespace Danielgnh\StatamicMcp;

use Danielgnh\StatamicMcp\Console\Doctor;
use Danielgnh\StatamicMcp\Console\Guidelines;
use Danielgnh\StatamicMcp\Console\IssueToken;
use Danielgnh\StatamicMcp\Console\Keys;
use Danielgnh\StatamicMcp\Console\ListTokens;
use Danielgnh\StatamicMcp\Console\RevokeToken;
use Danielgnh\StatamicMcp\Console\Setup;
use Danielgnh\StatamicMcp\CP\McpNav;
use Danielgnh\StatamicMcp\Middleware\AuthenticateMcpToken;
use Danielgnh\StatamicMcp\Middleware\AuthenticateOAuth;
use Danielgnh\StatamicMcp\Middleware\EnsureMcpEnabled;
use Danielgnh\StatamicMcp\Middleware\EnsureMcpPermission;
use Danielgnh\StatamicMcp\OAuth\PassportBearerGuard;
use Danielgnh\StatamicMcp\OAuth\PassportKeys;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server as McpServer;
use Laravel\Passport\Contracts\AuthorizationViewResponse;
use Laravel\Passport\Passport;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\ResourceServer;
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
        Setup::class,
        Keys::class,
        Guidelines::class,
    ];

    #[\Override]
    public function bootAddon(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/mcp.php', 'statamic.mcp');

        $this->publishes([
            __DIR__.'/../config/mcp.php' => config_path('statamic/mcp.php'),
        ], 'statamic-mcp-config');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/statamic-mcp'),
        ], 'statamic-mcp-views');

        Blade::anonymousComponentPath(__DIR__.'/../resources/views/components', 'statamic-mcp');

        $this->registerPermission();

        if (! config('statamic.mcp.enabled')) {
            return;
        }

        McpNav::register();

        try {
            $this->registerMcpRoutes();
        } catch (Throwable $e) {
            report($e);

            Log::warning('Statamic MCP failed to mount; run `php please mcp:doctor`', [
                'exception' => $e->getMessage(),
            ]);
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

        if ($oauth) {
            $this->registerOAuthGuard();

            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        $middleware = [
            EnsureMcpEnabled::forCurrentAuth(),
            ...Arr::wrap(config('statamic.mcp.middleware', [])),
            ...($oauth
                ? [AuthenticateOAuth::class]
                : [AuthenticateMcpToken::class]),
            EnsureMcpPermission::class,
        ];

        Mcp::web(config('statamic.mcp.route'), $this->serverClass())->middleware($middleware);

        if ($oauth && class_exists(Passport::class)) {
            $this->manageDatabaseKeys();

            Route::middleware([EnsureMcpEnabled::class.':oauth', 'throttle:60,1'])->group(fn () => Mcp::oauthRoutes());

            if (! app()->bound(AuthorizationViewResponse::class)) {
                Passport::authorizationView('statamic-mcp::oauth.authorize');
            }
        }
    }

    /** @return class-string<McpServer> */
    protected function serverClass(): string
    {
        $server = config('statamic.mcp.server', Server::class);

        if (! is_string($server) || ! is_subclass_of($server, McpServer::class)) {
            throw new InvalidArgumentException(sprintf(
                "statamic.mcp.server must name a subclass of %s, got '%s'.",
                McpServer::class,
                is_string($server) ? $server : get_debug_type($server),
            ));
        }

        return $server;
    }

    protected function manageDatabaseKeys(): void
    {
        $inject = fn () => $this->app->make(PassportKeys::class)->inject();

        $this->app->beforeResolving(AuthorizationServer::class, $inject);
        $this->app->beforeResolving(ResourceServer::class, $inject);
    }

    protected function registerOAuthGuard(): void
    {
        Auth::viaRequest(PassportBearerGuard::DRIVER, new PassportBearerGuard);

        config([
            'auth.guards.'.PassportBearerGuard::GUARD => [
                'driver' => PassportBearerGuard::DRIVER,
                'provider' => null,
            ],
        ]);
    }
}
