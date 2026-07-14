<?php

use Danielgnh\StatamicMcp\Support\OAuthPrerequisites;
use Danielgnh\StatamicMcp\Tests\Support\OAuthFixtures;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

it('reports passport as installed', function () {
    // require-dev ships the real package — class_exists is genuinely true.
    expect((new OAuthPrerequisites)->passportInstalled())->toBeTrue();
});

it('reports keys as missing when neither config nor key files provide them', function () {
    expect((new OAuthPrerequisites)->passportKeysExist())->toBeFalse();
});

it('accepts keys from config — the PASSPORT_PRIVATE_KEY / PASSPORT_PUBLIC_KEY env path', function () {
    config(['passport.private_key' => '-----BEGIN RSA PRIVATE KEY-----fake']);

    // One key is not enough: signing needs the private, verifying the public.
    expect((new OAuthPrerequisites)->passportKeysExist())->toBeFalse();

    config(['passport.public_key' => '-----BEGIN PUBLIC KEY-----fake']);

    expect((new OAuthPrerequisites)->passportKeysExist())->toBeTrue();
});

it('accepts keys from the files passport:keys writes', function () {
    $dir = sys_get_temp_dir().'/mcp-prereqs-'.Str::random(8);
    @mkdir($dir, 0700, true);
    file_put_contents($dir.'/oauth-private.key', 'fake');
    file_put_contents($dir.'/oauth-public.key', 'fake');

    Passport::loadKeysFrom($dir);

    try {
        expect((new OAuthPrerequisites)->passportKeysExist())->toBeTrue();
    } finally {
        array_map(unlink(...), glob($dir.'/*') ?: []);
        @rmdir($dir);
        Passport::$keyPath = null;
    }
});

it('reports the oauth tables as missing before migrate has run', function () {
    expect((new OAuthPrerequisites)->oauthTablesMigrated())->toBeFalse();
});

it('reports the oauth tables as migrated once all three exist', function () {
    OAuthFixtures::migratePassport();

    expect((new OAuthPrerequisites)->oauthTablesMigrated())->toBeTrue();
});

it('rejects integer user_id columns and accepts string ones', function () {
    OAuthFixtures::migratePassportWithBigintUserIds();

    // Passport's stock shape: bigint user_id can never hold a Statamic UUID.
    expect((new OAuthPrerequisites)->oauthUserIdColumnsFitStatamicIds())->toBeFalse();
});

it('accepts the string user_id columns the addon migration produces', function () {
    OAuthFixtures::migratePassport();

    expect((new OAuthPrerequisites)->oauthUserIdColumnsFitStatamicIds())->toBeTrue();
});

// The predicate tracks whether a consent view is actually bound (the addon
// binds one in oauth mode; here we bind it explicitly since this suite boots
// in token mode).
it('reports the authorization view as bound once a view is registered', function () {
    $prereqs = new OAuthPrerequisites;

    expect($prereqs->authorizationViewBound())->toBeFalse();

    Passport::authorizationView('statamic-mcp::oauth.authorize');

    expect($prereqs->authorizationViewBound())->toBeTrue();
});
