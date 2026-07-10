<?php

namespace Danielgnh\StatamicMcp\Tests\Support;

use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Simulates what Passport hands laravel/mcp in oauth mode: an Eloquent
 * Authenticatable, NOT a Statamic user. Never persisted or queried.
 */
class FakeEloquentUser extends AuthUser
{
    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';
}
