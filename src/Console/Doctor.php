<?php

namespace Danielgnh\StatamicMcp\Console;

use Danielgnh\StatamicMcp\Middleware\EnsureMcpEnabled;
use Danielgnh\StatamicMcp\OAuth\KeyStore;
use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Support\OAuthPrerequisites;
use Danielgnh\StatamicMcp\Tokens\TokenRepository;
use Error;
use ErrorException;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Routing\CompiledRouteCollection;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Laravel\Mcp\Server as McpServer;
use PDOException;
use Statamic\Console\RunsInPlease;
use Statamic\Facades\User;
use TypeError;

class Doctor extends Command
{
    use RunsInPlease;

    protected $signature = 'statamic:mcp:doctor';

    protected $description = 'Check the MCP server configuration and print remedies for every problem found.';

    protected bool $failed = false;

    protected OAuthPrerequisites $prereqs;

    public function handle(TokenRepository $tokens, OAuthPrerequisites $prereqs): int
    {
        $this->prereqs = $prereqs;

        $mode = config('statamic.mcp.auth', 'token');
        $route = config('statamic.mcp.route', 'mcp/statamic');

        $this->line('Statamic MCP doctor');
        $this->line('');
        $this->line('  Endpoint:  '.(is_string($route) ? url($route) : 'none'));
        $this->line('  Auth mode: '.$mode);
        $this->line('');

        $this->checkEnabled();
        $this->checkRoute();
        $this->checkServer();
        $this->checkMiddleware();
        $this->checkAppUrl();
        $this->checkAuthMigrations();

        if ($mode === 'oauth') {
            $this->checkOAuth();
        } else {
            $this->checkTokens($tokens);
        }

        $this->line('');

        if ($this->failed) {
            $this->error('Problems found. Fix the [FAIL] items above.');

            return self::FAILURE;
        }

        $this->info('No blocking problems found.');

        return self::SUCCESS;
    }

    protected function checkEnabled(): void
    {
        if (! config('statamic.mcp.enabled')) {
            $this->warn("[WARN] MCP is disabled ('enabled' => false) — the endpoint, OAuth discovery and client registration, and the Tools → MCP pages are off. If Passport is installed, its own /oauth routes stay. Set STATAMIC_MCP_ENABLED=true to serve requests.");

            return;
        }

        $this->info('[ OK ] MCP is enabled.');

        $route = $this->mountedRoute();
        $routesCached = Route::getRoutes() instanceof CompiledRouteCollection;

        if ($route && in_array(EnsureMcpEnabled::forCurrentAuth(), $route->middleware(), true)) {
            $this->info('[ OK ] MCP route is mounted.');
        } elseif ($route) {
            $this->problem('The cached MCP route was built for another auth mode or an older version of the addon. Rebuild the route cache: php artisan optimize.');
        } elseif ($routesCached) {
            $this->problem("MCP is enabled but the route cache holds no MCP route — it was built while MCP was off, or while the route failed to mount. Rebuild it: php artisan optimize. If the route is still missing, check the log for 'Statamic MCP failed to mount'.");
        } else {
            $this->problem("MCP is enabled but its route is not mounted — boot failed, so requests to the endpoint reach Statamic's frontend route and get a 404, or a 419 for a POST. Check the log for 'Statamic MCP failed to mount' and fix the reported exception.");
        }
    }

    protected function mountedRoute(): ?RoutingRoute
    {
        $path = config('statamic.mcp.route', 'mcp/statamic');

        if (! is_string($path)) {
            return null;
        }

        $uri = trim($path, '/');

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if ($route->uri() === $uri && in_array('POST', $route->methods(), true)) {
                return $route;
            }
        }

