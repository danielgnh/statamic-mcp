<?php

use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tokens\TokenRepository;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    config(['statamic.editions.pro' => true, 'cache.default' => 'array']);
    File::delete(storage_path('statamic/mcp/tokens.yaml'));
});

// Every CP route sits behind Statamic's Authorize middleware, which requires
// 'access cp' (supers bypass it) — so each user that makes a request needs it
// in addition to the utility permission under test.

it('403s users without the utility permission', function () {
    $user = Fixtures::makeUser('access cp'); // can enter the CP, has 'access mcp', but NOT the utility permission

    // The CP exception handler turns AuthorizationException into a 302
    // redirect for HTML requests; only JSON-expecting requests get the raw 403.
    $this->actingAs($user)
        ->getJson(cp_route('utilities.mcp-tokens'))
        ->assertForbidden();
});

it('shows a permitted user only their own tokens', function () {
    $user = Fixtures::makeUser('access cp', 'access mcp_tokens utility');
    $other = Fixtures::makeUser();

    $repository = app(TokenRepository::class);
    $repository->issue($user, 'mine-alpha');
    $repository->issue($other, 'theirs-beta');

    $this->actingAs($user)
        ->get(cp_route('utilities.mcp-tokens'))
        ->assertOk()
        ->assertSee('mine-alpha', false)
        ->assertDontSee('theirs-beta', false);
});

it('shows a super admin all tokens with their owners', function () {
    $super = Fixtures::makeSuper();
    $other = Fixtures::makeUser();

    app(TokenRepository::class)->issue($other, 'theirs-beta');

    $this->actingAs($super)
        ->get(cp_route('utilities.mcp-tokens'))
        ->assertOk()
        ->assertSee('theirs-beta', false)
        ->assertSee($other->email(), false);
});

it('marks expired tokens as expired', function () {
    $user = Fixtures::makeUser('access cp', 'access mcp_tokens utility');

    $this->travelTo(now()->subDays(60), function () use ($user) {
        app(TokenRepository::class)->issue($user, 'old-token', 30);
    });

    $this->actingAs($user)
        ->get(cp_route('utilities.mcp-tokens'))
        ->assertOk()
        ->assertSee('old-token', false)
        ->assertSee('Expired', false);
});

it('warns when the user lacks the access mcp permission', function () {
    $bare = Fixtures::makeBareUser('access cp', 'access mcp_tokens utility');

    $this->actingAs($bare)
        ->get(cp_route('utilities.mcp-tokens'))
        ->assertOk()
        ->assertSee('does not have the', false); // access-mcp warning banner
});

it('does not warn when the user has the access mcp permission', function () {
    $user = Fixtures::makeUser('access cp', 'access mcp_tokens utility');

    $this->actingAs($user)
        ->get(cp_route('utilities.mcp-tokens'))
        ->assertOk()
        ->assertDontSee('does not have the', false);
});

it('shows the oauth-mode notice only when auth mode is oauth', function () {
    config(['statamic.mcp.auth' => 'oauth']);

    $user = Fixtures::makeUser('access cp', 'access mcp_tokens utility');

    // 'This site is in OAuth mode' is unique to the banner — the help panel's
    // static copy also mentions "OAuth mode", so a bare sentinel would always match.
    $this->actingAs($user)
        ->get(cp_route('utilities.mcp-tokens'))
        ->assertOk()
        ->assertSee('This site is in OAuth mode', false);

    config(['statamic.mcp.auth' => 'token']);

    $this->actingAs($user)
        ->get(cp_route('utilities.mcp-tokens'))
        ->assertDontSee('This site is in OAuth mode', false);
});

it('warns about plain http and shows the endpoint in the help panel', function () {
    $user = Fixtures::makeUser('access cp', 'access mcp_tokens utility');

    // Test requests hit http://localhost, so the insecure warning must show
    // and the endpoint must be printed for the help panel. The Blade HTML is
    // JSON-encoded into the Inertia payload, so slashes arrive as \/ escapes.
    $this->actingAs($user)
        ->get(cp_route('utilities.mcp-tokens'))
        ->assertOk()
        ->assertSee('unencrypted', false)
        ->assertSee('http:\/\/localhost\/mcp\/statamic', false)
        ->assertSee('mcpServers', false);
});

it('issues a token for the current user and shows the secret exactly once', function () {
    $user = Fixtures::makeUser('access cp', 'access mcp_tokens utility');

    $response = $this->actingAs($user)->post(cp_route('utilities.mcp-tokens.store'), [
        'name' => 'cp-issued',
        'expiry' => '30',
    ]);

    $response->assertRedirect(cp_route('utilities.mcp-tokens'));

    $records = app(TokenRepository::class)->all();

    expect($records)->toHaveCount(1);

    $record = array_values($records)[0];

    expect($record['user'])->toBe((string) $user->id())
        ->and($record['name'])->toBe('cp-issued')
        ->and($record['expires_at'])->not->toBeNull();

    // First GET after the redirect: the flashed secret is visible.
    $this->get(cp_route('utilities.mcp-tokens'))
        ->assertOk()
        ->assertSee('mcp_'.array_keys($records)[0].'_', false)
        ->assertSee('ONLY time', false);

    // Second GET: the flash is gone — the secret never appears again.
    $this->get(cp_route('utilities.mcp-tokens'))
        ->assertOk()
        ->assertDontSee('mcp_'.array_keys($records)[0].'_', false);
});

