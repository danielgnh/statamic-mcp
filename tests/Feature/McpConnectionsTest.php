<?php

use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tests\Support\OAuthFixtures;
use Illuminate\Support\Facades\Schema;
use Laravel\Passport\Passport;

beforeEach(function () {
    config(['statamic.editions.pro' => true, 'cache.default' => 'array']);

    OAuthFixtures::migratePassport();
    OAuthFixtures::oauthReadyConfig();
});

// ── Route + gate behavior ──

it('404s disconnecting when oauth is not ready', function () {
    // Missing tables: disconnect() reports nothing matched, and the route
    // answers 404 — never a 500.
    Schema::drop('oauth_refresh_tokens');
    Schema::drop('oauth_access_tokens');

    $user = Fixtures::makeUser('access cp');

    $this->actingAs($user)
        ->deleteJson(cp_route('mcp.connections.oauth.destroy', ['client-x', (string) $user->id()]))
        ->assertNotFound();
});

it('403s disconnecting without the access mcp permission', function () {
    $user = Fixtures::makeBareUser('access cp');

    $this->actingAs($user)
        ->deleteJson(cp_route('mcp.connections.oauth.destroy', ['client-x', (string) $user->id()]))
        ->assertForbidden();
});

it("403s disconnecting another user's connection before revealing whether it exists", function () {
    $user = Fixtures::makeUser('access cp');

    $this->actingAs($user)
        ->deleteJson(cp_route('mcp.connections.oauth.destroy', ['client-x', 'someone-else']))
        ->assertForbidden();
});

// ── Real disconnect behavior ──

it('lets a user disconnect their own connection, revoking access and refresh tokens', function () {
    $user = Fixtures::makeUser('access cp');

    $client = OAuthFixtures::client();
    $tokenId = OAuthFixtures::accessToken((string) $user->id(), $client);
    $refreshId = OAuthFixtures::refreshToken($tokenId);

    $this->actingAs($user)
        ->delete(cp_route('mcp.connections.oauth.destroy', [$client, (string) $user->id()]))
        ->assertRedirect(cp_route('mcp.connections.index'));

    $tokenModel = Passport::tokenModel();
    $refreshModel = Passport::refreshTokenModel();

    expect($tokenModel::query()->find($tokenId)->revoked)->toBeTrue()
        ->and($refreshModel::query()->find($refreshId)->revoked)->toBeTrue();
});

it("lets a super admin disconnect anyone's connection", function () {
    $super = Fixtures::makeSuper();
    $other = Fixtures::makeUser();

    $client = OAuthFixtures::client();
    $tokenId = OAuthFixtures::accessToken((string) $other->id(), $client);

    $this->actingAs($super)
        ->delete(cp_route('mcp.connections.oauth.destroy', [$client, (string) $other->id()]))
        ->assertRedirect(cp_route('mcp.connections.index'));

    $tokenModel = Passport::tokenModel();

    expect($tokenModel::query()->find($tokenId)->revoked)->toBeTrue();
});

it("leaves another user's tokens intact when a non-super is 403d", function () {
    $user = Fixtures::makeUser('access cp');
    $other = Fixtures::makeUser();

    $client = OAuthFixtures::client();
    $tokenId = OAuthFixtures::accessToken((string) $other->id(), $client);

    $this->actingAs($user)
        ->deleteJson(cp_route('mcp.connections.oauth.destroy', [$client, (string) $other->id()]))
        ->assertForbidden();

    $tokenModel = Passport::tokenModel();

    expect($tokenModel::query()->find($tokenId)->revoked)->toBeFalse();
});

it('404s disconnecting a pair with no tokens', function () {
    $user = Fixtures::makeUser('access cp');

    $this->actingAs($user)
        ->deleteJson(cp_route('mcp.connections.oauth.destroy', ['no-such-client', (string) $user->id()]))
        ->assertNotFound();
});

// ── Panel rendering ──

it('hides the connections panel entirely in token mode', function () {
    config(['statamic.mcp.auth' => 'token']);

    $user = Fixtures::makeUser('access cp');

    $this->actingAs($user)
        ->get(cp_route('mcp.connections.index'))
        ->assertOk()
        ->assertDontSee('Your connections', false);
});

it('shows a doctor remedy instead of the table when oauth mode is not ready', function () {
    // oauth mode on, but the passport tables are gone — the page renders the
    // remedy alert instead of 500ing.
    Schema::drop('oauth_refresh_tokens');
    Schema::drop('oauth_access_tokens');

    $user = Fixtures::makeUser('access cp');

    $this->actingAs($user)
        ->get(cp_route('mcp.connections.index'))
        ->assertOk()
        ->assertSee('mcp:doctor', false);
});

it('shows a permitted user only their own connections', function () {
    $user = Fixtures::makeUser('access cp');
    $other = Fixtures::makeUser();

    // Client names must not collide with static page copy ("Claude Code",
    // "claude.ai", "ChatGPT") — the assertions below have to fail when the
    // panel is empty or shows the wrong row.
    $client = OAuthFixtures::client('Acme Team Laptop');
    OAuthFixtures::accessToken((string) $user->id(), $client);
    OAuthFixtures::accessToken((string) $other->id(), OAuthFixtures::client('Zebra Desktop'));

    $this->actingAs($user)
        ->get(cp_route('mcp.connections.index'))
        ->assertOk()
        ->assertSee('Acme Team Laptop', false)
        ->assertDontSee('Zebra Desktop', false);
});

it("shows a super admin everyone's connections with their emails", function () {
    $super = Fixtures::makeSuper();
    $other = Fixtures::makeUser();

    OAuthFixtures::accessToken((string) $other->id(), OAuthFixtures::client('Zebra Desktop'));

    $this->actingAs($super)
        ->get(cp_route('mcp.connections.index'))
        ->assertOk()
        ->assertSee('Zebra Desktop', false)
        ->assertSee($other->email(), false);
});

it('marks dead connections as expired', function () {
    $user = Fixtures::makeUser('access cp');

    OAuthFixtures::accessToken((string) $user->id(), OAuthFixtures::client(), [
        'expires_at' => now()->subHour(),
    ]);

    $this->actingAs($user)
        ->get(cp_route('mcp.connections.index'))
        ->assertOk()
        ->assertSee('Expired', false);
});

it('renders DCR-supplied client names inertly for the vue runtime compiler', function () {
    // Client names arrive from dynamic client registration — attacker-
    // controlled input rendered in supers' sessions. Same v-pre contract as
    // token names (see McpTokensTest).
    $user = Fixtures::makeUser('access cp');

    OAuthFixtures::accessToken((string) $user->id(), OAuthFixtures::client('{{ 7*7 }}'));

    $this->actingAs($user)
        ->get(cp_route('mcp.connections.index'))
        ->assertOk()
        ->assertSee('<span v-pre>{{ 7*7 }}</span>', false);
});

it('shows an empty state when oauth is ready but nothing has connected', function () {
    $user = Fixtures::makeUser('access cp');

    $this->actingAs($user)
        ->get(cp_route('mcp.connections.index'))
        ->assertOk()
        ->assertSee('No connections yet', false);
});
