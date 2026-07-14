<?php

namespace Danielgnh\StatamicMcp\Support;

use Illuminate\Support\Facades\Schema;
use Laravel\Passport\Contracts\AuthorizationViewResponse;
use Laravel\Passport\Passport;

/**
 * Single source of truth for every OAuth-mode prerequisite. Doctor (diagnosis),
 * AuthenticateOAuth (runtime preflight), and Setup (installer) all answer from
 * these predicates, so what gets checked, enforced, and fixed can never drift.
 *
 * OAuth mode works with file users AND Eloquent users: the addon's own guard
 * resolves the token's user through the Statamic repository, so no user
 * migration is ever required. What Passport does need is a database for its
 * OWN tables (clients, tokens) — with user_id columns wide enough for
 * Statamic's UUID ids, which the addon's migration provides.
 */
class OAuthPrerequisites
{
    public function passportInstalled(): bool
    {
        return class_exists(Passport::class);
    }

    /**
     * Both keys must be available — the private key signs at issuance, the
     * public key verifies at every request. Passport reads each from config
     * (PASSPORT_PRIVATE_KEY / PASSPORT_PUBLIC_KEY env — the deploy-friendly
     * path) before falling back to the key files passport:keys writes.
     */
    public function passportKeysExist(): bool
    {
        return $this->passportInstalled()
            && $this->keyAvailable('private')
            && $this->keyAvailable('public');
    }

    protected function keyAvailable(string $type): bool
    {
        return filled(config("passport.{$type}_key"))
            || file_exists(Passport::keyPath("oauth-{$type}.key"));
    }

    /**
     * Whether Passport's tables exist — the auth-code flow touches all three
     * on the first connector handshake. Missing tables mean `php artisan
     * migrate` hasn't run since Passport's migrations were published.
     */
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

    /**
     * Statamic ids are UUID strings (file users always; Eloquent users
     * whenever they were imported from files), but Passport's stock tables
     * give user_id a bigint column — the first consent would crash on insert.
     * The addon ships a migration converting the columns to string(36); this
     * detects a site that hasn't run it yet.
     */
    public function oauthUserIdColumnsFitStatamicIds(): bool
    {
        return rescue(
            fn () => ! str_contains(strtolower(Schema::getColumnType('oauth_auth_codes', 'user_id')), 'int')
                && ! str_contains(strtolower(Schema::getColumnType('oauth_access_tokens', 'user_id')), 'int'),
            false,
            report: false,
        );
    }

    /**
     * Whether an authorization (consent) view is bound. Passport 12+ ships no
     * default and never binds this contract, so /oauth/authorize 500s with
     * "Target [...AuthorizationViewResponse] is not instantiable" unless
     * someone calls Passport::authorizationView(). This addon binds a default
     * in OAuth mode, so the only way this is false is Passport-absent or the
     * addon's boot never ran — both of which the other checks already name.
     */
    public function authorizationViewBound(): bool
    {
        return $this->passportInstalled()
            && app()->bound(AuthorizationViewResponse::class);
    }
}
