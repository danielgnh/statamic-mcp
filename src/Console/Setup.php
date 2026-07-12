<?php

namespace Danielgnh\StatamicMcp\Console;

use Closure;
use Danielgnh\StatamicMcp\Setup\AuthGuardEditor;
use Danielgnh\StatamicMcp\Setup\EditResult;
use Danielgnh\StatamicMcp\Setup\EnvWriter;
use Danielgnh\StatamicMcp\Setup\UserModelEditor;
use Danielgnh\StatamicMcp\Setup\UsersRepositoryEditor;
use Danielgnh\StatamicMcp\Support\OAuthPrerequisites;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use ReflectionClass;
use Statamic\Console\RunsInPlease;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

class Setup extends Command
{
    use RunsInPlease;

    protected $signature = 'statamic:mcp:setup';

    protected $description = 'Interactive setup wizard for the MCP server — token or OAuth mode.';

    protected OAuthPrerequisites $prereqs;

    public function handle(OAuthPrerequisites $prereqs, EnvWriter $env): int
    {
        $this->prereqs = $prereqs;

        $this->components->info('Statamic MCP setup');

        $mode = select(
            label: 'How will AI clients connect to this site?',
            options: [
                'token' => 'Token — Claude Code, Cursor, MCP Inspector',
                'oauth' => 'OAuth — claude.ai, Claude Desktop, ChatGPT connectors',
            ],
            default: config('statamic.mcp.auth', 'token'),
            hint: 'OAuth requires database (Eloquent) users — a Passport constraint.',
        );

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

        $email = text(
            label: 'Which Statamic user should the first token act as?',
            placeholder: 'you@site.com',
            required: true,
        );

        // mcp:token owns issuance, output, and the permission/APP_URL warnings.
        return $this->call('statamic:mcp:token', ['email' => $email]);
    }

    /**
     * Every step follows the same rhythm: already satisfied? report and move
     * on — otherwise confirm and apply. The .env flip runs LAST so an aborted
     * run never leaves a broken oauth mode live; token mode keeps working
     * until everything else is in place.
     */
    protected function setupOAuth(EnvWriter $env): int
    {
        $steps = [
            fn (): bool => $this->ensureEloquentUsers(),
            fn (): bool => $this->ensurePassportInstalled(),
            fn (): bool => $this->ensurePassportPlumbing(),
            fn (): bool => $this->ensureUserModelPrepared(),
            fn (): bool => $this->ensureApiGuard(),
            fn (): bool => $this->offerConsentViews(),
            fn (): bool => $this->flipAuthMode($env),
        ];

        foreach ($steps as $step) {
            if (! $step()) {
                $this->components->error('Setup stopped. Fix the problem above and re-run `php please mcp:setup` — completed steps will be skipped.');

                return self::FAILURE;
            }
        }

        return $this->finale();
    }

    protected function ensureEloquentUsers(): bool
    {
        if ($this->prereqs->usersAreEloquent()) {
            $this->components->twoColumnDetail('Database (Eloquent) users', 'skipped — already configured');

            return true;
        }

        $this->components->warn('OAuth mode requires database users (a Passport constraint). This migrates your user data — back up first if in doubt.');

        if (! confirm('Migrate users to the database now?')) {
            $this->printManual('Migrate users per https://statamic.dev/tips/storing-users-in-a-database, then re-run this wizard.');

            return false; // everything after this depends on Eloquent users
        }

        if (! $this->runProcess('php please auth:migration')
            || ! $this->runProcess('php artisan migrate')
            || ! $this->runProcess('php please eloquent:import-users')) {
            return false;
        }

        $editor = app(UsersRepositoryEditor::class);

        $this->applyEdit(
            "Set 'repository' => 'eloquent' in config/statamic/users.php",
            config_path('statamic/users.php'),
            fn () => $editor->apply(config_path('statamic/users.php')),
            fn () => $editor->snippet(),
        );

        return true;
    }

