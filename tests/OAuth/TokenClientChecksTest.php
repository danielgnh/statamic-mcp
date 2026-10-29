<?php

use Danielgnh\StatamicMcp\Tests\RegistersOAuthProbeRoute;
use Danielgnh\StatamicMcp\Tests\Support\HostApiUser;
use Danielgnh\StatamicMcp\Tests\Support\OAuthFixtures;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Bridge\AccessToken;
use Laravel\Passport\Bridge\Client;
use Laravel\Passport\Bridge\Scope;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use Laravel\Passport\PersonalAccessTokenFactory;
use League\OAuth2\Server\CryptKey;
use Statamic\Facades\User;

uses(RegistersOAuthProbeRoute::class);

function initializeWithBearer(string $bearer): TestResponse
{
    return test()->withHeaders([
        'Authorization' => 'Bearer '.$bearer,
        'Accept' => 'application/json, text/event-stream',
    ])->postJson('/mcp/statamic', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2025-11-25',
            'capabilities' => (object) [],
            'clientInfo' => ['name' => 'pest', 'version' => '1.0.0'],
        ],
    ]);
}

beforeEach(function () {
    $this->keyDir = sys_get_temp_dir().'/mcp-client-checks-'.Str::random(8);
    @mkdir($this->keyDir, 0700, true);

    $rsa = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($rsa, $private);
    file_put_contents($this->keyDir.'/oauth-private.key', $private);
    file_put_contents($this->keyDir.'/oauth-public.key', openssl_pkey_get_details($rsa)['key']);
    chmod($this->keyDir.'/oauth-private.key', 0600);

    Passport::loadKeysFrom($this->keyDir);

    OAuthFixtures::migratePassport();

    Schema::create('customers', function (Blueprint $table) {
        $table->id();
        $table->string('email');
        $table->string('password');
        $table->timestamps();
    });

    config([
        'auth.providers.customers' => ['driver' => 'eloquent', 'model' => HostApiUser::class],
        'auth.guards.customer-api' => ['driver' => 'passport', 'provider' => 'customers'],
    ]);

    User::make()->id('1')->email('admin@example.com')->makeSuper()->save();

    $this->customer = HostApiUser::create(['email' => 'customer@example.com', 'password' => Hash::make('secret')]);
});

afterEach(function () {
    array_map(unlink(...), glob($this->keyDir.'/*') ?: []);
    @rmdir($this->keyDir);
    Passport::$keyPath = null;
});

it('refuses a personal access token whose client is bound to another user provider', function () {
    app(ClientRepository::class)->createPersonalAccessGrantClient('Host API', 'customers');

    $bearer = $this->customer->createToken('host api', ['*'])->accessToken;

    expect($this->customer->getKey())->toBe(1);

    initializeWithBearer($bearer)->assertUnauthorized();
});

it('refuses a password grant token whose client is bound to another user provider', function () {
    Passport::enablePasswordGrant();

    $client = app(ClientRepository::class)->createPasswordGrantClient('Host Mobile App', 'customers');

    $bearer = $this->postJson('/oauth/token', [
        'grant_type' => 'password',
        'client_id' => $client->getKey(),
        'username' => 'customer@example.com',
        'password' => 'secret',
        'scope' => 'mcp:use',
    ])->assertOk()->json('access_token');

    initializeWithBearer($bearer)->assertUnauthorized();
});

it('refuses a personal access token from a client with no provider when the api guard serves another user provider', function () {
    config(['auth.guards.api' => ['driver' => 'passport', 'provider' => 'customers']]);

    app(ClientRepository::class)->createPersonalAccessGrantClient('Host API');

    $bearer = $this->customer->createToken('host api', ['*'])->accessToken;

    initializeWithBearer($bearer)->assertUnauthorized();
});

it('refuses a password grant token from a client with no provider when the api guard serves another user provider', function () {
    config(['auth.guards.api' => ['driver' => 'passport', 'provider' => 'customers']]);
    Passport::enablePasswordGrant();

    $client = app(ClientRepository::class)->createPasswordGrantClient('Host Mobile App');

    $bearer = $this->postJson('/oauth/token', [
        'grant_type' => 'password',
        'client_id' => $client->getKey(),
        'username' => 'customer@example.com',
        'password' => 'secret',
        'scope' => 'mcp:use',
    ])->assertOk()->json('access_token');

    initializeWithBearer($bearer)->assertUnauthorized();
});

it('accepts a token from a dynamically registered client when the api guard serves another user provider', function () {
    config(['auth.guards.api' => ['driver' => 'passport', 'provider' => 'customers']]);

    $redirect = 'https://client.example.com/callback';
    $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient('Claude', [$redirect], false);

    $token = new AccessToken('1', [new Scope('mcp:use')], new Client($client->getKey(), 'Claude', [$redirect]));
    $token->setIdentifier(Str::random(40));
    $token->setExpiryDateTime(new DateTimeImmutable('+1 hour'));
    $token->setPrivateKey(new CryptKey(Passport::keyPath('oauth-private.key'), null, false));

    OAuthFixtures::accessToken('1', $client->getKey(), ['id' => $token->getIdentifier()]);

    initializeWithBearer($token->toString())->assertOk();
});

it('refuses a token from a client with no provider when passport signs in another user provider at consent', function () {
    config([
        'auth.guards.customer-web' => ['driver' => 'session', 'provider' => 'customers'],
        'passport.guard' => 'customer-web',
    ]);

    $redirect = 'https://client.example.com/callback';
    $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient('Partner App', [$redirect], false);

    $token = new AccessToken((string) $this->customer->getKey(), [new Scope('mcp:use')], new Client($client->getKey(), 'Partner App', [$redirect]));
    $token->setIdentifier(Str::random(40));
    $token->setExpiryDateTime(new DateTimeImmutable('+1 hour'));
    $token->setPrivateKey(new CryptKey(Passport::keyPath('oauth-private.key'), null, false));

    OAuthFixtures::accessToken((string) $this->customer->getKey(), $client->getKey(), ['id' => $token->getIdentifier()]);

    initializeWithBearer($token->toString())->assertUnauthorized();
});

it('accepts a token whose client is bound to the provider statamic users come from', function () {
    app(ClientRepository::class)->createPersonalAccessGrantClient('Statamic Agents', 'users');

    $bearer = app(PersonalAccessTokenFactory::class)->make('1', 'agent', ['mcp:use'], 'users')->accessToken;

    initializeWithBearer($bearer)->assertOk();
});

it('refuses a client credentials token even when a statamic user has the client id', function () {
    $client = app(ClientRepository::class)->createClientCredentialsGrantClient('Host Worker');

    User::make()->id((string) $client->getKey())->email('worker@example.com')->makeSuper()->save();

    $bearer = $this->postJson('/oauth/token', [
        'grant_type' => 'client_credentials',
        'client_id' => $client->getKey(),
        'client_secret' => $client->plainSecret,
        'scope' => 'mcp:use',
    ])->assertOk()->json('access_token');

    initializeWithBearer($bearer)->assertUnauthorized();
});
