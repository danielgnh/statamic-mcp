<?php

namespace Danielgnh\StatamicMcp\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Facades\User;

class ActingUser
{
    /**
     * Normalize the framework-authenticated user to a Statamic user,
     * mode-agnostic: under Passport the request user is the Eloquent model,
     * under token auth it is already a Statamic user — fromUser() collapses
     * both. Null when the request is unauthenticated (stdio/inspector) or the
     * Eloquent user has no Statamic representation.
     */
    public static function resolve(?Authenticatable $authenticated): ?UserContract
    {
        return $authenticated ? User::fromUser($authenticated) : null;
    }
}
