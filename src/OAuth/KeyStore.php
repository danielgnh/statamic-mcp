<?php

namespace Danielgnh\StatamicMcp\OAuth;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Passport's signing keys as managed application state: one database row,
 * private key only, encrypted at rest with APP_KEY. The database is already
 * a hard prerequisite of OAuth mode (Passport's own tables), so keys stored
 * here survive releases, work on read-only filesystems, and are shared across
 * a fleet with zero deploy steps — no PEM blobs in the environment.
 *
 * put() never overwrites: regenerating a live pair silently 401s every
 * connected client, and a racing second server must lose to the first, not
 * rotate the fleet's keys. An undecryptable row (APP_KEY changed) is reported
 * as exactly that — never treated as absent, which would trigger a silent
 * regeneration over a key that might still be restorable.
 */
class KeyStore
{
    public const TABLE = 'statamic_mcp_oauth_keys';

    /** The fixed single-row primary key — see the migration. */
    protected const ROW_ID = 1;

    public function available(): bool
    {
        return rescue(fn () => Schema::hasTable(self::TABLE), false, report: false);
    }

    public function has(): bool
    {
        return $this->available() && $this->row() !== null;
    }

    /** The decrypted private PEM — null when absent or undecryptable. */
    public function get(): ?string
    {
        if (! $this->available() || ($cipher = $this->row()) === null) {
            return null;
        }

        return rescue(fn () => Crypt::decryptString($cipher), null, report: false);
    }

    /** A row exists but APP_KEY can no longer decrypt it. */
    public function undecryptable(): bool
    {
        if (! $this->available() || ($cipher = $this->row()) === null) {
            return false;
        }

        try {
            Crypt::decryptString($cipher);

            return false;
        } catch (DecryptException) {
            return true;
        }
    }

    /**
     * Store the pair unless one is already stored — first write wins, so read
     * back with get() for the authoritative key.
     */
    public function put(string $privatePem): void
    {
        DB::table(self::TABLE)->insertOrIgnore([
            'id' => self::ROW_ID,
            'private_key' => Crypt::encryptString(trim($privatePem)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * The public key is always derived from the private one — storing both
     * would be storing a divergence waiting to happen.
     */
    public function publicKeyFor(string $privatePem): ?string
    {
        $key = openssl_pkey_get_private($privatePem);
        $details = $key === false ? false : openssl_pkey_get_details($key);

        return $details === false ? null : trim($details['key']);
    }

    protected function row(): ?string
    {
        return DB::table(self::TABLE)->where('id', self::ROW_ID)->value('private_key');
    }
}
