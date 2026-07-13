<?php

use Danielgnh\StatamicMcp\Setup\AuthGuardEditor;
use Danielgnh\StatamicMcp\Setup\EditResult;
use Danielgnh\StatamicMcp\Setup\EnvWriter;
use Danielgnh\StatamicMcp\Setup\UserModelEditor;
use Danielgnh\StatamicMcp\Setup\UsersRepositoryEditor;
use Danielgnh\StatamicMcp\Support\OAuthPrerequisites;
use Illuminate\Support\Facades\Process;

// A real class so ReflectionClass can resolve a file path for the model step.
class SetupWizardTestUser {}

function fakeEditors(): array
{
    $fakes = [
        UsersRepositoryEditor::class => new class extends UsersRepositoryEditor
        {
            public array $applied = [];

            public function apply(string $path, string $repository = 'eloquent'): EditResult
            {
                $this->applied[] = [$path, $repository];

                return EditResult::Applied;
            }
        },
        AuthGuardEditor::class => new class extends AuthGuardEditor
        {
            public array $applied = [];

            public function apply(string $path): EditResult
            {
                $this->applied[] = $path;

                return EditResult::Applied;
            }
        },
        UserModelEditor::class => new class extends UserModelEditor
        {
            public array $applied = [];

            public function apply(string $path, ?string $interface): EditResult
            {
                $this->applied[] = [$path, $interface];

                return EditResult::Applied;
            }
        },
        EnvWriter::class => new class extends EnvWriter
        {
            public array $writes = [];

            public function apply(string $path, string $key, string $value): EditResult
            {
                $this->writes[] = [$key, $value];

                return EditResult::Applied;
            }
        },
    ];

    foreach ($fakes as $abstract => $fake) {
        app()->instance($abstract, $fake);
    }

    return $fakes;
}

function freshInstallPrereqs(): void
{
    app()->instance(OAuthPrerequisites::class, new class extends OAuthPrerequisites
    {
        public function usersAreEloquent(): bool
        {
            return false;
        }

        // A fresh install that HAS done the UUID homework: HasUuids on the
        // model, uuid users.id, and the import lands rows. The schema-refusal
        // and empty-import tests override these one at a time.
        public function importModelHasUuids(): bool
        {
            return true;
        }

        public function usersIdColumnAcceptsUuids(): bool
        {
            return true;
        }

        public function eloquentUsersExist(): bool
        {
            return true;
        }

        public function passportInstalled(): bool
        {
            return false;
        }

        public function apiGuardIsPassport(): bool
        {
            return false;
        }

        public function passportKeysExist(): bool
        {
            return false;
        }

        public function userModel(): string
        {
            return SetupWizardTestUser::class;
        }

        public function userModelHasTrait(): bool
        {
            return false;
        }
    });
}

const MODE_OPTIONS = [
    'token' => 'Token — Claude Code, Cursor, MCP Inspector',
    'oauth' => 'OAuth — claude.ai, Claude Desktop, ChatGPT connectors',
];

it('walks a fresh install through every oauth step', function () {
    Process::fake();
    $fakes = fakeEditors();
    freshInstallPrereqs();

    $modelPath = (new ReflectionClass(SetupWizardTestUser::class))->getFileName();

    $this->artisan('statamic:mcp:setup')
        ->expectsChoice('How will AI clients connect to this site?', 'oauth', MODE_OPTIONS)
        // Step 1: users → database
        ->expectsConfirmation('Migrate users to the database now?', 'yes')
        ->expectsConfirmation('Apply this change to '.config_path('statamic/users.php').'?', 'yes')
        // Step 2: passport
        ->expectsConfirmation('Install laravel/passport via composer now?', 'yes')
        // Step 3: plumbing
        ->expectsConfirmation('Publish Passport migrations, run them, and generate encryption keys?', 'yes')
        // Step 4: user model
        ->expectsConfirmation('Apply this change to '.$modelPath.'?', 'yes')
        // Step 5: api guard
        ->expectsConfirmation('Apply this change to '.config_path('auth.php').'?', 'yes')
        // Step 6: consent views (optional — decline)
        ->expectsConfirmation('Publish the OAuth consent screen to customize it? (a working default is already bound)', 'no')
        // Step 7: env flip
        ->expectsConfirmation('Apply this change to '.base_path('.env').'?', 'yes')
        ->assertExitCode(0);

    Process::assertRan('php please auth:migration');
    Process::assertRan('php artisan migrate');
    Process::assertRan('php please eloquent:import-users');
    Process::assertRan('composer require laravel/passport');
    Process::assertRan('php artisan vendor:publish --tag=passport-migrations');
    Process::assertRan('php artisan passport:keys');
    Process::assertRan('php please mcp:doctor');

    // The wizard resolves the OAuthenticatable FQCN from the environment: in
    // the main CI legs Passport is absent (null, the fresh-install branch);
    // the Passport CI leg detects the real interface. Mirror that split.
    $oauthenticatable = interface_exists('Laravel\Passport\Contracts\OAuthenticatable')
        ? 'Laravel\Passport\Contracts\OAuthenticatable'
        : null;

    expect($fakes[UsersRepositoryEditor::class]->applied)->toBe([[config_path('statamic/users.php'), 'eloquent']])
        ->and($fakes[AuthGuardEditor::class]->applied)->toBe([config_path('auth.php')])
        ->and($fakes[UserModelEditor::class]->applied)->toBe([[$modelPath, $oauthenticatable]])
        ->and($fakes[EnvWriter::class]->writes)->toBe([['STATAMIC_MCP_AUTH', 'oauth']]);
});

