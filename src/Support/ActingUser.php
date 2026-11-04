<?php

namespace Danielgnh\StatamicMcp\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Facades\User;

class ActingUser
{
    public static function resolve(?Authenticatable $authenticated): ?UserContract
    {
        return $authenticated ? User::fromUser($authenticated) : null;
    }
}
