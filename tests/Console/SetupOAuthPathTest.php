<?php

use Danielgnh\StatamicMcp\Setup\EditResult;
use Danielgnh\StatamicMcp\Setup\EnvWriter;
use Danielgnh\StatamicMcp\Support\OAuthPrerequisites;
use Illuminate\Support\Facades\Process;

function fakeEnvWriter(): object
{
    $fake = new class extends EnvWriter
    {
        public array $writes = [];

        public function apply(string $path, string $key, string $value): EditResult
        {
            $this->writes[] = [$key, $value];

            return EditResult::Applied;
        }
    };

    app()->instance(EnvWriter::class, $fake);

    return $fake;
}

/**
 * @param  array{installed?: bool, keys?: bool, tables?: bool, columns?: bool}  $state
 */
function stubOAuthPrereqs(array $state = []): void
{
    app()->instance(OAuthPrerequisites::class, new class($state) extends OAuthPrerequisites
    {
        public function __construct(private readonly array $state) {}

        public function passportInstalled(): bool
        {
            return $this->state['installed'] ?? false;
        }

        public function passportKeysExist(): bool
        {
            return $this->state['keys'] ?? false;
        }

        public function oauthTablesMigrated(): bool
        {
            return $this->state['tables'] ?? false;
        }

        public function oauthUserIdColumnsFitStatamicIds(): bool
        {
            return $this->state['columns'] ?? false;
        }
    });
}

const MODE_OPTIONS = [
    'token' => 'Token — Claude Code, Cursor, MCP Inspector',
    'oauth' => 'OAuth — claude.ai, Claude Desktop, ChatGPT connectors',
];

it('walks a fresh install through every oauth step — no user migration anywhere', function () {
    Process::fake();
    $env = fakeEnvWriter();
    stubOAuthPrereqs(); // nothing satisfied

    $this->artisan('statamic:mcp:setup')
        ->expectsChoice('How will AI clients connect to this site?', 'oauth', MODE_OPTIONS)
        ->expectsConfirmation('Install laravel/passport via composer now?', 'yes')
        ->expectsConfirmation('Generate Passport encryption keys now?', 'yes')
        ->expectsConfirmation('Publish the OAuth consent screen to customize it? (a working default is already bound)', 'no')
        ->expectsConfirmation('Apply this change to '.base_path('.env').'?', 'yes')
        ->expectsConfirmation('Publish and run the Passport migrations now?', 'yes')
        ->assertExitCode(0);

    Process::assertRan('composer require laravel/passport');
    Process::assertRan('php artisan passport:keys');
    Process::assertRan('php artisan vendor:publish --tag=passport-migrations');
    Process::assertRan('php artisan migrate');
    Process::assertRan('php please mcp:doctor');

    expect($env->writes)->toBe([['STATAMIC_MCP_AUTH', 'oauth']]);
});

it('skips every oauth step on an already-configured install', function () {
    Process::fake();
    $env = fakeEnvWriter();
    stubOAuthPrereqs(['installed' => true, 'keys' => true, 'tables' => true, 'columns' => true]);

    config(['statamic.mcp.auth' => 'oauth']);

    $this->artisan('statamic:mcp:setup')
        ->expectsChoice('How will AI clients connect to this site?', 'oauth', MODE_OPTIONS)
        // The ONLY prompt left on a satisfied install is the optional views publish:
        ->expectsConfirmation('Publish the OAuth consent screen to customize it? (a working default is already bound)', 'no')
        ->expectsOutputToContain('skipped')
        ->assertExitCode(0);

    // Nothing was installed, migrated, or edited — only the doctor ran.
    Process::assertDidntRun('composer require laravel/passport');
    Process::assertDidntRun('php artisan migrate');
    Process::assertRan('php please mcp:doctor');

    expect($env->writes)->toBe([]);
});