it('skips every oauth step on an already-configured install', function () {
    Process::fake();
    $fakes = fakeEditors();

    app()->instance(OAuthPrerequisites::class, new class extends OAuthPrerequisites
    {
        public function usersAreEloquent(): bool
        {
            return true;
        }

        public function passportInstalled(): bool
        {
            return true;
        }

        public function apiGuardIsPassport(): bool
        {
            return true;
        }

        public function passportKeysExist(): bool
        {
            return true;
        }

        public function userModel(): string
        {
            return SetupWizardTestUser::class;
        }

        public function userModelHasTrait(): bool
        {
            return true;
        }
    });

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

    expect($fakes[UsersRepositoryEditor::class]->applied)->toBe([])
        ->and($fakes[AuthGuardEditor::class]->applied)->toBe([])
        ->and($fakes[UserModelEditor::class]->applied)->toBe([])
        ->and($fakes[EnvWriter::class]->writes)->toBe([]);
});

it('runs every oauth step unattended with --oauth --yes --migrate-users', function () {
    Process::fake();
    $fakes = fakeEditors();
    freshInstallPrereqs();

    $modelPath = (new ReflectionClass(SetupWizardTestUser::class))->getFileName();

    $this->artisan('statamic:mcp:setup', ['--oauth' => true, '--yes' => true, '--migrate-users' => true])
        ->assertExitCode(0);

    Process::assertRan('php please auth:migration');
    Process::assertRan('php artisan migrate');
    Process::assertRan('php please eloquent:import-users');
    Process::assertRan('composer require laravel/passport');
    Process::assertRan('php artisan passport:keys');
    Process::assertRan('php please mcp:doctor');
    // The optional consent views publish defaults to "no" — --yes keeps that.
    Process::assertDidntRun('php artisan vendor:publish --tag=statamic-mcp-views');

    $oauthenticatable = interface_exists('Laravel\Passport\Contracts\OAuthenticatable')
        ? 'Laravel\Passport\Contracts\OAuthenticatable'
        : null;

    expect($fakes[UsersRepositoryEditor::class]->applied)->toBe([[config_path('statamic/users.php'), 'eloquent']])
        ->and($fakes[AuthGuardEditor::class]->applied)->toBe([config_path('auth.php')])
        ->and($fakes[UserModelEditor::class]->applied)->toBe([[$modelPath, $oauthenticatable]])
        ->and($fakes[EnvWriter::class]->writes)->toBe([['STATAMIC_MCP_AUTH', 'oauth']]);
});

it('refuses to migrate file users under --yes without --migrate-users', function () {
    Process::fake();
    fakeEditors();
    freshInstallPrereqs();

    $this->artisan('statamic:mcp:setup', ['--oauth' => true, '--yes' => true])
        ->expectsOutputToContain('Re-run with --migrate-users')
        ->expectsOutputToContain('Setup stopped.')
        ->assertExitCode(1);

    Process::assertDidntRun('php please auth:migration');
    Process::assertDidntRun('composer require laravel/passport');
});

