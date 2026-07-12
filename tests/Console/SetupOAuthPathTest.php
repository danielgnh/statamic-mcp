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

            public function apply(string $path): EditResult
            {
                $this->applied[] = $path;

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

        public function userModel(): ?string
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
        ->expectsConfirmation('Publish the OAuth consent screen views (customizable Blade)?', 'no')
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

    expect($fakes[UsersRepositoryEditor::class]->applied)->toBe([config_path('statamic/users.php')])
        ->and($fakes[AuthGuardEditor::class]->applied)->toBe([config_path('auth.php')])
        ->and($fakes[UserModelEditor::class]->applied)->toBe([[$modelPath, null]])
        ->and($fakes[EnvWriter::class]->writes)->toBe([['STATAMIC_MCP_AUTH', 'oauth']]);
});
