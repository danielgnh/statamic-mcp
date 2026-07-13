<?php

use Danielgnh\StatamicMcp\Support\OAuthPrerequisites;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Passport\Passport;

class PrereqsUuidUser
{
    use HasUuids;
}

class PrereqsPlainUser {}

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

it('resolves the import model through the cp guard, not the api guard', function () {
    // eloquent:import-users writes through the CP guard's provider — a site
    // can point the api guard at a different model entirely.
    config([
        'statamic.users.guards.cp' => 'special',
        'auth.guards.special' => ['driver' => 'session', 'provider' => 'cp_users'],
        'auth.providers.cp_users.model' => PrereqsUuidUser::class,
        'auth.guards.api' => ['driver' => 'passport', 'provider' => 'users'],
        'auth.providers.users.model' => PrereqsPlainUser::class,
    ]);

    $prereqs = new OAuthPrerequisites;

    expect($prereqs->importUserModel())->toBe(PrereqsUuidUser::class)
        ->and($prereqs->importModelHasUuids())->toBeTrue()
        ->and($prereqs->userModel())->toBe(PrereqsPlainUser::class);
});

it('detects a missing HasUuids trait on the import model', function () {
    config([
        'statamic.users.guards.cp' => 'web',
        'auth.guards.web' => ['driver' => 'session', 'provider' => 'users'],
        'auth.providers.users.model' => PrereqsPlainUser::class,
    ]);

    expect((new OAuthPrerequisites)->importModelHasUuids())->toBeFalse();
});

it('rejects an integer users id column and accepts a uuid one', function () {
    $prereqs = new OAuthPrerequisites;

    // No table at all: whatever creates it later creates it bigint.
    expect($prereqs->usersIdColumnAcceptsUuids())->toBeFalse()
        ->and($prereqs->usersIdColumnType())->toBeNull();

    // Laravel's stock users table shape — the schema that dooms the import.
    Schema::create('users', function ($table) {
        $table->id();
        $table->string('email');
    });

    expect($prereqs->usersIdColumnAcceptsUuids())->toBeFalse()
        ->and($prereqs->usersIdColumnType())->not->toBeNull();

    Schema::drop('users');

    Schema::create('users', function ($table) {
        $table->uuid('id')->primary();
        $table->string('email');
    });

    expect($prereqs->usersIdColumnAcceptsUuids())->toBeTrue();

    Schema::drop('users');
});

it('reports whether any eloquent users exist', function () {
    $prereqs = new OAuthPrerequisites;

    // Missing table reports false instead of throwing.
    expect($prereqs->eloquentUsersExist())->toBeFalse();

    Schema::create('users', function ($table) {
        $table->uuid('id')->primary();
        $table->string('email');
    });

    expect($prereqs->eloquentUsersExist())->toBeFalse();

    DB::table('users')->insert(['id' => 'ae0bbcf0-1d75-4f50-a1e3-6f4c3e9f0000', 'email' => 'user@site.com']);

    expect($prereqs->eloquentUsersExist())->toBeTrue();

    Schema::drop('users');
});

// Passport is not in require-dev, so in the main CI legs these are always
// false — exactly the branch a fresh host site exercises. The Passport CI
// leg installs the real package, so there this test skips instead.
it('reports passport as absent in a suite without passport', function () {
    $prereqs = new OAuthPrerequisites;

    expect($prereqs->passportInstalled())->toBeFalse()
        ->and($prereqs->passportKeysExist())->toBeFalse()
        ->and($prereqs->userModelHasTrait())->toBeFalse()
        // No Passport means no view binding can exist — and the predicate must
        // short-circuit on passportInstalled() rather than touch the absent
        // contract class.
        ->and($prereqs->authorizationViewBound())->toBeFalse();
})->skip(fn () => class_exists(Passport::class), 'asserts Passport absence — skipped in the Passport CI leg');

// The inverse, in the Passport CI leg: the predicate tracks whether a consent
// view is actually bound (the addon binds one in oauth mode; here we bind it
// explicitly since this suite boots in token mode).
it('reports the authorization view as bound once a view is registered', function () {
    $prereqs = new OAuthPrerequisites;

    expect($prereqs->authorizationViewBound())->toBeFalse();

    Passport::authorizationView('statamic-mcp::oauth.authorize');

    expect($prereqs->authorizationViewBound())->toBeTrue();
})->skip(fn () => ! class_exists(Passport::class), 'requires laravel/passport — Passport CI leg only');
