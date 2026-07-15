<?php

use Danielgnh\StatamicMcp\OAuth\KeyStore;
use Danielgnh\StatamicMcp\Tests\Support\OAuthFixtures;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

it('reports unavailable until its table is migrated', function () {
    $store = new KeyStore;

    expect($store->available())->toBeFalse();

    OAuthFixtures::migrateKeyStore();

    expect($store->available())->toBeTrue();
});

it('round-trips the private key and stores it encrypted at rest', function () {
    OAuthFixtures::migrateKeyStore();
    $pem = OAuthFixtures::rsaPrivateKey();

    $store = new KeyStore;
    $store->put($pem);

    $raw = DB::table(KeyStore::TABLE)->value('private_key');

    expect($store->get())->toBe($pem)
        ->and($raw)->not->toContain('PRIVATE KEY') // Crypt at rest — a DB dump alone leaks nothing
        ->and($store->has())->toBeTrue();
});

it('returns null and has() false when no key is stored', function () {
    OAuthFixtures::migrateKeyStore();

    $store = new KeyStore;

    expect($store->get())->toBeNull()
        ->and($store->has())->toBeFalse()
        ->and($store->undecryptable())->toBeFalse();
});

it('never overwrites a stored key — first write wins', function () {
    OAuthFixtures::migrateKeyStore();
    $first = OAuthFixtures::rsaPrivateKey();
    $second = OAuthFixtures::rsaPrivateKey();

    $store = new KeyStore;
    $store->put($first);
    $store->put($second); // a racing second server must lose, not rotate the fleet's keys

    expect($store->get())->toBe($first)
        ->and(DB::table(KeyStore::TABLE)->count())->toBe(1);
});

it('flags a stored key as undecryptable after APP_KEY changes instead of pretending it is absent', function () {
    OAuthFixtures::migrateKeyStore();

    $store = new KeyStore;
    $store->put(OAuthFixtures::rsaPrivateKey());

    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    app()->forgetInstance('encrypter');
    Crypt::clearResolvedInstance('encrypter');

    expect($store->get())->toBeNull()
        ->and($store->has())->toBeTrue()
        ->and($store->undecryptable())->toBeTrue();
});

it('derives the public key from the private key', function () {
    $pem = OAuthFixtures::rsaPrivateKey();

    $public = (new KeyStore)->publicKeyFor($pem);

    expect($public)->toContain('-----BEGIN PUBLIC KEY-----')
        ->and((new KeyStore)->publicKeyFor("NOT\nA\nKEY"))->toBeNull();
});
