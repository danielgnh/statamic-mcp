<?php

namespace Danielgnh\StatamicMcp\Console;

use Closure;
use Danielgnh\StatamicMcp\Setup\EditResult;
use Danielgnh\StatamicMcp\Setup\EnvWriter;
use Danielgnh\StatamicMcp\Support\OAuthPrerequisites;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Statamic\Console\RunsInPlease;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

class Setup extends Command
{
    use RunsInPlease;

    protected $signature = 'statamic:mcp:setup
        {--oauth : Set up OAuth mode without asking which mode}
        {--token : Set up token mode without asking which mode}
        {--user= : Email for the first token (token mode)}
        {--yes : Run unattended — apply every change without confirming}';

    protected $description = 'Setup wizard for the MCP server — token or OAuth mode. Interactive by default, unattended with --yes.';

    protected OAuthPrerequisites $prereqs;

    public function handle(OAuthPrerequisites $prereqs, EnvWriter $env): int
    {
        $this->prereqs = $prereqs;

        if ($this->option('oauth') && $this->option('token')) {
            $this->components->error('Pass either --oauth or --token, not both.');

            return self::FAILURE;
        }

        $this->components->info('Statamic MCP setup');

        $mode = match (true) {
            (bool) $this->option('oauth') => 'oauth',
            (bool) $this->option('token') => 'token',
            default => select(
                label: 'How will AI clients connect to this site?',
                options: [
                    'token' => 'Token — Claude Code, Cursor, MCP Inspector',
                    'oauth' => 'OAuth — claude.ai, Claude Desktop, ChatGPT connectors',
                ],
                default: config('statamic.mcp.auth', 'token'),
                hint: 'OAuth needs a database for Passport\'s own tables — your users stay where they are.',
            ),
        };

        return $mode === 'oauth'
            ? $this->setupOAuth($env)
            : $this->setupToken($env);
    }

    protected function setupToken(EnvWriter $env): int
    {
        if (config('statamic.mcp.auth') === 'oauth') {
            $this->applyEdit(
                'Set STATAMIC_MCP_AUTH=token in .env',
                base_path('.env'),
                fn () => $env->apply(base_path('.env'), 'STATAMIC_MCP_AUTH', 'token'),
                fn () => $env->snippet('STATAMIC_MCP_AUTH', 'token'),
            );
        }

        if (! $email = $this->option('user')) {
            if ($this->option('yes')) {
                $this->components->error('Token mode with --yes needs --user=you@site.com.');

                return self::FAILURE;
            }

            $email = text(
                label: 'Which Statamic user should the first token act as?',
                placeholder: 'you@site.com',
                required: true,
            );
        }

        // mcp:token owns issuance, output, and the permission/APP_URL warnings.
        return $this->call('statamic:mcp:token', ['email' => $email]);
    }

    /**
     * Users stay wherever they are — file or Eloquent — because the addon's
     * own guard resolves OAuth tokens through the Statamic repository. What
     * OAuth needs is Passport itself, its keys, and its tables (with user_id
     * columns wide enough for Statamic's UUID ids — the addon's migration).
     *
     * Every step follows the same rhythm: already satisfied? report and move
     * on — otherwise confirm and apply. The .env flip runs before the final
     * migrate on purpose: the addon's user_id migration only loads in OAuth
     * mode, so the subprocess must already see the new mode. An aborted run
     * still never leaves a broken endpoint live — the route answers 503 with
     * the exact remedy until every prerequisite is in place.
     */
    protected function setupOAuth(EnvWriter $env): int
    {
        $steps = [
            $this->ensurePassportInstalled(...),
            $this->ensurePassportKeys(...),
            $this->offerConsentViews(...),
            fn (): bool => $this->flipAuthMode($env),
            $this->runMigrations(...),
        ];

        foreach ($steps as $step) {
            if (! $step()) {
                $this->components->error('Setup stopped. Fix the problem above and re-run `php please mcp:setup` — completed steps will be skipped.');

                return self::FAILURE;
            }
        }

        return $this->finale();
    }

    protected function ensurePassportInstalled(): bool
    {
        if ($this->prereqs->passportInstalled()) {
            $this->components->twoColumnDetail('Laravel Passport', 'skipped — already installed');

            return true;
        }

        if (! $this->confirmStep('Install laravel/passport via composer now?')) {
            $this->printManual('composer require laravel/passport');

            return false; // every remaining step needs the package
        }

        return $this->runProcess('composer require laravel/passport');
    }

    protected function ensurePassportKeys(): bool
    {
        if ($this->prereqs->passportKeysExist()) {
            $this->components->twoColumnDetail('Passport encryption keys', 'skipped — already available');

            return true;
        }

        if (! $this->confirmStep('Generate Passport encryption keys now?')) {
            $this->printManual("php please mcp:keys\nGenerates a pair if needed and prints deploy-ready PASSPORT_* env variables.");

            return false;
        }

        // Subprocess on purpose: when Passport was installed moments ago by
        // this very wizard, its commands are not registered in THIS process.
        if (! $this->runProcess('php artisan passport:keys')) {
            return false;
        }

        $this->line('  Deploying? Run `php please mcp:keys` and paste its output into the production');
        $this->line('  environment — PASSPORT_* env vars survive releases and are shared across');
        $this->line('  servers (storage/oauth-*.key is per-machine and gitignored).');

        return true;
    }

