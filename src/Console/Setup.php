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

        return $this->call('statamic:mcp:token', ['email' => $email]);
    }

    protected function setupOAuth(EnvWriter $env): int
    {
        $steps = [
            $this->ensurePassportInstalled(...),
            $this->offerConsentViews(...),
            fn (): bool => $this->flipAuthMode($env),
            $this->runMigrations(...),
            $this->ensurePassportKeys(...),
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

            return false;
        }

        return $this->runProcess('composer require laravel/passport');
    }

    protected function ensurePassportKeys(): bool
    {
        if (! in_array($this->prereqs->keySource(), [null, 'provisionable'], true)) {
            $this->components->twoColumnDetail('Passport encryption keys', 'skipped — already available');

            return true;
        }

        if (! $this->confirmStep('Provision Passport encryption keys now?')) {
            $this->line('  Keys will be provisioned into the database automatically on the first OAuth request.');

            return true;
        }

        return $this->runProcess('php please mcp:keys', stdout: false);
    }

    protected function offerConsentViews(): bool
    {
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

    protected function runMigrations(): bool
    {
        if ($this->prereqs->oauthTablesMigrated()
            && $this->prereqs->oauthUserIdColumnsFitStatamicIds()
            && $this->prereqs->keyStoreMigrated()) {
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

        if (! $this->runProcess('php please mcp:doctor')) {
            $this->components->error('The doctor found problems — fix the [FAIL] items above and re-run `php please mcp:setup`.');

            return self::FAILURE;
        }

        $this->components->info('Done. Add this connector URL to claude.ai or ChatGPT: '.url(config()->string('statamic.mcp.route', 'mcp/statamic')));
        $this->line('Connectors need the site reachable over HTTPS from the internet.');

        return self::SUCCESS;
    }

    protected function runProcess(string $command, bool $stdout = true): bool
    {
        $this->line('  → '.$command);

        if ($stdout) {
            $result = Process::forever()->run($command, function (string $type, string $output) {
                $this->output->write($output);
            });
        } else {
            $result = Process::forever()->run($command);

            $this->output->write($result->errorOutput());
        }

        if ($result->failed()) {
            $this->components->error("'{$command}' failed (exit {$result->exitCode()}).");
        }

        return $result->successful();
    }

    protected function confirmStep(string $label, bool $whenYes = true, bool $default = true): bool
    {
        if ($this->option('yes')) {
            return $whenYes;
        }

        return confirm($label, default: $default);
    }

    /**
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
