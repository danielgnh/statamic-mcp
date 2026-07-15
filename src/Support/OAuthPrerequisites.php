<?php

namespace Danielgnh\StatamicMcp\Support;

use Danielgnh\StatamicMcp\OAuth\KeyStore;
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
    public function __construct(protected KeyStore $keys = new KeyStore) {}

    public function passportInstalled(): bool
    {
        return class_exists(Passport::class);
    }

    /**
     * Both keys must be available — the private key signs at issuance, the
     * public key verifies at every request. keySource() mirrors the runtime
     * precedence exactly, including "the store's table exists and a pair will
     * self-provision on first use".
     */
    public function passportKeysExist(): bool
    {
        return $this->passportInstalled()
            && $this->keySource() !== null;
    }

    /**
     * Where the runtime gets its keys — the exact mirror of
     * PassportKeys::inject(): explicit config short-circuits everything, an
     * undecryptable store row blocks the database path (Passport then falls
     * back to key files natively), a stored key beats files, and an empty
     * store table means a pair self-provisions on first use.
     *
     * @return 'config'|'database'|'files'|'provisionable'|null
     */
    public function keySource(): ?string
    {
        // Either config key set → the addon's injection steps aside and
        // Passport reads config-or-file per key, exactly as stock.
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

    /**
     * A stored key APP_KEY can no longer decrypt. Deliberately its own
     * predicate: "missing" remedies regenerate, which would 401 every
     * connected client over a key that restoring APP_KEY could still save.
     */
    public function passportKeysUndecryptable(): bool
    {
        return blank(config('passport.private_key'))
            && $this->keys->undecryptable();
    }

    /**
     * Whether the addon's own key table exists — distinct from
     * oauthTablesMigrated() because an upgrading site has Passport's tables
     * already but still needs one `php artisan migrate` for this one.
     */
    public function keyStoreMigrated(): bool
    {
        return $this->keys->available();
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
