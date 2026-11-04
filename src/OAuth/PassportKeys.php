<?php

namespace Danielgnh\StatamicMcp\OAuth;

use Laravel\Passport\Passport;

class PassportKeys
{
    public function __construct(protected KeyStore $store) {}

    public function inject(): void
    {
        if (filled(config('passport.private_key')) || filled(config('passport.public_key'))) {
            return;
        }

        if ($pair = $this->resolve()) {
            config([
                'passport.private_key' => $pair['private'],
                'passport.public_key' => $pair['public'],
            ]);
        }
    }

    /**
     * @return array{private: string, public: string, source: string}|null
     */
    public function resolve(): ?array
    {
        if ($this->store->undecryptable()) {
            return null;
        }

        if ($private = $this->store->get()) {
            $public = $this->store->publicKeyFor($private);

            return $public === null ? null : ['private' => $private, 'public' => $public, 'source' => 'database'];
        }

        return $this->fromFiles() ?? $this->provision();
    }

    /**
     * @return array{private: string, public: string, source: string}|null
     */
    protected function fromFiles(): ?array
    {
        $privatePath = Passport::keyPath('oauth-private.key');
        $publicPath = Passport::keyPath('oauth-public.key');

        if (! file_exists($privatePath) || ! file_exists($publicPath)) {
            return null;
        }

        $private = trim((string) file_get_contents($privatePath));
        $public = trim((string) file_get_contents($publicPath));

        if ($this->store->available() && $this->store->publicKeyFor($private) !== null) {
            $this->store->put($private);

            if ($stored = $this->store->get()) {
                return [
                    'private' => $stored,
                    'public' => (string) $this->store->publicKeyFor($stored),
                    'source' => 'files',
                ];
            }
        }

        return ['private' => $private, 'public' => $public, 'source' => 'files'];
    }

    /**
     * @return array{private: string, public: string, source: string}|null
     */
    protected function provision(): ?array
    {
        if (! $this->store->available() || ! $private = $this->generate()) {
            return null;
        }

        $this->store->put($private);

        $stored = $this->store->get();
        $public = $stored === null ? null : $this->store->publicKeyFor($stored);

        return $stored !== null && $public !== null
            ? ['private' => $stored, 'public' => $public, 'source' => 'generated']
            : null;
    }

    public function generate(): ?string
    {
        $rsa = openssl_pkey_new(['private_key_bits' => 4096, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

        if ($rsa === false || ! openssl_pkey_export($rsa, $private)) {
            return null;
        }

        return trim((string) $private);
    }
}
