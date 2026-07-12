<?php

namespace Danielgnh\StatamicMcp\Support;

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

    public function passportInstalled(): bool
    {
        return class_exists(Passport::class);
    }

    public function apiGuardDefined(): bool
    {
        return config('auth.guards.api') !== null;
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
        $provider = config('auth.guards.api.provider') ?? 'users';

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