it('needs no --migrate-users under --yes when users are already eloquent', function () {
    Process::fake();
    $fakes = fakeEditors();

    app()->instance(OAuthPrerequisites::class, new class extends OAuthPrerequisites
    {
        public function usersAreEloquent(): bool
        {
            return true;
        }

        public function passportInstalled(): bool
        {
            return false;
        }

        public function apiGuardIsPassport(): bool
        {
            return false;
        }

        public function passportKeysExist(): bool
        {
            return false;
        }

        public function userModel(): string
        {
            return SetupWizardTestUser::class;
        }

        public function userModelHasTrait(): bool
        {
            return false;
        }
    });

    $this->artisan('statamic:mcp:setup', ['--oauth' => true, '--yes' => true])
        ->assertExitCode(0);

    Process::assertDidntRun('php please auth:migration');
    Process::assertRan('composer require laravel/passport');

    expect($fakes[UsersRepositoryEditor::class]->applied)->toBe([])
        ->and($fakes[EnvWriter::class]->writes)->toBe([['STATAMIC_MCP_AUTH', 'oauth']]);
});

it('skips the auth migration when the users table is already migrated', function () {
    Process::fake();
    $fakes = fakeEditors();

    // Columns already present (a half-finished earlier run or a manual setup),
    // but config still names the file repository.
    app()->instance(OAuthPrerequisites::class, new class extends OAuthPrerequisites
    {
        public function usersAreEloquent(): bool
        {
            return false;
        }

        public function usersTableMigrated(): bool
        {
            return true;
        }

        public function importModelHasUuids(): bool
        {
            return true;
        }

        public function usersIdColumnAcceptsUuids(): bool
        {
            return true;
        }

        public function eloquentUsersExist(): bool
        {
            return true;
        }

        public function passportInstalled(): bool
        {
            return true;
        }

        public function apiGuardIsPassport(): bool
        {
            return true;
        }

        public function passportKeysExist(): bool
        {
            return true;
        }

        public function userModel(): string
        {
            return SetupWizardTestUser::class;
        }

        public function userModelHasTrait(): bool
        {
            return true;
        }
    });

    $this->artisan('statamic:mcp:setup', ['--oauth' => true, '--yes' => true, '--migrate-users' => true])
        ->expectsOutputToContain('already migrated')
        ->assertExitCode(0);

    // The crash-causing pair never runs a second time...
    Process::assertDidntRun('php please auth:migration');
    Process::assertDidntRun('php artisan migrate');
    // ...but the repo flip and import still complete the switch to database users.
    Process::assertRan('php please eloquent:import-users');

    expect($fakes[UsersRepositoryEditor::class]->applied)->toBe([[config_path('statamic/users.php'), 'eloquent']]);
});

it('refuses the user migration when the schema cannot take uuid ids, naming the exact fix', function () {
    Process::fake();
    fakeEditors();

    // The stock-Laravel trap: file users about to migrate, but the model has
    // no HasUuids and users.id is a bigint — eloquent:import-users would
    // print an error and exit 0 anyway. The wizard must refuse BEFORE any
    // migration or prompt, and hand the operator (or their agent) the
    // conversion steps.
    app()->instance(OAuthPrerequisites::class, new class extends OAuthPrerequisites
    {
        public function usersAreEloquent(): bool
        {
            return false;
        }

        public function importModelHasUuids(): bool
        {
            return false;
        }

        public function usersIdColumnAcceptsUuids(): bool
        {
            return false;
        }

        public function usersIdColumnType(): string
        {
            return 'bigint';
        }

        public function importUserModel(): string
        {
            return SetupWizardTestUser::class;
        }
    });

    // Substrings are deliberately non-overlapping across output blocks:
    // expectsOutputToContain consumes one write per expectation, so a
    // substring appearing in two blocks would shadow a later expectation.
    $this->artisan('statamic:mcp:setup', ['--oauth' => true, '--yes' => true, '--migrate-users' => true])
        ->expectsOutputToContain('keyed by UUID')
        ->expectsOutputToContain('is missing the')
        ->expectsOutputToContain("id column is 'bigint'")
        ->expectsOutputToContain("uuid('id')->primary()")
        ->expectsOutputToContain('Setup stopped.')
        ->assertExitCode(1);

    // Nothing destructive ran: no migration generated, no repository flip.
    Process::assertDidntRun('php please auth:migration');
    Process::assertDidntRun('php artisan migrate');
    Process::assertDidntRun('php please eloquent:import-users');
});

