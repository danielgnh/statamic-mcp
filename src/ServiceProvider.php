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
use Error;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server as McpServer;
use Laravel\Mcp\Server\Middleware\ReorderJsonAccept;
use Laravel\Passport\Contracts\AuthorizationViewResponse;
use Laravel\Passport\Passport;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\ResourceServer;
use RuntimeException;
use Statamic\Facades\Permission;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    protected $config = false;

    protected $translations = false;

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

        $this->loadJsonTranslationsFrom(__DIR__.'/../lang');

        $this->registerPermission();

        if (! config('statamic.mcp.enabled')) {
            return;
        }

        McpNav::register();

        try {
            $this->registerMcpRoutes();
        } catch (InvalidArgumentException $e) {
            report(new RuntimeException('Statamic MCP failed to mount; run `php please mcp:doctor`', previous: $e));
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

        $route = $this->routePath();
        $server = $this->serverClass();
        $beforeHeaderChecks = [
            ReorderJsonAccept::class,
            EnsureMcpEnabled::forCurrentAuth(),
            ...$this->configuredMiddleware(),
        ];
        $afterHeaderChecks = [
            $oauth ? AuthenticateOAuth::class : AuthenticateMcpToken::class,
            EnsureMcpPermission::class,
        ];

        Route::group(['middleware' => $beforeHeaderChecks], fn () => Mcp::web($route, $server)->middleware($afterHeaderChecks));

        if ($oauth && class_exists(Passport::class)) {
            $this->manageDatabaseKeys();

            Route::middleware([EnsureMcpEnabled::class.':oauth', 'throttle:60,1'])->group(fn () => Mcp::oauthRoutes());

            if (! app()->bound(AuthorizationViewResponse::class)) {
                Passport::authorizationView(fn (array $parameters) => $this->consentView($parameters));
            }
        }
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    protected function consentView(array $parameters): Response
    {
        $user = $parameters['user'] ?? null;

        if ($user instanceof HasLocalePreference && ($locale = $user->preferredLocale()) !== null) {
            app()->setLocale($locale);
        }

        return response()->view('statamic-mcp::oauth.authorize', $parameters);
    }

    /** @return array<array-key, mixed> */
    protected function configuredMiddleware(): array
    {
        $middleware = Arr::wrap(config('statamic.mcp.middleware', []));

        foreach ($middleware as $entry) {
            if (! is_string($entry)) {
                throw new InvalidArgumentException(sprintf(
                    "statamic.mcp.middleware must list names, got '%s'.",
                    get_debug_type($entry),
                ));
            }
        }

        return $middleware;
    }

    protected function routePath(): string
    {
        $route = config('statamic.mcp.route');

        if (! is_string($route) || blank($route)) {
            throw new InvalidArgumentException(sprintf(
                "statamic.mcp.route must be a path, got '%s'.",
                is_string($route) ? $route : get_debug_type($route),
            ));
        }

        return $route;
    }

    /** @return class-string<McpServer> */
    protected function serverClass(): string
    {
        $server = config('statamic.mcp.server', Server::class);

        try {
            if (is_string($server) && is_subclass_of($server, McpServer::class)) {
                return $server;
            }
        } catch (Error $e) {
            throw new InvalidArgumentException("statamic.mcp.server names a class that cannot load: {$e->getMessage()}", $e->getCode(), $e);
        }

        throw new InvalidArgumentException(sprintf(
            "statamic.mcp.server must name a subclass of %s, got '%s'.",
            McpServer::class,
            is_string($server) ? $server : get_debug_type($server),
        ));
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
