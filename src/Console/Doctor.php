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
        $this->line('  Endpoint:  '.url(config('statamic.mcp.route', 'mcp/statamic')));
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
            // Bearer over plaintext http: every token on the wire is readable.
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
     * @return array{0: int, 1: int, 2: int} [active, expired, orphaned]
     */
    protected function classifyTokens(array $records): array
    {
        $active = $expired = $orphaned = 0;

        foreach ($records as $record) {
            if (($record['expires_at'] ?? null) && Carbon::parse($record['expires_at'])->isPast()) {
                $expired++;
            } elseif (! User::find($record['user'] ?? '')) {
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
     */
    protected function checkOAuth(): void
    {
        $passportInstalled = $this->prereqs->passportInstalled();

        if ($passportInstalled) {
            $this->info('[ OK ] Laravel Passport is installed.');
        } else {
            $this->problem("Laravel Passport is not installed. Run 'composer require laravel/passport' and follow the OAuth setup in the statamic-mcp README, or switch to token mode ('auth' => 'token').");
        }

        $this->checkOAuthUsers($passportInstalled);
        $this->checkApiGuard();
    }

    protected function checkOAuthUsers(bool $passportInstalled): void
    {
        $repository = $this->prereqs->usersRepository();
        $driver = $this->prereqs->usersDriver() ?? '(none)';

        if ($driver === 'file') {
            $this->problem("Users are file-based (the '{$repository}' repository resolves to the file driver) — OAuth mode requires database (Eloquent) users, a Passport constraint, not ours. Run 'php please mcp:setup --oauth' to migrate them (it checks every prerequisite first), or switch to token mode ('auth' => 'token').".$this->uuidReadinessNote());

            return;
        }

        if ($driver !== 'eloquent') {
            $this->problem("Users use the '{$driver}' driver (repository: {$repository}) — OAuth mode requires database (Eloquent) users, a Passport constraint, not ours. Run 'php please mcp:setup --oauth' to migrate them (it checks every prerequisite first), or switch to token mode ('auth' => 'token').".$this->uuidReadinessNote());

            return;
        }

        $this->info("[ OK ] Users are database-backed (repository: {$repository}, driver: {$driver}).");

        // Without Passport the trait cannot be in use anyway — its [FAIL]
        // above already owns the remedy, so skip the noise.
        if ($passportInstalled) {
            $this->checkUserModelTrait();
        }
    }

    /**
     * The migration remedy above is only honest if the schema can actually
     * take it: Statamic file users are keyed by UUID, eloquent:import-users
     * preserves those ids, and Laravel's stock users table (bigint id) can
     * never hold them — the conflict that strands an unattended setup. Name
     * the conversion here so the operator (or their agent) knows the real
     * first step.
     */
    protected function uuidReadinessNote(): string
    {
        $blockers = [];

        if (! $this->prereqs->importModelHasUuids()) {
            $blockers[] = 'the user model '.($this->prereqs->importUserModel() ?? 'App\Models\User').' is missing the HasUuids trait';
        }

        if (! $this->prereqs->usersIdColumnAcceptsUuids()) {
            $table = config('statamic.users.tables.users', 'users');
            $type = $this->prereqs->usersIdColumnType() ?? 'missing';
            $blockers[] = "the '{$table}' table id column is '{$type}', not a UUID";
        }

        if ($blockers === []) {
            return '';
        }

        return ' Note: the import preserves file-user UUID ids, but '.implode(' and ', $blockers).' — add the HasUuids trait and convert the id column (plus referencing foreign keys) to UUID first; the setup wizard prints the exact steps.';
    }

    protected function checkUserModelTrait(): void
    {
        $model = $this->prereqs->userModel();

        if ($this->prereqs->userModelHasTrait()) {
            $this->info('[ OK ] User model '.$model.' uses the HasApiTokens trait.');
        } else {
            $provider = config('auth.guards.api.provider', 'users');

            $this->problem('User model '.($model ?: "(none configured in auth.providers.{$provider}.model)").' is missing the Laravel\\Passport\\HasApiTokens trait — add it per the README OAuth guide.');
        }
    }

    protected function checkApiGuard(): void
    {
        if (! $this->prereqs->apiGuardDefined()) {
            $this->problem("No 'api' guard is defined — Laravel 12 and 13 ship none. In config/auth.php add 'api' => ['driver' => 'passport', 'provider' => 'users'] under 'guards'.");

            return;
        }

        $driver = $this->prereqs->apiGuardDriver() ?? '(none)';

        if ($driver !== 'passport') {
            // Wrong driver is worse than none: OAuth discovery and token
            // issuance complete, then every request 401-loops on tokens the
            // guard ignores — misconfiguration presenting as credential failure.
            $this->problem("The 'api' guard uses the '{$driver}' driver, not 'passport' — OAuth discovery and token issuance would complete, then every request 401-loops on tokens the guard ignores. In config/auth.php set 'api' => ['driver' => 'passport', 'provider' => 'users'] under 'guards'.");

            return;
        }

        $this->info("[ OK ] The 'api' guard uses the passport driver.");
    }

    protected function problem(string $message): void
    {
        $this->failed = true;

        $this->error('[FAIL] '.$message);
    }
}
