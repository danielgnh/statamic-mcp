<?php

use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tokens\TokenRepository;
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
        ->assertSee('http:\/\/localhost\/mcp\/statamic', false);
});
