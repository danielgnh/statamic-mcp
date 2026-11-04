<?php

namespace Danielgnh\StatamicMcp\Support;

use Danielgnh\StatamicMcp\OAuth\KeyStore;
use Illuminate\Database\SQLiteDatabaseDoesNotExistException;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Laravel\Passport\Contracts\AuthorizationViewResponse;
use Laravel\Passport\Passport;
use PDOException;

class OAuthPrerequisites
{
    public function __construct(protected KeyStore $keys = new KeyStore) {}

    public function passportInstalled(): bool
    {
        return class_exists(Passport::class);
    }

    public function databaseFailure(): PDOException|InvalidArgumentException|null
    {
        try {
            $this->keys->tableExists();

            return null;
        } catch (PDOException|InvalidArgumentException $e) {
            return $e->getPrevious() instanceof SQLiteDatabaseDoesNotExistException ? null : $e;
        }
    }

    public function passportKeysExist(): bool
    {
        return $this->passportInstalled()
            && $this->keySource() !== null;
    }

    /**
     * @return 'config'|'database'|'files'|'provisionable'|null
     */
    public function keySource(): ?string
    {
        if (! $this->passportInstalled()) {
            return null;
        }

        if (filled(config('passport.private_key')) || filled(config('passport.public_key'))) {
            return $this->keyAvailable('private') && $this->keyAvailable('public') ? 'config' : null;
        }

        if (! $this->keys->undecryptable() && $this->keys->get() !== null) {
            return 'database';
        }

        if (file_exists(Passport::keyPath('oauth-private.key')) && file_exists(Passport::keyPath('oauth-public.key'))) {
            return 'files';
        }

        return $this->keys->available() && ! $this->keys->has() ? 'provisionable' : null;
    }

    public function passportKeysUndecryptable(): bool
    {
        return blank(config('passport.private_key'))
            && $this->keys->undecryptable();
    }

    public function keyStoreMigrated(): bool
    {
        return $this->keys->available();
    }

    protected function keyAvailable(string $type): bool
    {
        return filled(config("passport.{$type}_key"))
            || file_exists(Passport::keyPath("oauth-{$type}.key"));
    }

    public function oauthTablesMigrated(): bool
    {
        return rescue(
            fn () => Schema::hasTable('oauth_clients')
                && Schema::hasTable('oauth_auth_codes')
                && Schema::hasTable('oauth_access_tokens'),
            false,
            report: false,
        );
    }

    public function oauthUserIdColumnsFitStatamicIds(): bool
    {
        return rescue(
            fn () => ! str_contains(strtolower(Schema::getColumnType('oauth_auth_codes', 'user_id')), 'int')
                && ! str_contains(strtolower(Schema::getColumnType('oauth_access_tokens', 'user_id')), 'int'),
            false,
            report: false,
        );
    }

    public function authorizationViewBound(): bool
    {
        return $this->passportInstalled()
            && app()->bound(AuthorizationViewResponse::class);
    }
}
