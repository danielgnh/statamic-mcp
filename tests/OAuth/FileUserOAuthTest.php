<?php

use Danielgnh\StatamicMcp\Tests\RegistersOAuthProbeRoute;
use Danielgnh\StatamicMcp\Tests\Support\OAuthFixtures;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Str;
use Laravel\Passport\Bridge\AccessToken;
use Laravel\Passport\Bridge\Client;
use Laravel\Passport\Bridge\Scope;
use Laravel\Passport\Passport;
use League\OAuth2\Server\CryptKey;
use Statamic\Facades\User as StatamicUser;

uses(RegistersOAuthProbeRoute::class);

/**
 * The headline capability, end-to-end with REAL signed tokens: OAuth mode
 * authenticates Statamic FILE users — no Eloquent users, no HasApiTokens, no
 * user migration. Bearer validation runs through Passport's own
 * ResourceServer; the user resolves through the Statamic repository; the
 * production middleware stack (AuthenticateOAuth + EnsureMcpPermission)
 * enforces scope and permission.
 */

/**
 * Mint a Passport-FORMAT bearer for a user id + scopes, signed with the real
 * private key via Passport's own Bridge/league entities — byte-for-byte what
 * /oauth/token emits — and record the DB row the revocation check reads.
 */
function mintFileUserBearer(string $userId, array $scopes, array $tokenOverrides = []): string
{
    $jti = Str::random(40);
    $clientId = OAuthFixtures::client('Minted Client');

    OAuthFixtures::accessToken($userId, $clientId, array_merge([
        'id' => $jti,
        'scopes' => $scopes,
    ], $tokenOverrides));

    $token = new AccessToken($userId, array_map(fn ($s) => new Scope($s), $scopes), new Client($clientId, 'Minted Client', ['https://client.test/callback']));
    $token->setIdentifier($jti);
    $token->setExpiryDateTime(new DateTimeImmutable('+1 hour'));
    $token->setPrivateKey(new CryptKey(Passport::keyPath('oauth-private.key'), null, false));

    return $token->toString();
}

beforeEach(function () {
    $this->keyDir = sys_get_temp_dir().'/mcp-oauth-test-'.Str::random(8);
    @mkdir($this->keyDir, 0700, true);

    $rsa = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($rsa, $private);
    file_put_contents($this->keyDir.'/oauth-private.key', $private);
    file_put_contents($this->keyDir.'/oauth-public.key', openssl_pkey_get_details($rsa)['key']);
    chmod($this->keyDir.'/oauth-private.key', 0600);
    chmod($this->keyDir.'/oauth-public.key', 0600);

    Passport::loadKeysFrom($this->keyDir);
    Passport::tokensCan(['mcp:use' => 'Use MCP server']);

    OAuthFixtures::migratePassport();
});

afterEach(function () {
    array_map(unlink(...), glob($this->keyDir.'/*') ?: []);
    @rmdir($this->keyDir);
    Passport::$keyPath = null;
});

it('authenticates a Statamic FILE user from a real Passport bearer — no Eloquent anywhere', function () {
    $user = tap(StatamicUser::make()->email('file-user@site.test')->makeSuper())->save();

    // Sanity: a genuine file user, keyed by UUID, in the file repository.
    expect($user->id())->toBeString()
        ->and(config('statamic.users.repository'))->toBe('file');

    $bearer = mintFileUserBearer($user->id(), ['mcp:use']);

    $this->withHeaders(['Authorization' => "Bearer {$bearer}"])
        ->postJson('/mcp-oauth-probe')
        ->assertOk()
        ->assertJson(['email' => 'file-user@site.test']);
});

it('rejects a token missing the mcp:use scope with 403 insufficient_scope', function () {
    $user = tap(StatamicUser::make()->email('file-user@site.test')->makeSuper())->save();

    $bearer = mintFileUserBearer($user->id(), ['some-other-scope']);

    $response = $this->withHeaders(['Authorization' => "Bearer {$bearer}"])
        ->postJson('/mcp-oauth-probe');

    $response->assertStatus(403);
    expect($response->headers->get('WWW-Authenticate'))->toContain('insufficient_scope');
});

