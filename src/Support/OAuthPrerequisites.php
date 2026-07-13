<?php

namespace Danielgnh\StatamicMcp\Support;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Passport\Passport;

/**
 * Single source of truth for every OAuth-mode prerequisite. Doctor (diagnosis),
 * AuthenticateOAuth (runtime preflight), and Setup (installer) all answer from
 * these predicates, so what gets checked, enforced, and fixed can never drift.
 */
class OAuthPrerequisites
{
    public function usersRepository(): string
    {
        return config('statamic.users.repository', 'file');
    }

    /**
     * The repository NAME is arbitrary — what matters is the driver it
     * resolves to (a 'custom' repository may still be file-driven).
     */
    public function usersDriver(): ?string
    {
        return config('statamic.users.repositories.'.$this->usersRepository().'.driver');
    }

    public function usersAreEloquent(): bool
    {
        return $this->usersDriver() === 'eloquent';
    }

    /**
     * Whether the Statamic auth columns are already on the users table.
     * statamic:auth:migration is not idempotent — it ADDs `super` with no
     * guard — so a second run dies with "Duplicate column 'super'". The
     * installer checks this to skip re-generating a migration that already
     * ran (a half-finished earlier run, or a hand-rolled Statamic setup).
     * DB unreachable? Report "not migrated" and let `migrate` surface the
     * real connection error itself.
     */
    public function usersTableMigrated(): bool
    {
        $table = config('statamic.users.tables.users', 'users');

        return rescue(
            fn () => Schema::hasTable($table) && Schema::hasColumn($table, 'super'),
            false,
            report: false,
        );
    }

    /**
     * The model eloquent:import-users will write through — resolved exactly
     * the way the importer resolves it (the CP guard's provider), which is
     * NOT necessarily the api guard's model that userModel() answers.
     */
    public function importUserModel(): ?string
    {
        $guard = config('statamic.users.guards.cp', 'web');
        $provider = config('auth.guards.'.$guard.'.provider', 'users');

        return config('auth.providers.'.$provider.'.model');
    }

    /**
     * eloquent:import-users preserves each file user's UUID id and refuses to
     * run (printing an error but still EXITING 0) unless the model uses
     * HasUuids — the trap an unchecked run falls into.
     */
    public function importModelHasUuids(): bool
    {
        $model = $this->importUserModel();

        return $model
            && class_exists($model)
            && in_array(HasUuids::class, class_uses_recursive($model), true);
    }

    public function usersIdColumnType(): ?string
    {
        $table = config('statamic.users.tables.users', 'users');

        return rescue(
            fn () => Schema::hasTable($table) ? Schema::getColumnType($table, 'id') : null,
            null,
            report: false,
        );
    }

    /**
     * A UUID can only land in a string-ish id column. Laravel's stock users
     * table has a bigint auto-increment id — and Statamic ships no migration
     * that converts it, so this is THE schema conflict that dooms the import
     * on a default install. Matching 'int' covers every integer type name
     * across drivers (bigint, integer, int8, tinyint, …); uuid/char/varchar
     * pass. A missing table or column also fails: whatever creates it later
     * (Statamic's stubs included) creates it bigint.
     */
    public function usersIdColumnAcceptsUuids(): bool
    {
        $type = $this->usersIdColumnType();

        return $type !== null && ! str_contains(strtolower($type), 'int');
    }

    /**
     * Whether any user rows actually exist in the database. The installer
     * checks this AFTER eloquent:import-users, because the importer exits 0
     * even when it refused to import — trusting its exit code is exactly how
     * a site ends up with 'repository' => 'eloquent' over an empty table and
     * nobody able to log in.
     */
    public function eloquentUsersExist(): bool
    {
        $table = config('statamic.users.tables.users', 'users');

        return rescue(
            fn () => DB::table($table)->exists(),
            false,
            report: false,
        );
    }

    public function passportInstalled(): bool
    {
        return class_exists(Passport::class);
    }

    public function apiGuardDefined(): bool
    {
        return (bool) config('auth.guards.api');
    }

    public function apiGuardDriver(): ?string
    {
        return config('auth.guards.api.driver');
    }

    public function apiGuardIsPassport(): bool
    {
        return $this->apiGuardDriver() === 'passport';
    }

    public function userModel(): ?string
    {
        $provider = config('auth.guards.api.provider', 'users');

        return config('auth.providers.'.$provider.'.model');
    }

    public function userModelHasTrait(): bool
    {
        $model = $this->userModel();

        return $model
            && class_exists($model)
            && in_array('Laravel\\Passport\\HasApiTokens', class_uses_recursive($model), true);
    }

    public function passportKeysExist(): bool
    {
        return $this->passportInstalled()
            && file_exists(Passport::keyPath('oauth-private.key'));
    }
}
