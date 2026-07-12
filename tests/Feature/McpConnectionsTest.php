<?php

use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tests\Support\OAuthFixtures;
use Laravel\Passport\Passport;
use Statamic\Contracts\Auth\UserRepository;

$requiresPassport = fn () => ! class_exists(Passport::class);

beforeEach(function () {
    config(['statamic.editions.pro' => true, 'cache.default' => 'array']);

    if (class_exists(Passport::class)) {
        OAuthFixtures::migratePassport();

        // Pin the (singleton) user repository to the file driver BEFORE
        // oauthReadyConfig() flips statamic.users.repository to eloquent —
        // ConnectionRepository::ready() reads config only, while the test
        // users stay file-based (no users table exists in this suite).
        app(UserRepository::class);

        OAuthFixtures::oauthReadyConfig();
    }
});

// ── Route + gate behavior that must hold even WITHOUT Passport (main leg) ──

it('404s disconnecting when oauth is not ready', function () {
    // No Passport / no tables / wrong config: disconnect() reports nothing
    // matched, and the route answers 404 — never a 500.
    config(['auth.guards.api' => ['driver' => 'session', 'provider' => 'users']]);

    $user = Fixtures::makeUser('access cp', 'access mcp_tokens utility');

    $this->actingAs($user)
        ->deleteJson(cp_route('utilities.mcp-tokens.connections.destroy', ['client-x', (string) $user->id()]))
        ->assertNotFound();
});

it('403s disconnecting without the utility permission', function () {
    $user = Fixtures::makeUser('access cp'); // no utility permission

    $this->actingAs($user)
        ->deleteJson(cp_route('utilities.mcp-tokens.connections.destroy', ['client-x', (string) $user->id()]))
        ->assertForbidden();
});

it("403s disconnecting another user's connection before revealing whether it exists", function () {
    $user = Fixtures::makeUser('access cp', 'access mcp_tokens utility');

    $this->actingAs($user)
        ->deleteJson(cp_route('utilities.mcp-tokens.connections.destroy', ['client-x', 'someone-else']))
        ->assertForbidden();
});

// ── Real disconnect behavior (Passport CI leg) ──

it('lets a user disconnect their own connection, revoking access and refresh tokens', function () {
    $user = Fixtures::makeUser('access cp', 'access mcp_tokens utility');

    $client = OAuthFixtures::client();
    $tokenId = OAuthFixtures::accessToken((string) $user->id(), $client);
    $refreshId = OAuthFixtures::refreshToken($tokenId);

    $this->actingAs($user)
        ->delete(cp_route('utilities.mcp-tokens.connections.destroy', [$client, (string) $user->id()]))
        ->assertRedirect(cp_route('utilities.mcp-tokens'));

    $tokenModel = Passport::tokenModel();
    $refreshModel = Passport::refreshTokenModel();

    expect($tokenModel::query()->find($tokenId)->revoked)->toBeTrue()
        ->and($refreshModel::query()->find($refreshId)->revoked)->toBeTrue();
})->skip($requiresPassport, 'requires laravel/passport (Passport CI leg)');

it("lets a super admin disconnect anyone's connection", function () {
    $super = Fixtures::makeSuper();
    $other = Fixtures::makeUser();

    $client = OAuthFixtures::client();
    $tokenId = OAuthFixtures::accessToken((string) $other->id(), $client);

    $this->actingAs($super)
        ->delete(cp_route('utilities.mcp-tokens.connections.destroy', [$client, (string) $other->id()]))
        ->assertRedirect(cp_route('utilities.mcp-tokens'));

    $tokenModel = Passport::tokenModel();

    expect($tokenModel::query()->find($tokenId)->revoked)->toBeTrue();
})->skip($requiresPassport, 'requires laravel/passport (Passport CI leg)');

it("leaves another user's tokens intact when a non-super is 403d", function () {
    $user = Fixtures::makeUser('access cp', 'access mcp_tokens utility');
    $other = Fixtures::makeUser();

    $client = OAuthFixtures::client();
    $tokenId = OAuthFixtures::accessToken((string) $other->id(), $client);

    $this->actingAs($user)
        ->deleteJson(cp_route('utilities.mcp-tokens.connections.destroy', [$client, (string) $other->id()]))
        ->assertForbidden();

    $tokenModel = Passport::tokenModel();

    expect($tokenModel::query()->find($tokenId)->revoked)->toBeFalse();
})->skip($requiresPassport, 'requires laravel/passport (Passport CI leg)');

it('404s disconnecting a pair with no tokens', function () {
    $user = Fixtures::makeUser('access cp', 'access mcp_tokens utility');

    $this->actingAs($user)
        ->deleteJson(cp_route('utilities.mcp-tokens.connections.destroy', ['no-such-client', (string) $user->id()]))
        ->assertNotFound();
})->skip($requiresPassport, 'requires laravel/passport (Passport CI leg)');