        return null;
    }

    protected function checkRoute(): void
    {
        $route = config('statamic.mcp.route', 'mcp/statamic');

        if (is_string($route) && filled($route)) {
            return;
        }

        $given = is_string($route) ? $route : get_debug_type($route);

        $this->problem("Configured route '{$given}' is not a path — the MCP route cannot mount. Set 'route' in config/statamic/mcp.php, for example to 'mcp/statamic'.");
    }

    protected function checkServer(): void
    {
        $server = config('statamic.mcp.server', Server::class);

        try {
            if (is_string($server) && is_subclass_of($server, McpServer::class)) {
                return;
            }
        } catch (Error $e) {
            $this->problem("Configured server '{$server}' cannot load ({$e->getMessage()}) — the MCP route cannot mount. Point 'server' in the statamic.mcp config at a class that loads, or remove the key.");

            return;
        }

        $given = is_string($server) ? $server : get_debug_type($server);

        $this->problem("Configured server '{$given}' is not a subclass of ".McpServer::class." — the MCP route cannot mount. Point 'server' in the statamic.mcp config at a class extending Danielgnh\\StatamicMcp\\Server, or remove the key.");
    }

    protected function checkMiddleware(): void
    {
        $entries = Arr::wrap(config('statamic.mcp.middleware', []));

        $broken = false;

        foreach ($entries as $entry) {
            if (! is_string($entry)) {
                $broken = true;

                $this->problem(sprintf("Configured middleware has an entry of type %s, not a name — the MCP route cannot mount. List only class names, aliases, or groups under 'middleware'.", get_debug_type($entry)));

                continue;
            }
            if ($this->middlewareResolves($entry)) {
                continue;
            }
            $broken = true;

            $this->problem("Configured middleware '{$entry}' is neither a class nor a registered middleware alias or group — every MCP request would 500. Fix 'middleware' in the statamic.mcp config.");
        }

        if ($entries !== [] && ! $broken) {
            $this->info('[ OK ] Configured middleware resolves.');
        }
    }

    protected function middlewareResolves(string $entry): bool
    {
        $name = explode(':', $entry, 2)[0];

        if (class_exists($name)) {
            return true;
        }

        app(HttpKernel::class);

        $router = app('router');

        return array_key_exists($name, $router->getMiddleware())
            || array_key_exists($name, $router->getMiddlewareGroups());
    }

    protected function checkAppUrl(): void
    {
        $appUrl = (string) config('app.url');

        if ($appUrl === 'http://localhost') {
            $this->warn("[WARN] APP_URL is Laravel's default (http://localhost) — the endpoint above is only reachable if that is really the site's address. Set APP_URL to the site's public URL.");
        } elseif (str_starts_with($appUrl, 'http://')) {
            $this->warn('[WARN] APP_URL is plain http — Bearer tokens over http travel unencrypted. Serve the MCP endpoint over https.');
        }
    }

    protected function checkAuthMigrations(): void
    {
        $files = glob(database_path('migrations/*_statamic_auth_tables.php')) ?: [];

        if (count($files) < 2) {
            return;
        }

        sort($files);
        $keep = basename(array_shift($files));
        $orphans = implode(', ', array_map(basename(...), $files));

        $this->warn("[WARN] {$orphans} looks like a leftover from an interrupted OAuth setup — a second Statamic auth migration re-adds the 'super' column and would crash 'php artisan migrate'. Delete it, keeping {$keep}.");
    }

    protected function checkTokens(TokenRepository $tokens): void
    {
        $dir = storage_path('statamic/mcp');
        $file = $dir.'/tokens.yaml';

        $probe = $dir;

        while (! is_dir($probe)) {
            $probe = dirname($probe);
        }

        if (! is_writable($probe)) {
            $this->problem('Token store is not writable — fix permissions on '.$probe.' so tokens can be saved to '.$file.'.');
        } elseif (file_exists($file) && ! is_writable($file)) {
            $this->problem($file.' exists but is not writable — it was probably created by a different user (e.g. tokens issued as root). Fix its ownership or permissions.');
        } else {
            $this->info('[ OK ] Token store is writable ('.$dir.').');
        }

        try {
            $records = $tokens->all();

            $unexpired = array_filter($records, fn (array $record) => ! ($record['expires_at'] ?? null) || ! Carbon::parse($record['expires_at'])->isPast());

            Validator::validate($unexpired, ['*.user' => ['required'], '*.user.*' => ['prohibited']]);
        } catch (ErrorException|InvalidArgumentException|TypeError|ValidationException $e) {
            $this->problem('tokens.yaml is corrupt or unreadable ('.$e->getMessage().') — authentication fails closed. Restore it from backup, or delete it and re-issue tokens with: php please mcp:token you@site.com');

            return;
        }

        try {
            $orphaned = count(array_filter($unexpired, fn (array $record) => ! User::find($record['user'])));
        } catch (ErrorException|InvalidArgumentException|PDOException $e) {
            $this->problem('Could not look up the users in tokens.yaml ('.$e->getMessage().') — the file itself is fine. Fix the user store and run this again.');

            return;
        }

        $total = count($records);
        $expired = $total - count($unexpired);
        $active = count($unexpired) - $orphaned;

        $breakdown = implode(', ', array_filter([
            $expired ? $expired.' expired' : null,
            $orphaned ? $orphaned.' orphaned-user' : null,
        ]));

        if ($total === 0) {
            $this->warn('[WARN] No tokens issued — the endpoint is a locked door. Run: php please mcp:token you@site.com');
        } elseif ($active === 0) {
            $this->warn("[WARN] {$total} token(s) issued but none are active ({$breakdown}) — the endpoint is a locked door. Run: php please mcp:token you@site.com");
        } elseif ($active === $total) {
            $this->info('[ OK ] '.$total.' token(s) issued.');
        } else {
            $this->info("[ OK ] {$total} token(s) issued ({$active} active, {$breakdown}).");
        }
    }

    protected function checkOAuth(): void
    {
        $passportInstalled = $this->prereqs->passportInstalled();

        if ($passportInstalled) {
            $this->info('[ OK ] Laravel Passport is installed.');
        } else {
            $this->problem("Laravel Passport is not installed. Run 'composer require laravel/passport' and follow the OAuth setup in the statamic-mcp README, or switch to token mode ('auth' => 'token').");
        }

        if (! $passportInstalled) {
            return;
        }

        $this->checkPassportKeys();
        $this->checkOAuthTables();
        $this->checkAuthorizationView();
    }

    protected function checkPassportKeys(): void
    {
        if ($this->prereqs->passportKeysUndecryptable()) {
            $this->problem("Passport's stored signing key can't be decrypted — APP_KEY changed since it was stored. Restore the previous APP_KEY, or delete the row in '".KeyStore::TABLE."' to let a fresh pair provision (every connected client must then reconnect).");

            return;
        }

        $source = $this->prereqs->keySource();

        match ($source) {
            'config' => $this->info('[ OK ] Passport encryption keys are available (environment config).'),
            'database' => $this->info('[ OK ] Passport encryption keys are available (database).'),
            'files' => $this->info('[ OK ] Passport encryption keys are available (key files — they will be adopted into the database on first use).'),
            'provisionable' => $this->info('[ OK ] Passport encryption keys will be provisioned into the database on first use.'),
            default => $this->problem("Passport's encryption keys are missing. Run 'php artisan migrate' — the addon provisions a pair into the database automatically — or 'php please mcp:keys' to generate one now."),
        };
    }

    protected function checkOAuthTables(): void
    {
        if (! $this->prereqs->oauthTablesMigrated()) {
            $this->problem("Passport's tables are missing — the first connector handshake would 500. Run 'php artisan vendor:publish --tag=passport-migrations' then 'php artisan migrate'. OAuth mode needs a database for Passport's OWN tables only; your users stay wherever they are (file users work).");

            return;
        }

        if (! $this->prereqs->oauthUserIdColumnsFitStatamicIds()) {
            $this->problem("Passport's user_id columns are integers, but Statamic ids are UUID strings — the first consent would crash on insert. Run 'php artisan migrate': the addon ships a migration converting the columns to string(36) (safe for integer ids too).");

            return;
        }

        $this->info("[ OK ] Passport tables exist and user_id columns fit Statamic's ids.");
    }

    protected function checkAuthorizationView(): void
    {
        if ($this->prereqs->authorizationViewBound()) {
            $this->info('[ OK ] OAuth consent view is bound.');
        } else {
            $this->problem("No OAuth consent view is bound — /oauth/authorize would 500 with \"Target [Laravel\\Passport\\Contracts\\AuthorizationViewResponse] is not instantiable\". The addon binds one automatically in OAuth mode; if this fails, its boot did not run — check the log for 'Statamic MCP failed to mount'. To supply your own, call Passport::authorizationView() in your AppServiceProvider.");
        }
    }

    protected function problem(string $message): void
    {
        $this->failed = true;

        $this->error('[FAIL] '.$message);
    }
}
