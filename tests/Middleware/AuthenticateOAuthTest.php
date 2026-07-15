<?php

use Danielgnh\StatamicMcp\Middleware\AuthenticateOAuth;
use Danielgnh\StatamicMcp\OAuth\PassportBearerGuard;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

// These tests drive the real handle() path. The guard is wired exactly as the
// ServiceProvider wires it in OAuth mode (this suite boots in token mode);
// setUser() on the guard stands in for a validated bearer, and the scopes
// request attribute stands in for what the guard stashes after validating one.
// The bearer-validation leg itself is covered end-to-end in
// tests/OAuth/FileUserOAuthTest.php with real signed tokens.

function oauthHandle(Request $request): Response
{
    return (new AuthenticateOAuth)->handle($request, fn () => response('ok'));
}

beforeEach(function () {
    Auth::viaRequest(PassportBearerGuard::DRIVER, new PassportBearerGuard);
    config([
        'auth.guards.'.PassportBearerGuard::GUARD => ['driver' => PassportBearerGuard::DRIVER, 'provider' => null],
        // Satisfy the keys preflight without touching the filesystem.
        'passport.private_key' => '-----BEGIN RSA PRIVATE KEY-----fake',
        'passport.public_key' => '-----BEGIN PUBLIC KEY-----fake',
    ]);
});

it('never implements AuthenticatesRequests', function () {
    // Laravel's middleware priority sorter hoists AuthenticatesRequests
    // implementors above the configured pre-auth throttle — and would have
    // hoisted plain auth middleware above the oauth preflight.
    expect(is_subclass_of(AuthenticateOAuth::class, AuthenticatesRequests::class))->toBeFalse();
});

it('answers 503 with the keys remedy when Passport keys are missing', function () {
    config(['passport.private_key' => null, 'passport.public_key' => null]);

    $response = oauthHandle(Request::create('/mcp/statamic', 'POST'));

    expect($response->getStatusCode())->toBe(503)
        ->and($response->headers->get('Retry-After'))->toBe('60')
        ->and(json_decode($response->getContent(), true)['remedy'])
        ->toContain('php artisan migrate')
        ->toContain('mcp:keys');
});

it('rejects an unauthenticated request with 401 and WWW-Authenticate', function () {
    // No bearer token: the guard resolves null.
    $response = oauthHandle(Request::create('/mcp/statamic', 'POST'));

    expect($response->getStatusCode())->toBe(401)
        // rewritten with resource_metadata by laravel/mcp's
        // AddWwwAuthenticateHeader on the real route
        ->and($response->headers->get('WWW-Authenticate'))->toBe('Bearer');
});

it('passes through and pins the addon guard as default when the token grants mcp:use', function () {
    $user = Fixtures::makeSuper();

    Auth::guard(PassportBearerGuard::GUARD)->setUser($user);

    $request = Request::create('/mcp/statamic', 'POST');
    $request->attributes->set(PassportBearerGuard::SCOPES_ATTRIBUTE, ['mcp:use']);

    $response = oauthHandle($request);

    expect($response->getContent())->toBe('ok')
        // shouldUse: downstream (EnsureMcpPermission, Request::user(),
        // User::current()) must resolve from the guard that authenticated.
        ->and(Auth::getDefaultDriver())->toBe(PassportBearerGuard::GUARD)
        ->and(Auth::user()->email())->toBe($user->email());
});

it('accepts the Passport * superscope', function () {
    Auth::guard(PassportBearerGuard::GUARD)->setUser(Fixtures::makeSuper());

    $request = Request::create('/mcp/statamic', 'POST');
    $request->attributes->set(PassportBearerGuard::SCOPES_ATTRIBUTE, ['*']);

    expect(oauthHandle($request)->getContent())->toBe('ok');
});

it('rejects an authenticated token that lacks the mcp:use scope with 403 insufficient_scope', function () {
    // A valid Passport token minted for some other first-party client — passes
    // the guard, but carries no mcp:use scope, so it must not reach the server.
    Auth::guard(PassportBearerGuard::GUARD)->setUser(Fixtures::makeSuper());

    $request = Request::create('/mcp/statamic', 'POST');
    $request->attributes->set(PassportBearerGuard::SCOPES_ATTRIBUTE, ['some-other-scope']);

    $response = oauthHandle($request);

    expect($response->getStatusCode())->toBe(403)
        ->and($response->headers->get('WWW-Authenticate'))->toContain('insufficient_scope');
});

it('rejects an authenticated user with no scopes attribute at all', function () {
    // Defence in depth: a user on the guard without the guard having stashed
    // scopes (impossible via the real driver) must still not pass.
    Auth::guard(PassportBearerGuard::GUARD)->setUser(Fixtures::makeSuper());

    expect(oauthHandle(Request::create('/mcp/statamic', 'POST'))->getStatusCode())->toBe(403);
});