    protected function offerConsentViews(): bool
    {
        // OAuth mode auto-binds a working, self-contained consent screen (see
        // the addon service provider), so this step is optional — publish only
        // to customize the Blade. It publishes the ADDON's view, not
        // laravel/mcp's: that one depends on a compiled Vite/Tailwind bundle and
        // 500s on any site without one, which is why the addon ships its own.
        if (! $this->confirmStep('Publish the OAuth consent screen to customize it? (a working default is already bound)', whenYes: false, default: false)) {
            return true;
        }

        return $this->runProcess('php artisan vendor:publish --tag=statamic-mcp-views');
    }

    protected function flipAuthMode(EnvWriter $env): bool
    {
        if (config('statamic.mcp.auth') === 'oauth') {
            $this->components->twoColumnDetail('STATAMIC_MCP_AUTH=oauth', 'skipped — already set');
        } else {
            $this->applyEdit(
                'Set STATAMIC_MCP_AUTH=oauth in .env',
                base_path('.env'),
                fn () => $env->apply(base_path('.env'), 'STATAMIC_MCP_AUTH', 'oauth'),
                fn () => $env->snippet('STATAMIC_MCP_AUTH', 'oauth'),
            );
        }

        if (app()->configurationIsCached()) {
            return $this->runProcess('php artisan config:clear');
        }

        return true;
    }

    /**
     * Runs AFTER the mode flip so the subprocess loads the addon's user_id
     * migration (OAuth mode only). Publishing Passport's migrations first is
     * required — Passport 13 doesn't auto-load them — and is idempotent.
     */
    protected function runMigrations(): bool
    {
        if ($this->prereqs->oauthTablesMigrated() && $this->prereqs->oauthUserIdColumnsFitStatamicIds()) {
            $this->components->twoColumnDetail('Passport tables (Statamic-ready user_id)', 'skipped — already migrated');

            return true;
        }

        if (! $this->confirmStep('Publish and run the Passport migrations now?')) {
            $this->printManual("php artisan vendor:publish --tag=passport-migrations\nphp artisan migrate");

            return false;
        }

        return $this->runProcess('php artisan vendor:publish --tag=passport-migrations')
            && $this->runProcess('php artisan migrate');
    }

    protected function finale(): int
    {
        $this->line('');
        $this->components->info('Verifying with mcp:doctor…');

        // Subprocess on purpose: the doctor must see the files this wizard
        // just edited, not this process's stale in-memory config.
        $healthy = $this->runProcess('php please mcp:doctor');

        $url = url(config()->string('statamic.mcp.route', 'mcp/statamic'));

        if (! $healthy) {
            $this->components->error('The doctor found problems — fix the [FAIL] items above and re-run `php please mcp:setup`.');

            return self::FAILURE;
        }

        $this->components->info('Done. Add this connector URL to claude.ai or ChatGPT: '.$url);
        $this->line('Connectors need the site reachable over HTTPS from the internet.');

        return self::SUCCESS;
    }

    protected function runProcess(string $command): bool
    {
        $this->line('  → '.$command);

        $result = Process::forever()->run($command, function (string $type, string $output) {
            $this->output->write($output);
        });

        if ($result->failed()) {
            $this->components->error("'{$command}' failed (exit {$result->exitCode()}).");
        }

        return $result->successful();
    }

    /**
     * Every confirmation goes through here so --yes means one thing
     * everywhere: answer with $whenYes instead of prompting. Steps that are
     * optional say so by passing whenYes: false.
     */
    protected function confirmStep(string $label, bool $whenYes = true, bool $default = true): bool
    {
        if ($this->option('yes')) {
            return $whenYes;
        }

        return confirm($label, default: $default);
    }

    /**
     * The one rhythm every file edit follows: announce the change and the
     * file, confirm, apply — and on decline or bail, print the manual snippet
     * and carry on. The wizard never edits silently (--yes still shows every
     * change) and never mangles a file it doesn't recognize.
     *
     * @param  Closure(): EditResult  $apply
     * @param  Closure(): string  $snippet
     */
    protected function applyEdit(string $description, string $path, Closure $apply, Closure $snippet): void
    {
        $this->line('');
        $this->components->info($description);
        $this->line('  File: '.$path);
        $this->line('');
        $this->line('  '.str_replace("\n", "\n  ", $snippet()));
        $this->line('');

        if (! $this->confirmStep('Apply this change to '.$path.'?')) {
            $this->components->warn('Skipped — apply the snippet above manually before connecting a client.');

            return;
        }

        match ($apply()) {
            EditResult::Applied => $this->components->twoColumnDetail($description, 'applied'),
            EditResult::Skipped => $this->components->twoColumnDetail($description, 'skipped — already in place'),
            EditResult::Bailed => $this->components->warn($path." doesn't look like the file this wizard expects — apply the snippet above manually."),
        };
    }

    protected function printManual(string $instructions): void
    {
        $this->components->warn('Manual step required:');
        $this->line('  '.str_replace("\n", "\n  ", $instructions));
    }
}
