<?php

namespace Danielgnh\StatamicMcp\OAuth;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class KeyStore
{
    public const TABLE = 'statamic_mcp_oauth_keys';

    protected const ROW_ID = 1;

    public function available(): bool
    {
        return rescue(fn () => $this->tableExists(), false, report: false);
    }

    public function tableExists(): bool
    {
        return Schema::hasTable(self::TABLE);
    }

    public function has(): bool
    {
        return $this->available() && $this->row() !== null;
    }

    public function get(): ?string
    {
        if (! $this->available() || ($cipher = $this->row()) === null) {
            return null;
        }

        try {
            return Crypt::decryptString($cipher);
        } catch (DecryptException) {
            return null;
        }
    }

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

    public function put(string $privatePem): void
    {
        DB::table(self::TABLE)->insertOrIgnore([
            'id' => self::ROW_ID,
            'private_key' => Crypt::encryptString(trim($privatePem)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

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
