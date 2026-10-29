<?php

declare(strict_types=1);

namespace Danielgnh\StatamicMcp\Tests\Support;

use Illuminate\Foundation\Auth\User as AuthUser;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;

class HostApiUser extends AuthUser implements OAuthenticatable
{
    use HasApiTokens;

    protected $table = 'customers';

    protected $guarded = [];
}
