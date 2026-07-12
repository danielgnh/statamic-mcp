<?php

use Danielgnh\StatamicMcp\OAuth\ConnectionRepository;
use Danielgnh\StatamicMcp\Tests\Support\OAuthFixtures;
use Laravel\Passport\Passport;

// Every test here needs laravel/passport — the main CI leg (where the package
// is deliberately absent) skips them; the Passport CI leg runs them.
$requiresPassport = fn () => ! class_exists(Passport::class);

beforeEach(function () {
    if (class_exists(Passport::class)) {
        OAuthFixtures::migratePassport();
        OAuthFixtures::oauthReadyConfig();
    }
});

it('is not ready without the oauth prerequisites', function () {
    config(['auth.guards.api' => ['driver' => 'session', 'provider' => 'users']]);

    $repository = new ConnectionRepository;

    expect($repository->ready())->toBeFalse()
        ->and($repository->all())->toBeEmpty()
        ->and($repository->disconnect('u1', 'c1'))->toBeFalse();
})->skip($requiresPassport, 'requires laravel/passport (Passport CI leg)');

it('is ready when users are eloquent, the api guard is passport, and the tables exist', function () {
    expect((new ConnectionRepository)->ready())->toBeTrue();
})->skip($requiresPassport, 'requires laravel/passport (Passport CI leg)');

it('groups tokens into one connection per user and client with honest timestamps', function () {
    $claude = OAuthFixtures::client('Claude');
    $chatgpt = OAuthFixtures::client('ChatGPT');

    OAuthFixtures::accessToken('user-1', $claude, ['created_at' => now()->subDays(10)]);
    OAuthFixtures::accessToken('user-1', $claude, ['created_at' => now()->subHour()]);
    OAuthFixtures::accessToken('user-1', $chatgpt);
    OAuthFixtures::accessToken('user-2', $claude);

    $connections = (new ConnectionRepository)->all();

    expect($connections)->toHaveCount(3);

    $pair = $connections->first(fn ($c) => $c['user_id'] === 'user-1' && $c['client_name'] === 'Claude');

    expect($pair['connected_at']->toDateString())->toBe(now()->subDays(10)->toDateString())
        ->and($pair['last_refreshed_at']->toDateString())->toBe(now()->toDateString())
        ->and($pair['active'])->toBeTrue();
})->skip($requiresPassport, 'requires laravel/passport (Passport CI leg)');

it('falls back to an unknown-client label when the client row is gone', function () {
    OAuthFixtures::accessToken('user-1', 'deleted-client-id');

    expect((new ConnectionRepository)->all()->first()['client_name'])->toBe('Unknown client');
})->skip($requiresPassport, 'requires laravel/passport (Passport CI leg)');

it('marks a pair active when an expired access token still has a live refresh token', function () {
    $client = OAuthFixtures::client();

    $tokenId = OAuthFixtures::accessToken('user-1', $client, ['expires_at' => now()->subHour()]);
    OAuthFixtures::refreshToken($tokenId);

    expect((new ConnectionRepository)->all()->first()['active'])->toBeTrue();
})->skip($requiresPassport, 'requires laravel/passport (Passport CI leg)');

it('marks a pair inactive when the access token is revoked, even with a live refresh row', function () {
    // Passport's refresh grant checks BOTH rows: a revoked access token kills
    // its refresh token's usability. Status must agree, or the page would
    // show Active for a connector that can no longer get in.
    $client = OAuthFixtures::client();

    $tokenId = OAuthFixtures::accessToken('user-1', $client, ['revoked' => true]);
    OAuthFixtures::refreshToken($tokenId);

    expect((new ConnectionRepository)->all()->first()['active'])->toBeFalse();
})->skip($requiresPassport, 'requires laravel/passport (Passport CI leg)');

it('marks a pair inactive when every token is expired with no live refresh', function () {
    $client = OAuthFixtures::client();

    $tokenId = OAuthFixtures::accessToken('user-1', $client, ['expires_at' => now()->subHour()]);
    OAuthFixtures::refreshToken($tokenId, ['revoked' => true]);

    expect((new ConnectionRepository)->all()->first()['active'])->toBeFalse();
})->skip($requiresPassport, 'requires laravel/passport (Passport CI leg)');

it('disconnects a pair by revoking its access AND refresh tokens', function () {
    $client = OAuthFixtures::client();
    $other = OAuthFixtures::client('ChatGPT');

    $mine = OAuthFixtures::accessToken('user-1', $client);
    $myRefresh = OAuthFixtures::refreshToken($mine);
    $unrelated = OAuthFixtures::accessToken('user-1', $other);

    $repository = new ConnectionRepository;

    expect($repository->disconnect('user-1', $client))->toBeTrue();

    $tokenModel = Passport::tokenModel();
    $refreshModel = Passport::refreshTokenModel();

    expect($tokenModel::query()->find($mine)->revoked)->toBeTrue()
        ->and($refreshModel::query()->find($myRefresh)->revoked)->toBeTrue()
        ->and($tokenModel::query()->find($unrelated)->revoked)->toBeFalse();
})->skip($requiresPassport, 'requires laravel/passport (Passport CI leg)');

it('returns false disconnecting a pair that has no tokens at all', function () {
    expect((new ConnectionRepository)->disconnect('nobody', 'no-client'))->toBeFalse();
})->skip($requiresPassport, 'requires laravel/passport (Passport CI leg)');

it('treats disconnecting an already-dead pair as a successful no-op', function () {
    $client = OAuthFixtures::client();
    OAuthFixtures::accessToken('user-1', $client, ['revoked' => true]);

    expect((new ConnectionRepository)->disconnect('user-1', $client))->toBeTrue();
})->skip($requiresPassport, 'requires laravel/passport (Passport CI leg)');
