<?php

use Danielgnh\StatamicMcp\Tests\RegistersOAuthProbeRoute;
use Danielgnh\StatamicMcp\Tests\Support\OAuthFixtures;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Str;
use Illuminate\Support\Uri;
use Statamic\Facades\User;

uses(RegistersOAuthProbeRoute::class);

beforeEach(function () {
    OAuthFixtures::migratePassport();

    $private = OAuthFixtures::rsaPrivateKey();

    config([
        'passport.private_key' => $private,
        'passport.public_key' => openssl_pkey_get_details(openssl_pkey_get_private($private))['key'],
    ]);

    $this->editor = tap(User::make()->email('editor@site.test')->makeSuper())->save();

    $this->register = fn (array $redirectUris) => $this->postJson('/oauth/register', [
        'client_name' => 'Claude',
        'redirect_uris' => $redirectUris,
    ])->assertCreated()->json('client_id');

    $this->consent = fn (string $client, array $query = []) => $this->actingAs($this->editor, 'web')->get('/oauth/authorize?'.http_build_query([
        'client_id' => $client,
        'response_type' => 'code',
        'scope' => 'mcp:use',
        'state' => 'consent-state',
        'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', Str::random(64), true)), '+/', '-_'), '='),
        'code_challenge_method' => 'S256',
        ...$query,
    ]));

    $this->approve = fn () => $this->actingAs($this->editor, 'web')
        ->withoutMiddleware(ValidateCsrfToken::class)
        ->withSession(['authToken' => session('authToken'), 'authRequest' => session('authRequest')])
        ->post('/oauth/authorize', ['auth_token' => session('authToken')]);
});

it('shows where approving sends the code, whatever name the client registered under', function () {
    $client = ($this->register)(['https://attacker.example/callback']);

    ($this->consent)($client, ['redirect_uri' => 'https://attacker.example/callback'])
        ->assertOk()
        ->assertSee('Claude')
        ->assertSeeInOrder(['attacker.example', 'https://attacker.example/callback']);

    expect(($this->approve)()->headers->get('Location'))->toStartWith('https://attacker.example/callback?');
});

it('shows a lookalike host the way the browser will reach it', function (string $registered, string $reached) {
    $client = ($this->register)([$registered]);

    ($this->consent)($client, ['redirect_uri' => $registered])
        ->assertOk()
        ->assertSeeInOrder([$reached, "https://{$reached}/callback"])
        ->assertDontSee(parse_url($registered, PHP_URL_HOST));

    expect(Uri::of(($this->approve)()->headers->get('Location'))->host())->toBe($reached);
})->with([
    'capital I for l' => ['https://cIaude.ai/callback', 'ciaude.ai'],
    'Cyrillic a' => ["https://cl\u{0430}ude.ai/callback", 'xn--clude-5ve.ai'],
]);

it('shows the registered redirect URI when the request leaves it out', function () {
    $client = ($this->register)(['https://client.example.com/callback']);

    ($this->consent)($client)
        ->assertOk()
        ->assertSee('https://client.example.com/callback');

    expect(($this->approve)()->headers->get('Location'))->toStartWith('https://client.example.com/callback?');
});

it('shows the registered redirect URI when the request sends an empty one on a site that keeps empty strings', function () {
    $this->withoutMiddleware(ConvertEmptyStringsToNull::class);

    $client = ($this->register)(['https://client.example.com/callback']);

    ($this->consent)($client, ['redirect_uri' => ''])
        ->assertOk()
        ->assertSee('https://client.example.com/callback');

    expect(($this->approve)()->headers->get('Location'))->toStartWith('https://client.example.com/callback?');
});

it('shows the port a loopback redirect uses, not the one registered', function () {
    $client = ($this->register)(['http://127.0.0.1/callback']);

    ($this->consent)($client, ['redirect_uri' => 'http://127.0.0.1:43210/callback'])
        ->assertOk()
        ->assertSee('http://127.0.0.1:43210/callback');

    expect(($this->approve)()->headers->get('Location'))->toStartWith('http://127.0.0.1:43210/callback?');
});
