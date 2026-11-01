<?php

use Danielgnh\StatamicMcp\Tests\RegistersOAuthProbeRoute;
use Danielgnh\StatamicMcp\Tests\Support\OAuthFixtures;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Statamic\Facades\User;

uses(RegistersOAuthProbeRoute::class);

beforeEach(function () {
    OAuthFixtures::migratePassport();

    $private = OAuthFixtures::rsaPrivateKey();

    config([
        'passport.private_key' => $private,
        'passport.public_key' => openssl_pkey_get_details(openssl_pkey_get_private($private))['key'],
    ]);
});

it('requires laravel/mcp 1.1.2 so no other site can frame the consent page', function () {
    $client = $this->postJson('/oauth/register', [
        'client_name' => 'Claude',
        'redirect_uris' => ['https://client.example.com/callback'],
    ])->assertCreated()->json('client_id');

    $user = tap(User::make()->email('editor@site.test')->makeSuper())->save();

    $consent = $this->actingAs($user, 'web')->get('/oauth/authorize?'.http_build_query([
        'client_id' => $client,
        'redirect_uri' => 'https://client.example.com/callback',
        'response_type' => 'code',
        'scope' => 'mcp:use',
        'state' => Str::random(16),
        'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', Str::random(64), true)), '+/', '-_'), '='),
        'code_challenge_method' => 'S256',
    ]));

    $consent->assertOk()
        ->assertSee('Claude')
        ->assertHeader('X-Frame-Options', 'DENY');

    expect($consent->headers->all('content-security-policy'))->toContain("frame-ancestors 'none'");
});

it('caps a client registration at ten redirect URIs, as laravel/mcp 1.1.1 and 0.9.7 do', function () {
    $response = $this->postJson('/oauth/register', [
        'client_name' => 'Claude',
        'redirect_uris' => collect(range(1, 11))->map(fn (int $i) => "https://client.example.com/callback/{$i}")->all(),
    ]);

    $response->assertStatus(400)->assertJson(['error' => 'invalid_redirect_uri']);

    expect(Passport::client()->newQuery()->count())->toBe(0);
});

it('refuses a localhost lookalike on a host that limits redirect domains, as laravel/mcp 1.0.1 and 0.9.6 do', function () {
    config(['mcp.redirect_domains' => ['https://claude.ai', 'http://localhost']]);

    $response = $this->postJson('/oauth/register', [
        'client_name' => 'Claude',
        'redirect_uris' => ['http://localhost:80@attacker.example/callback'],
    ]);

    $response->assertStatus(400)->assertJson(['error' => 'invalid_redirect_uri']);

    expect(Passport::client()->newQuery()->count())->toBe(0);
});