it('rejects a revoked token with 401', function () {
    $user = tap(StatamicUser::make()->email('file-user@site.test')->makeSuper())->save();

    $bearer = mintFileUserBearer($user->id(), ['mcp:use'], ['revoked' => true]);

    $this->withHeaders(['Authorization' => "Bearer {$bearer}"])
        ->postJson('/mcp-oauth-probe')
        ->assertStatus(401);
});

it('rejects a garbage bearer with 401, not 500', function () {
    $this->withHeaders(['Authorization' => 'Bearer not-a-jwt'])
        ->postJson('/mcp-oauth-probe')
        ->assertStatus(401);
});

it("403s an authenticated file user who lacks the 'access mcp' permission", function () {
    // The token authenticates fine — Statamic's native permission system
    // still decides access, identically to token mode.
    $user = tap(StatamicUser::make()->email('no-perms@site.test'))->save();

    $bearer = mintFileUserBearer($user->id(), ['mcp:use']);

    $response = $this->withHeaders(['Authorization' => "Bearer {$bearer}"])
        ->postJson('/mcp-oauth-probe');

    $response->assertStatus(403);
    expect($response->json('error'))->toContain('access mcp');
});

it('issues a token to a FILE user through the real authorize → consent → exchange flow', function () {
    $user = tap(StatamicUser::make()->email('file-user@site.test')->makeSuper())->save();

    // A public PKCE client, shaped exactly like laravel/mcp's DCR creates them.
    $clientId = (string) Str::uuid();
    $redirect = 'https://client.test/callback';

    (new (Passport::clientModel()))->forceFill([
        'id' => $clientId,
        'name' => 'Claude',
        'secret' => null,
        'provider' => null,
        'redirect_uris' => [$redirect],
        'grant_types' => ['authorization_code', 'refresh_token'],
        'revoked' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ])->save();

    // Consent view as a closure: capture the auth token and the raw session
    // authRequest so the approve POST can be seeded deterministically
    // (test-session persistence is driver-dependent; production uses a real
    // session cookie). Captured in-process, not via the JSON response — the
    // session format changed from object to serialized string in Passport
    // 13.7.5 and only the latter survives a JSON round-trip.
    $consentSession = [];

    Passport::authorizationView(function (array $params) use (&$consentSession) {
        $consentSession = [
            'authToken' => $params['authToken'],
            'authRequest' => session('authRequest'),
        ];

        return response()->json(['user_email' => $params['user']->email()]);
    });

    $verifier = Str::random(64);
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

    // Step 1 — GET /oauth/authorize as a session-authenticated FILE user.
    $consent = $this->actingAs($user, 'web')->get('/oauth/authorize?'.http_build_query([
        'client_id' => $clientId,
        'redirect_uri' => $redirect,
        'response_type' => 'code',
        'scope' => 'mcp:use',
        'state' => 'test-state',
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
    ]));

    $consent->assertOk();
    expect($consent->json('user_email'))->toBe('file-user@site.test');

    // Step 2 — approve the consent screen.
    $approve = $this->actingAs($user, 'web')
        ->withoutMiddleware(ValidateCsrfToken::class)
        ->withSession($consentSession)
        ->post('/oauth/authorize', ['auth_token' => $consentSession['authToken']]);

    $approve->assertRedirect();
    parse_str(parse_url((string) $approve->headers->get('Location'), PHP_URL_QUERY), $query);

    expect($query)->toHaveKey('code');

    // Step 3 — exchange the code at /oauth/token (PKCE, public client).
    $exchange = $this->postJson('/oauth/token', [
        'grant_type' => 'authorization_code',
        'client_id' => $clientId,
        'redirect_uri' => $redirect,
        'code' => $query['code'],
        'code_verifier' => $verifier,
    ]);

    $exchange->assertOk();
    expect($exchange->json('token_type'))->toBe('Bearer')
        ->and($exchange->json('refresh_token'))->toBeString();

    // Step 4 — the issued bearer authenticates the FILE user through the
    // production middleware stack.
    $this->withHeaders(['Authorization' => 'Bearer '.$exchange->json('access_token')])
        ->postJson('/mcp-oauth-probe')
        ->assertOk()
        ->assertJson(['email' => 'file-user@site.test']);

    // The persisted token row carries the file user's UUID — proof the whole
    // chain never needed an Eloquent user.
    expect(Passport::token()->newQuery()->latest('created_at')->first()->user_id)->toBe($user->id());
});
