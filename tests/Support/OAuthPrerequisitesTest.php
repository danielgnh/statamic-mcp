<?php

use Danielgnh\StatamicMcp\Support\OAuthPrerequisites;

it('resolves the users driver through the configured repository', function () {
    config(['statamic.users.repository' => 'custom']);
    config(['statamic.users.repositories.custom.driver' => 'file']);

    $prereqs = new OAuthPrerequisites;

    expect($prereqs->usersRepository())->toBe('custom')
        ->and($prereqs->usersDriver())->toBe('file')
        ->and($prereqs->usersAreEloquent())->toBeFalse();
});

it('recognizes eloquent users regardless of the repository name', function () {
    config(['statamic.users.repository' => 'anything']);
    config(['statamic.users.repositories.anything.driver' => 'eloquent']);

    expect((new OAuthPrerequisites)->usersAreEloquent())->toBeTrue();
});

it('returns null for the users driver when the repository is not defined', function () {
    config(['statamic.users.repository' => 'ghost']);
    config(['statamic.users.repositories' => []]);

    expect((new OAuthPrerequisites)->usersDriver())->toBeNull();
});

it('reports the api guard state', function () {
    config(['auth.guards.api' => null]);

    $prereqs = new OAuthPrerequisites;

    expect($prereqs->apiGuardDefined())->toBeFalse()
        ->and($prereqs->apiGuardIsPassport())->toBeFalse();

    config(['auth.guards.api' => ['driver' => 'session', 'provider' => 'users']]);

    expect($prereqs->apiGuardDefined())->toBeTrue()
        ->and($prereqs->apiGuardDriver())->toBe('session')
        ->and($prereqs->apiGuardIsPassport())->toBeFalse();

    config(['auth.guards.api' => ['driver' => 'passport', 'provider' => 'users']]);

    expect((new OAuthPrerequisites)->apiGuardIsPassport())->toBeTrue();
});

it('treats an empty api guard definition as undefined', function () {
    config(['auth.guards.api' => []]);

    expect((new OAuthPrerequisites)->apiGuardDefined())->toBeFalse();
});

it('resolves the user model from the api guard provider', function () {
    config(['auth.guards.api' => ['driver' => 'passport', 'provider' => 'special']]);
    config(['auth.providers.special.model' => 'App\Models\SpecialUser']);

    expect((new OAuthPrerequisites)->userModel())->toBe('App\Models\SpecialUser');
});

it('falls back to the users provider when the api guard names none', function () {
    config(['auth.guards.api' => null]);
    config(['auth.providers.users.model' => 'App\Models\User']);

    expect((new OAuthPrerequisites)->userModel())->toBe('App\Models\User');
});

// Passport is not in require-dev, so in this suite these are always false —
// which is exactly the branch a fresh host site exercises.
it('reports passport as absent in a suite without passport', function () {
    $prereqs = new OAuthPrerequisites;

    expect($prereqs->passportInstalled())->toBeFalse()
        ->and($prereqs->passportKeysExist())->toBeFalse()
        ->and($prereqs->userModelHasTrait())->toBeFalse();
});