    protected function ensurePassportInstalled(): bool
    {
        if ($this->prereqs->passportInstalled()) {
            $this->components->twoColumnDetail('Laravel Passport', 'skipped — already installed');

            return true;
        }

        if (! confirm('Install laravel/passport via composer now?')) {
            $this->printManual('composer require laravel/passport');

            return false; // every remaining step needs the package
        }

        return $this->runProcess('composer require laravel/passport');
    }

    protected function ensurePassportPlumbing(): bool
    {
        if ($this->prereqs->passportKeysExist()) {
            $this->components->twoColumnDetail('Passport migrations & keys', 'skipped — keys already exist');

            return true;
        }

        if (! confirm('Publish Passport migrations, run them, and generate encryption keys?')) {
            $this->printManual("php artisan vendor:publish --tag=passport-migrations\nphp artisan migrate\nphp artisan passport:keys");

            return false;
        }

        // Subprocesses on purpose: when Passport was installed moments ago by
        // this very wizard, its commands are not registered in THIS process.
        return $this->runProcess('php artisan vendor:publish --tag=passport-migrations')
            && $this->runProcess('php artisan migrate')
            && $this->runProcess('php artisan passport:keys');
    }

    protected function ensureUserModelPrepared(): bool
    {
        $model = $this->prereqs->userModel();

        if (! $model || ! class_exists($model)) {
            $this->printManual('No user model resolved from auth.providers — add the Laravel\Passport\HasApiTokens trait to your user model manually (see the README OAuth guide).');

            return true; // not fatal for the remaining steps
        }

        if ($this->prereqs->userModelHasTrait()) {
            $this->components->twoColumnDetail('HasApiTokens trait on '.$model, 'skipped — already present');

            return true;
        }

        $interface = $this->oauthenticatableInterface();
        $path = (new ReflectionClass($model))->getFileName();
        $editor = app(UserModelEditor::class);

        $this->applyEdit(
            'Add HasApiTokens'.($interface ? ' + OAuthenticatable' : '').' to '.$model,
            $path,
            fn () => $editor->apply($path, $interface),
            fn () => $editor->snippet($interface),
        );

        return true;
    }

    /**
     * The FQCN is resolved from the environment, never hardcoded blind:
     * interface_exists() covers Passport already loaded; the vendor-path probe
     * covers Passport installed by this very wizard in a subprocess, where the
     * running autoloader can't see the new package yet.
     */
    protected function oauthenticatableInterface(): ?string
    {
        $interface = 'Laravel\Passport\Contracts\OAuthenticatable';

        if (interface_exists($interface)
            || is_file(base_path('vendor/laravel/passport/src/Contracts/OAuthenticatable.php'))) {
            return $interface;
        }

        return null;
    }

    protected function ensureApiGuard(): bool
    {
        if ($this->prereqs->apiGuardIsPassport()) {
            $this->components->twoColumnDetail("'api' guard (passport driver)", 'skipped — already configured');

            return true;
        }

        $editor = app(AuthGuardEditor::class);

        $this->applyEdit(
            "Add the 'api' guard (passport driver) to config/auth.php",
            config_path('auth.php'),
            fn () => $editor->apply(config_path('auth.php')),
            fn () => $editor->snippet(),
        );

        return true;
    }

    protected function offerConsentViews(): bool
    {
        if (! confirm('Publish the OAuth consent screen views (customizable Blade)?', default: false)) {
            return true;
        }

        return $this->runProcess('php artisan vendor:publish --tag=mcp-views');
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

    protected function finale(): int
    {
        $this->line('');
        $this->components->info('Verifying with mcp:doctor…');

        // Subprocess on purpose: the doctor must see the files this wizard
        // just edited, not this process's stale in-memory config.
        $healthy = $this->runProcess('php please mcp:doctor');

        $url = url(config('statamic.mcp.route', 'mcp/statamic'));

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
     * The one rhythm every file edit follows: announce the change and the
     * file, confirm, apply — and on decline or bail, print the manual snippet
     * and carry on. The wizard never edits silently and never mangles a file
     * it doesn't recognize.
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

        if (! confirm('Apply this change to '.$path.'?')) {
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
