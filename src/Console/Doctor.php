<?php

namespace Danielgnh\StatamicMcp\Console;

use Danielgnh\StatamicMcp\Support\OAuthPrerequisites;
use Danielgnh\StatamicMcp\Tokens\TokenRepository;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Statamic\Console\RunsInPlease;
use Statamic\Facades\User;
use Throwable;

/**
 * One command that answers "why doesn't my MCP endpoint work?". Every [FAIL]
 * carries the same remedy text the middleware answers with at runtime, checks
 * never short-circuit (all problems are named at once), and any [FAIL] exits
 * FAILURE while [WARN]s alone exit SUCCESS.
 */
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

        $this->line('Statamic MCP doctor');
        $this->line('');
        $this->line('  Endpoint:  '.url(config()->string('statamic.mcp.route', 'mcp/statamic')));
        $this->line('  Auth mode: '.$mode);
        $this->line('');

        $this->checkEnabled();
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
            $this->warn("[WARN] MCP is disabled ('enabled' => false) — the endpoint is not registered. Set STATAMIC_MCP_ENABLED=true to serve requests.");

            return;
        }

        $this->info('[ OK ] MCP is enabled.');

        // Enabled but no route: bootAddon caught a mount failure and logged
        // 'Statamic MCP failed to mount' instead of bricking the host site.
        if ($this->routeIsMounted()) {
            $this->info('[ OK ] MCP route is mounted.');
        } else {
            $this->problem("MCP is enabled but its route is not mounted — boot failed and the endpoint 404s. Check the log for 'Statamic MCP failed to mount' and fix the reported exception.");
        }
    }

    protected function routeIsMounted(): bool
    {
        $uri = trim((string) config('statamic.mcp.route', 'mcp/statamic'), '/');

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if ($route->uri() === $uri && in_array('POST', $route->methods(), true)) {
                return true;
            }
        }

        return false;
    }

    protected function checkMiddleware(): void
    {
        $entries = Arr::wrap(config('statamic.mcp.middleware', []));

        $broken = false;

        foreach ($entries as $entry) {
            if (! is_string($entry)) {
                continue;
            }
            if ($this->middlewareResolves($entry)) {
                continue;
            }
            $broken = true;

            // A typo'd class mounts fine and 500s every request — the one
            // misconfiguration the route itself can never report.
            $this->problem("Configured middleware '{$entry}' is neither a class nor a registered middleware alias or group — every MCP request would 500. Fix 'middleware' in the statamic.mcp config.");
        }

        if ($entries !== [] && ! $broken) {
            $this->info('[ OK ] Configured middleware resolves.');
        }
    }

    protected function middlewareResolves(string $entry): bool
    {
        $name = explode(':', $entry, 2)[0]; // strip alias parameters like throttle:60,1

        if (class_exists($name)) {
            return true;
        }

        // Aliases and groups are synced from the HTTP Kernel to the router in
        // the Kernel's constructor — a console run may never have built it.
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

    /**
     * Statamic's auth:migration is not idempotent — a second file re-adds the
     * `super` column and crashes `php artisan migrate`. An interrupted OAuth
     * setup leaves exactly that: a duplicate *_statamic_auth_tables.php the
     * installer never ran. A healthy site has exactly one, so 2+ is the tell.
     */
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

        // The directory may not exist before the first token is issued —
        // probe the closest existing ancestor for writability.
        $probe = $dir;

        while (! is_dir($probe)) {
            $probe = dirname($probe);
        }

        if (! is_writable($probe)) {
            $this->problem('Token store is not writable — fix permissions on '.$probe.' so tokens can be saved to '.$file.'.');
        } elseif (file_exists($file) && ! is_writable($file)) {
            // Ownership mismatch (e.g. tokens issued as root, served as the
            // deploy user): the ancestor probe passes but writes still fail.
            $this->problem($file.' exists but is not writable — it was probably created by a different user (e.g. tokens issued as root). Fix its ownership or permissions.');
        } else {
            $this->info('[ OK ] Token store is writable ('.$dir.').');
        }

        // Corrupt YAML must not crash the very command that diagnoses it —
        // authentication reads the same file and fails closed.
        try {
            $records = $tokens->all();

            [$active, $expired, $orphaned] = $this->classifyTokens($records);
        } catch (Throwable $e) {
            $this->problem('tokens.yaml is corrupt or unreadable ('.$e->getMessage().') — authentication fails closed. Restore it from backup, or delete it and re-issue tokens with: php please mcp:token you@site.com');

            return;
        }

        $total = count($records);

        $breakdown = implode(', ', array_filter([
            $expired ? $expired.' expired' : null,
            $orphaned ? $orphaned.' orphaned-user' : null,
        ]));

        if ($total === 0) {
            $this->warn('[WARN] No tokens issued — the endpoint is a locked door. Run: php please mcp:token you@site.com');
        } elseif ($active === 0) {
            // Dead tokens are worse than none: everything looks issued while
            // every request 401s.
            $this->warn("[WARN] {$total} token(s) issued but none are active ({$breakdown}) — the endpoint is a locked door. Run: php please mcp:token you@site.com");
        } elseif ($active === $total) {
            $this->info('[ OK ] '.$total.' token(s) issued.');
        } else {
            $this->info("[ OK ] {$total} token(s) issued ({$active} active, {$breakdown}).");
        }
    }

    /**
     * Active means the authentication middleware would accept it: unexpired
     * AND its user still exists.
     *
     * @param  array<string, array{user: string, name: ?string, hash: string, created_at: string, expires_at: ?string}>  $records
     * @return array{0: int, 1: int, 2: int} [active, expired, orphaned]
     */
    protected function classifyTokens(array $records): array
    {
        $active = $expired = $orphaned = 0;

        foreach ($records as $record) {
            if (($record['expires_at'] ?? null) && Carbon::parse($record['expires_at'])->isPast()) {
                $expired++;
            } elseif (! User::find($record['user'])) {
                $orphaned++;
            } else {
                $active++;
            }
        }

        return [$active, $expired, $orphaned];
    }

    /**
     * Mirrors AuthenticateOAuth's preflight (same prerequisites, same remedy
     * texts) but never short-circuits — every missing piece is named at once.
     * No user-repository check on purpose: OAuth mode works with file users
     * and Eloquent users alike, via the addon's own guard.
     */
    protected function checkOAuth(): void
    {
        $passportInstalled = $this->prereqs->passportInstalled();

        if ($passportInstalled) {
            $this->info('[ OK ] Laravel Passport is installed.');
        } else {
            $this->problem("Laravel Passport is not installed. Run 'composer require laravel/passport' and follow the OAuth setup in the statamic-mcp README, or switch to token mode ('auth' => 'token').");
        }

        // The remaining checks presuppose Passport (keys, tables, view
        // binding) — the [FAIL] above owns the remedy; more would be noise.
        if (! $passportInstalled) {
            return;
        }

        $this->checkPassportKeys();
        $this->checkOAuthTables();
        $this->checkAuthorizationView();
    }

    protected function checkPassportKeys(): void
    {
        if ($this->prereqs->passportKeysExist()) {
            $this->info('[ OK ] Passport encryption keys are available.');
        } else {
            $this->problem("Passport's encryption keys are missing. Run 'php please mcp:keys' — it generates a pair if needed and prints deploy-ready PASSPORT_PRIVATE_KEY / PASSPORT_PUBLIC_KEY environment variables (keys in the environment survive releases and are shared across servers).");
        }
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

    /**
     * Passport 12+ ships no default consent view and never binds
     * AuthorizationViewResponse, so /oauth/authorize 500s ("Target [...] is not
     * instantiable") the moment a connector reaches consent — while every other
     * check stays green.
     */
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