it('runs every oauth step unattended with --oauth --yes', function () {
    Process::fake();
    $env = fakeEnvWriter();
    stubOAuthPrereqs();

    $this->artisan('statamic:mcp:setup', ['--oauth' => true, '--yes' => true])
        ->assertExitCode(0);

    Process::assertRan('composer require laravel/passport');
    Process::assertRan('php artisan passport:keys');
    Process::assertRan('php artisan migrate');
    Process::assertRan('php please mcp:doctor');
    // The optional consent views publish defaults to "no" — --yes keeps that.
    Process::assertDidntRun('php artisan vendor:publish --tag=statamic-mcp-views');

    expect($env->writes)->toBe([['STATAMIC_MCP_AUTH', 'oauth']]);
});

it('flips the env before running migrations, so the user_id migration loads', function () {
    // The addon's user_id migration is only loaded in OAuth mode: a migrate
    // run BEFORE the flip would record nothing and leave bigint columns live.
    $commands = [];

    Process::fake(['*' => function ($process) use (&$commands) {
        $commands[] = $process->command;

        return Process::result();
    }]);

    $env = fakeEnvWriter();
    stubOAuthPrereqs(['installed' => true, 'keys' => true]);

    $this->artisan('statamic:mcp:setup', ['--oauth' => true, '--yes' => true])
        ->assertExitCode(0);

    // The flip happened (EnvWriter recorded it), and migrate only ran after
    // the wizard reached the migration step — the flip step precedes it, so
    // migrate is the last process before the doctor.
    expect($env->writes)->toBe([['STATAMIC_MCP_AUTH', 'oauth']])
        ->and($commands)->toBe([
            'php artisan vendor:publish --tag=passport-migrations',
            'php artisan migrate',
            'php please mcp:doctor',
        ]);
});

it('rejects --oauth combined with --token', function () {
    $this->artisan('statamic:mcp:setup', ['--oauth' => true, '--token' => true])
        ->expectsOutputToContain('not both')
        ->assertExitCode(1);
});

it('stops when the user declines the passport install, before anything else runs', function () {
    Process::fake();
    fakeEnvWriter();
    stubOAuthPrereqs();

    $this->artisan('statamic:mcp:setup')
        ->expectsChoice('How will AI clients connect to this site?', 'oauth', MODE_OPTIONS)
        ->expectsConfirmation('Install laravel/passport via composer now?', 'no')
        ->expectsOutputToContain('Setup stopped.')
        ->assertExitCode(1);

    Process::assertDidntRun('composer require laravel/passport');
    Process::assertDidntRun('php please mcp:doctor');
});

it('stops when an external command fails, before any later step runs', function () {
    Process::fake([
        'composer require laravel/passport' => Process::result(errorOutput: 'boom', exitCode: 1),
        '*' => Process::result(),
    ]);
    fakeEnvWriter();
    stubOAuthPrereqs();

    $this->artisan('statamic:mcp:setup')
        ->expectsChoice('How will AI clients connect to this site?', 'oauth', MODE_OPTIONS)
        ->expectsConfirmation('Install laravel/passport via composer now?', 'yes')
        ->expectsOutputToContain("'composer require laravel/passport' failed (exit 1).")
        ->expectsOutputToContain('Setup stopped.')
        ->assertExitCode(1);

    Process::assertDidntRun('php artisan passport:keys');
});

it('exits non-zero when the final doctor run finds problems', function () {
    Process::fake([
        'php please mcp:doctor' => Process::result(exitCode: 1),
        '*' => Process::result(),
    ]);
    fakeEnvWriter();
    stubOAuthPrereqs(['installed' => true, 'keys' => true, 'tables' => true, 'columns' => true]);

    config(['statamic.mcp.auth' => 'oauth']);

    $this->artisan('statamic:mcp:setup', ['--oauth' => true, '--yes' => true])
        ->expectsOutputToContain('The doctor found problems')
        ->assertExitCode(1);
});
