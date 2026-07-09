<?php

use Danielgnh\StatamicMcp\Tests\RegistersMcpAuthProbeRoute;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tokens\TokenRepository;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Statamic\Facades\User;

// Registers the /mcp-auth-probe route pre-boot — a beforeEach registration
// would land behind Statamic's frontend catch-all and 404.
uses(RegistersMcpAuthProbeRoute::class);

beforeEach(function () {
    File::delete(storage_path('statamic/mcp/tokens.yaml'));

    $this->repo = app(TokenRepository::class);
});

it('rejects a request with no authorization header', function () {
    $this->postJson('/mcp-auth-probe')
        ->assertStatus(401)
        ->assertHeader('WWW-Authenticate', 'Bearer');
});

it('rejects a non-bearer authorization scheme', function () {
    $this->postJson('/mcp-auth-probe', [], ['Authorization' => 'Basic dXNlcjpwYXNz'])
        ->assertStatus(401);
});

it('rejects malformed bearer tokens', function (string $token) {
    $this->postJson('/mcp-auth-probe', [], ['Authorization' => "Bearer {$token}"])
        ->assertStatus(401);
})->with([
    'no mcp prefix' => 'sk-something-else',
    'wrong prefix' => 'mpc_abcdefghijkl_'.str_repeat('s', 40),
    'missing secret part' => 'mcp_abcdefghijkl',
    'empty token id' => 'mcp__'.str_repeat('s', 40),
    'empty secret' => 'mcp_abcdefghijkl_',
]);

it('rejects an oversized authorization header before parsing', function () {
    $token = 'mcp_abcdefghijkl_'.str_repeat('a', 300); // pushes the header past 256 chars

    $this->postJson('/mcp-auth-probe', [], ['Authorization' => "Bearer {$token}"])
        ->assertStatus(401);
});

it('rejects an unknown token id', function () {
    $this->postJson('/mcp-auth-probe', [], ['Authorization' => 'Bearer mcp_unknownnnnnn_'.Str::random(40)])
        ->assertStatus(401);
});

it('rejects a known token id with the wrong secret', function () {
    $user = Fixtures::makeUser();
    $plain = $this->repo->issue($user);

    $this->postJson('/mcp-auth-probe', [], ['Authorization' => "Bearer mcp_{$plain->tokenId}_".Str::random(40)])
        ->assertStatus(401);
});

it('rejects an expired token', function () {
    $user = Fixtures::makeUser();
    $plain = $this->repo->issue($user, null, 5);

    $this->travelTo(now()->addDays(6));

    $this->postJson('/mcp-auth-probe', [], ['Authorization' => "Bearer {$plain->token}"])
        ->assertStatus(401);
});

it('rejects a revoked token that worked moments before', function () {
    $user = Fixtures::makeUser();
    $plain = $this->repo->issue($user);

    $headers = ['Authorization' => "Bearer {$plain->token}"];

    $this->postJson('/mcp-auth-probe', [], $headers)->assertOk();

    $this->repo->revoke($plain->tokenId);

    $this->postJson('/mcp-auth-probe', [], $headers)->assertStatus(401);
});

it('rejects a token whose user has been deleted', function () {
    $user = Fixtures::makeUser();
    $plain = $this->repo->issue($user);

    $user->delete();

    $this->postJson('/mcp-auth-probe', [], ['Authorization' => "Bearer {$plain->token}"])
        ->assertStatus(401);
});

it('authenticates so Statamic User::current() resolves to the token user inside the request', function () {
    $user = Fixtures::makeUser();
    $plain = $this->repo->issue($user);

    // The probe body runs Statamic\Facades\User::current()->email() — this
    // passing proves Auth::shouldUse + Auth::setUser, not just a resolver.
    $this->postJson('/mcp-auth-probe', [], ['Authorization' => "Bearer {$plain->token}"])
        ->assertOk()
        ->assertJson(['email' => $user->email()]);
});

it("returns 403 when the authenticated user lacks the 'access mcp' permission", function () {
    $user = tap(User::make()->email('no-mcp@site.test'))->save(); // no roles at all
    $plain = $this->repo->issue($user);

    $this->postJson('/mcp-auth-probe', [], ['Authorization' => "Bearer {$plain->token}"])
        ->assertStatus(403)
        ->assertJsonFragment([
            'error' => "requires 'access mcp' — grant it to a role of no-mcp@site.test in the Control Panel",
        ]);
});

it("lets supers through without an explicit 'access mcp' grant", function () {
    $super = Fixtures::makeSuper();
    $plain = $this->repo->issue($super);

    $this->postJson('/mcp-auth-probe', [], ['Authorization' => "Bearer {$plain->token}"])
        ->assertOk()
        ->assertJson(['email' => $super->email()]);
});
