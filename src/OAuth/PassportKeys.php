<?php

namespace Danielgnh\StatamicMcp\OAuth;

use Laravel\Passport\Passport;

/**
 * Resolves the Passport signing pair the addon manages, in the order the
 * runtime trusts it: explicit PASSPORT_* config always wins, then the
 * database store, then existing key files (adopted into the store so the
 * fleet converges on one copy), and only when nothing exists anywhere is a
 * fresh pair provisioned. Passport reads config('passport.*_key') lazily
 * while building its AuthorizationServer/ResourceServer singletons, so
 * inject() runs just in time via the service provider's beforeResolving
 * hooks — no deploy step, no PEM blobs in the environment.
 */
class PassportKeys
{
    public function __construct(protected KeyStore $store) {}

    /**
     * Feed the managed pair to Passport unless the environment already
     * provides one. Guarded on the config being blank, so it is idempotent
     * within a request and re-runs per request under Octane.
     */
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
     * Resolve-or-provision. Null means "nothing safe to serve": an
     * undecryptable row (APP_KEY changed — regenerating would 401 every
     * client while the original key might still be restorable), or no store
     * to provision into. Callers fall through to Passport's stock behavior
     * and the preflight/doctor name the remedy.
     *
     * @return array{private: string, public: string, source: string}|null
     */
    public function resolve(): ?array
    {
        if ($this->store->undecryptable()) {
            return null;
        }

        if ($private = $this->store->get()) {
            $public = $this->store->publicKeyFor($private);

            // A stored key the public half can't be derived from is garbage —
            // don't guess and don't regenerate over it.
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

        // Adopt the pair into the managed store so every server reads the
        // same copy from now on. put() never overwrites — when a racing
        // server stored first, ITS pair is the authoritative one.
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

        // Re-read: two servers provisioning simultaneously collide on the
        // store's primary key and both converge on the winner's pair.
        $stored = $this->store->get();
        $public = $stored === null ? null : $this->store->publicKeyFor($stored);

        return $stored !== null && $public !== null
            ? ['private' => $stored, 'public' => $public, 'source' => 'generated']
            : null;
    }

    /**
     * Same shape as `passport:keys`: a 4096-bit RSA private key. The public
     * half is always derived, never stored.
     */
    public function generate(): ?string
    {
        $rsa = openssl_pkey_new(['private_key_bits' => 4096, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

        if ($rsa === false || ! openssl_pkey_export($rsa, $private)) {
            return null;
        }

        return trim((string) $private);
    }
}
