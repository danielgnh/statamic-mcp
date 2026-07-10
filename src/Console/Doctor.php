<?php

namespace Danielgnh\StatamicMcp\Console;

use Danielgnh\StatamicMcp\Tokens\TokenRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Passport;
use Statamic\Console\RunsInPlease;

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

    public function handle(TokenRepository $tokens): int
    {
        $mode = config('statamic.mcp.auth', 'token');

        $this->line('Statamic MCP doctor');
        $this->line('');
        $this->line('  Endpoint:  '.url(config('statamic.mcp.route', 'mcp/statamic')));
        $this->line('  Auth mode: '.$mode);
        $this->line('');

        $this->checkEnabled();
        $this->checkAppUrl();

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
        $uri = ltrim(config('statamic.mcp.route', 'mcp/statamic'), '/');

        foreach (Route::getRoutes() as $route) {
            if ($route->uri() === $uri && in_array('POST', $route->methods(), true)) {
                return true;
            }
        }

        return false;
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

        $count = count($tokens->all());

        if ($count === 0) {
            $this->warn('[WARN] No tokens issued — the endpoint is a locked door. Run: php please mcp:token you@site.com');
        } else {
            $this->info('[ OK ] '.$count.' token(s) issued.');
        }
    }

    /**
     * Mirrors AuthenticateOAuth's preflight (same prerequisites, same remedy
     * texts) but never short-circuits — every missing piece is named at once.
     */
    protected function checkOAuth(): void
    {
        $passportInstalled = class_exists(Passport::class);

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
        $repository = config('statamic.users.repository', 'file');

        // The repository name is arbitrary — what matters is the driver it
        // resolves to (a 'custom' repository may still be file-driven).
        $driver = config('statamic.users.repositories.'.$repository.'.driver', $repository);

        if ($driver === 'file') {
            $this->problem("Users are file-based (the '{$repository}' repository resolves to the file driver) — OAuth mode requires database (Eloquent) users, a Passport constraint, not ours. Run 'php please auth:migration' then 'php please eloquent:import-users', or switch to token mode ('auth' => 'token').");

            return;
        }

        $this->info("[ OK ] Users are database-backed (repository: {$repository}, driver: {$driver}).");

        // Without Passport the trait cannot be in use anyway — its [FAIL]
        // above already owns the remedy, so skip the noise.
        if ($passportInstalled) {
            $this->checkUserModelTrait();
        }
    }

    protected function checkUserModelTrait(): void
    {
        $provider = config('auth.guards.api.provider') ?? 'users';
        $model = config('auth.providers.'.$provider.'.model');

        if ($model && class_exists($model) && in_array('Laravel\\Passport\\HasApiTokens', class_uses_recursive($model), true)) {
            $this->info('[ OK ] User model '.$model.' uses the HasApiTokens trait.');
        } else {
            $this->problem('User model '.($model ?: "(none configured in auth.providers.{$provider}.model)").' is missing the Laravel\\Passport\\HasApiTokens trait — add it per the README OAuth guide.');
        }
    }

    protected function checkApiGuard(): void
    {
        $guard = config('auth.guards.api');

        if (! $guard) {
            $this->problem("No 'api' guard is defined — Laravel 12 and 13 ship none. In config/auth.php add 'api' => ['driver' => 'passport', 'provider' => 'users'] under 'guards'.");

            return;
        }

        $driver = $guard['driver'] ?? '(none)';

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