it('rejects an expiry outside the presets', function () {
    $user = Fixtures::makeUser('access cp', 'access mcp_tokens utility');

    $this->actingAs($user)
        ->from(cp_route('utilities.mcp-tokens'))
        ->post(cp_route('utilities.mcp-tokens.store'), ['expiry' => '7'])
        ->assertSessionHasErrors('expiry');

    expect(app(TokenRepository::class)->all())->toBeEmpty();
});

it('rejects a name over 100 characters', function () {
    $user = Fixtures::makeUser('access cp', 'access mcp_tokens utility');

    $this->actingAs($user)
        ->from(cp_route('utilities.mcp-tokens'))
        ->post(cp_route('utilities.mcp-tokens.store'), ['name' => str_repeat('x', 101), 'expiry' => 'never'])
        ->assertSessionHasErrors('name');

    expect(app(TokenRepository::class)->all())->toBeEmpty();
});

it('403s issuance without the utility permission', function () {
    $user = Fixtures::makeUser('access cp'); // CP access but no utility permission

    $this->actingAs($user)
        ->postJson(cp_route('utilities.mcp-tokens.store'), ['expiry' => 'never'])
        ->assertForbidden();

    expect(app(TokenRepository::class)->all())->toBeEmpty();
});

it('flashes old input when the store is busy on issuance', function () {
    $user = Fixtures::makeUser('access cp', 'access mcp_tokens utility');

    $this->mock(TokenRepository::class, function ($mock) {
        $mock->shouldReceive('issue')->andThrow(new LockTimeoutException);
    });

    $this->actingAs($user)
        ->from(cp_route('utilities.mcp-tokens'))
        ->post(cp_route('utilities.mcp-tokens.store'), [
            'name' => 'typed-name',
            'expiry' => '30',
        ])
        ->assertRedirect(cp_route('utilities.mcp-tokens'))
        ->assertSessionHas('error')
        ->assertSessionHasInput('name', 'typed-name');
});

it('flashes an error when the store is busy on revocation', function () {
    $user = Fixtures::makeUser('access cp', 'access mcp_tokens utility');

    $this->mock(TokenRepository::class, function ($mock) use ($user) {
        $mock->shouldReceive('find')->with('busy-token')->andReturn([
            'user' => (string) $user->id(),
            'name' => 'x',
            'hash' => 'h',
            'created_at' => now()->toIso8601String(),
            'expires_at' => null,
        ]);
        $mock->shouldReceive('revoke')->andThrow(new LockTimeoutException);
    });

    $this->actingAs($user)
        ->from(cp_route('utilities.mcp-tokens'))
        ->delete(cp_route('utilities.mcp-tokens.destroy', 'busy-token'))
        ->assertRedirect(cp_route('utilities.mcp-tokens'))
        ->assertSessionHas('error');
});

it('lets a user revoke their own token', function () {
    $user = Fixtures::makeUser('access cp', 'access mcp_tokens utility');

    $plain = app(TokenRepository::class)->issue($user, 'to-revoke');

    $this->actingAs($user)
        ->delete(cp_route('utilities.mcp-tokens.destroy', $plain->tokenId))
        ->assertRedirect(cp_route('utilities.mcp-tokens'));

    expect(app(TokenRepository::class)->all())->toBeEmpty();
});

it("403s revoking another user's token and leaves it intact", function () {
    $user = Fixtures::makeUser('access cp', 'access mcp_tokens utility');
    $other = Fixtures::makeUser();

    $plain = app(TokenRepository::class)->issue($other, 'not-yours');

    $this->actingAs($user)
        ->deleteJson(cp_route('utilities.mcp-tokens.destroy', $plain->tokenId))
        ->assertForbidden();

    expect(app(TokenRepository::class)->all())->toHaveCount(1);
});

it("lets a super admin revoke anyone's token", function () {
    $super = Fixtures::makeSuper();
    $other = Fixtures::makeUser();

    $plain = app(TokenRepository::class)->issue($other, 'audit-revoke');

    $this->actingAs($super)
        ->delete(cp_route('utilities.mcp-tokens.destroy', $plain->tokenId))
        ->assertRedirect(cp_route('utilities.mcp-tokens'));

    expect(app(TokenRepository::class)->all())->toBeEmpty();
});

it('404s revoking an unknown token id', function () {
    $user = Fixtures::makeUser('access cp', 'access mcp_tokens utility');

    $this->actingAs($user)
        ->deleteJson(cp_route('utilities.mcp-tokens.destroy', 'nosuchtoken'))
        ->assertNotFound();
});

it('403s revocation without the utility permission, even for own tokens', function () {
    $user = Fixtures::makeUser('access cp'); // no utility permission

    $plain = app(TokenRepository::class)->issue($user, 'own-but-ungated');

    $this->actingAs($user)
        ->deleteJson(cp_route('utilities.mcp-tokens.destroy', $plain->tokenId))
        ->assertForbidden();

    expect(app(TokenRepository::class)->all())->toHaveCount(1);
});
