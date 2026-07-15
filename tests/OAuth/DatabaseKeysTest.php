<?php

use Danielgnh\StatamicMcp\OAuth\KeyStore;
use Danielgnh\StatamicMcp\Tests\RegistersOAuthProbeRoute;
use Danielgnh\StatamicMcp\Tests\Support\OAuthFixtures;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\ResourceServer;

uses(RegistersOAuthProbeRoute::class);

/**
 * The runtime seam of database-managed keys: Passport reads
 * config('passport.*_key') lazily when its AuthorizationServer/ResourceServer
 * singletons build, so the addon injects the stored pair via a beforeResolving
 * hook — env config wins, existing key files are imported, and an empty store
 * self-provisions. No env pasting, no deploy step.
 */
beforeEach(function () {
    $this->keyDir = sys_get_temp_dir().'/mcp-dbkeys-test-'.Str::random(8);
    @mkdir($this->keyDir, 0700, true);

    Passport::loadKeysFrom($this->keyDir); // isolate the file fallback per test

    config(['passport.private_key' => null, 'passport.public_key' => null]);
});

afterEach(function () {
    array_map(unlink(...), glob($this->keyDir.'/*') ?: []);
    @rmdir($this->keyDir);
    Passport::$keyPath = null;
});

it('feeds the stored database key to Passport when the resource server resolves', function () {
    OAuthFixtures::migrateKeyStore();
    $pem = OAuthFixtures::rsaPrivateKey();
    (new KeyStore)->put($pem);

    app(ResourceServer::class);

    expect(config('passport.private_key'))->toBe($pem)
        ->and(config('passport.public_key'))->toContain('-----BEGIN PUBLIC KEY-----');
});

it('feeds the stored database key to Passport when the authorization server resolves', function () {
    OAuthFixtures::migrateKeyStore();
    $pem = OAuthFixtures::rsaPrivateKey();
    (new KeyStore)->put($pem);

    app(AuthorizationServer::class);

    expect(config('passport.private_key'))->toBe($pem);
});

it('leaves env-configured keys untouched and never reads the database', function () {
    OAuthFixtures::migrateKeyStore();
    config(['passport.private_key' => 'ENV PRIVATE', 'passport.public_key' => 'ENV PUBLIC']);

    rescue(fn () => app(ResourceServer::class), report: false); // fake PEM — construction may throw, injection already ran

    expect(config('passport.private_key'))->toBe('ENV PRIVATE')
        ->and(config('passport.public_key'))->toBe('ENV PUBLIC')
        ->and((new KeyStore)->has())->toBeFalse();
});

it('imports existing key files into the database and serves them', function () {
    OAuthFixtures::migrateKeyStore();
    $pem = OAuthFixtures::rsaPrivateKey();
    file_put_contents($this->keyDir.'/oauth-private.key', $pem);
    file_put_contents($this->keyDir.'/oauth-public.key', (new KeyStore)->publicKeyFor($pem));

    app(ResourceServer::class);

    // The fleet converges on the database copy; the files become redundant.
    expect((new KeyStore)->get())->toBe($pem)
        ->and(config('passport.private_key'))->toBe($pem);
});

it('self-provisions a fresh pair into the database when nothing exists anywhere', function () {
    OAuthFixtures::migrateKeyStore();

    app(ResourceServer::class);

    $stored = (new KeyStore)->get();

    expect($stored)->toContain('PRIVATE KEY-----')
        ->and(config('passport.private_key'))->toBe($stored)
        ->and(config('passport.public_key'))->toContain('-----BEGIN PUBLIC KEY-----');
});

it('never regenerates over an undecryptable row when APP_KEY changed', function () {
    OAuthFixtures::migrateKeyStore();
    (new KeyStore)->put(OAuthFixtures::rsaPrivateKey());
    $cipher = DB::table(KeyStore::TABLE)->value('private_key');

    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    app()->forgetInstance('encrypter');
    Crypt::clearResolvedInstance('encrypter');

    // No keys reach Passport, so construction fails — but the row survives
    // for when the original APP_KEY is restored.
    expect(fn () => app(ResourceServer::class))->toThrow(LogicException::class)
        ->and(config('passport.private_key'))->toBeNull()
        ->and(DB::table(KeyStore::TABLE)->value('private_key'))->toBe($cipher);
});

it('degrades gracefully when the key-store table is not migrated yet', function () {
    // Pre-migrate state: injection must not throw its own error — Passport's
    // stock missing-file complaint surfaces and the preflight 503 names it.
    expect(fn () => app(ResourceServer::class))->toThrow(LogicException::class)
        ->and(config('passport.private_key'))->toBeNull();
});
