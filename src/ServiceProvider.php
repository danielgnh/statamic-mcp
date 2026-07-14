<?php

namespace Danielgnh\StatamicMcp;

use Danielgnh\StatamicMcp\Console\Doctor;
use Danielgnh\StatamicMcp\Console\IssueToken;
use Danielgnh\StatamicMcp\Console\Keys;
use Danielgnh\StatamicMcp\Console\ListTokens;
use Danielgnh\StatamicMcp\Console\RevokeToken;
use Danielgnh\StatamicMcp\Console\Setup;
use Danielgnh\StatamicMcp\CP\McpTokensUtility;
use Danielgnh\StatamicMcp\Middleware\AuthenticateMcpToken;
use Danielgnh\StatamicMcp\Middleware\AuthenticateOAuth;
use Danielgnh\StatamicMcp\Middleware\EnsureMcpPermission;
use Danielgnh\StatamicMcp\OAuth\PassportBearerGuard;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Passport\Contracts\AuthorizationViewResponse;
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
        Setup::class,
        Keys::class,
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

        McpTokensUtility::register();

        try {
            $this->registerMcpRoutes();
        } catch (Throwable $e) {
            report($e); // misconfiguration must never brick the host site

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

            // Converts Passport's bigint user_id columns to string(36) so
            // Statamic's UUID ids fit — loaded only in OAuth mode so the addon
            // never touches Passport tables a host app uses for other things.
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        // Build the full middleware stack BEFORE Mcp::web() registers anything,
        // so a config-shape error throws while zero routes exist — a throw after
        // registration would leave an unauthenticated route behind (fail closed).
        $middleware = [
            ...Arr::wrap(config('statamic.mcp.middleware', [])),
            ...($oauth
                // Wrapper runs the oauth preflight then delegates to the api
                // guard — deliberately NOT 'auth:api' directly, which Laravel's
                // middleware priority would hoist above the preflight; must not
                // implement AuthenticatesRequests.
                ? [AuthenticateOAuth::class]
                : [AuthenticateMcpToken::class]),
            EnsureMcpPermission::class, // 'access mcp', checked after auth in both modes
        ];

        Mcp::web(config('statamic.mcp.route'), Server::class)->middleware($middleware);

        if ($oauth && class_exists(Passport::class)) {
            Mcp::oauthRoutes(); // hard-requires Passport — guarded so bootAddon never throws

            // Passport 12+ ships no default consent view and never binds
            // AuthorizationViewResponse — /oauth/authorize 500s with
            // "Target [...AuthorizationViewResponse] is not instantiable"
            // unless someone calls authorizationView(). Guarded on `! bound` so a host
            // app's own Passport::authorizationView() wins whichever boots first.
            if (! app()->bound(AuthorizationViewResponse::class)) {
                Passport::authorizationView('statamic-mcp::oauth.authorize');
            }
        }
    }

    /**
     * OAuth mode brings its own guard, defined here in config so the host app
     * never edits config/auth.php: the driver validates the bearer via
     * Passport's ResourceServer but resolves the user through the Statamic
     * repository — file users and Eloquent users alike. The definition is
     * addon-namespaced and only exists in OAuth mode, so a host app's own
     * guards are untouched.
     */
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