it('reverts the repository flip when the import lands no users', function () {
    Process::fake();
    $fakes = fakeEditors();

    // eloquent:import-users exits 0 even when it imports nothing — the
    // wizard must verify rows landed and put the file repository back,
    // otherwise CP login reads an empty table and everyone is locked out.
    app()->instance(OAuthPrerequisites::class, new class extends OAuthPrerequisites
    {
        public function usersAreEloquent(): bool
        {
            return false;
        }

        public function importModelHasUuids(): bool
        {
            return true;
        }

        public function usersIdColumnAcceptsUuids(): bool
        {
            return true;
        }

        public function eloquentUsersExist(): bool
        {
            return false;
        }
    });

    $this->artisan('statamic:mcp:setup', ['--oauth' => true, '--yes' => true, '--migrate-users' => true])
        ->expectsOutputToContain('no users landed in the database')
        ->expectsOutputToContain("Reverted 'repository' => 'file'")
        ->expectsOutputToContain('Setup stopped.')
        ->assertExitCode(1);

    Process::assertRan('php please eloquent:import-users');
    // No later step ran on the broken state.
    Process::assertDidntRun('composer require laravel/passport');

    // Flip, then revert — in that order.
    expect($fakes[UsersRepositoryEditor::class]->applied)->toBe([
        [config_path('statamic/users.php'), 'eloquent'],
        [config_path('statamic/users.php'), 'file'],
    ]);
});

it('rejects --oauth combined with --token', function () {
    $this->artisan('statamic:mcp:setup', ['--oauth' => true, '--token' => true])
        ->expectsOutputToContain('not both')
        ->assertExitCode(1);
});

it('stops before composer when the user declines the users migration', function () {
    Process::fake();
    fakeEditors();
    freshInstallPrereqs();

    $this->artisan('statamic:mcp:setup')
        ->expectsChoice('How will AI clients connect to this site?', 'oauth', MODE_OPTIONS)
        ->expectsConfirmation('Migrate users to the database now?', 'no')
        ->expectsOutputToContain('Setup stopped.')
        ->assertExitCode(1);

    Process::assertDidntRun('composer require laravel/passport');
    Process::assertDidntRun('php please mcp:doctor');
});

it('stops when an external command fails, before any later step runs', function () {
    Process::fake([
        'php please auth:migration' => Process::result(errorOutput: 'boom', exitCode: 1),
        '*' => Process::result(),
    ]);
    fakeEditors();
    freshInstallPrereqs();

    $this->artisan('statamic:mcp:setup')
        ->expectsChoice('How will AI clients connect to this site?', 'oauth', MODE_OPTIONS)
        ->expectsConfirmation('Migrate users to the database now?', 'yes')
        ->expectsOutputToContain("'php please auth:migration' failed (exit 1).")
        ->expectsOutputToContain('Setup stopped.')
        ->assertExitCode(1);

    Process::assertDidntRun('composer require laravel/passport');
});

it('exits non-zero when the final doctor run finds problems', function () {
    Process::fake([
        'php please mcp:doctor' => Process::result(exitCode: 1),
        '*' => Process::result(),
    ]);
    fakeEditors();
    freshInstallPrereqs();

    $modelPath = (new ReflectionClass(SetupWizardTestUser::class))->getFileName();

    $this->artisan('statamic:mcp:setup')
        ->expectsChoice('How will AI clients connect to this site?', 'oauth', MODE_OPTIONS)
        ->expectsConfirmation('Migrate users to the database now?', 'yes')
        ->expectsConfirmation('Apply this change to '.config_path('statamic/users.php').'?', 'yes')
        ->expectsConfirmation('Install laravel/passport via composer now?', 'yes')
        ->expectsConfirmation('Publish Passport migrations, run them, and generate encryption keys?', 'yes')
        ->expectsConfirmation('Apply this change to '.$modelPath.'?', 'yes')
        ->expectsConfirmation('Apply this change to '.config_path('auth.php').'?', 'yes')
        ->expectsConfirmation('Publish the OAuth consent screen to customize it? (a working default is already bound)', 'no')
        ->expectsConfirmation('Apply this change to '.base_path('.env').'?', 'yes')
        ->expectsOutputToContain('The doctor found problems')
        ->assertExitCode(1);
});
