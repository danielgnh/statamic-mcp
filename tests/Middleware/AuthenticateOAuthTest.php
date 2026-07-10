<?php

use Danielgnh\StatamicMcp\Middleware\AuthenticateOAuth;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

// The delegate branch (every preflight prerequisite present) is unreachable
// over HTTP in this suite: the final prerequisite is class_exists(Passport)
// and laravel/passport is deliberately not installed — faking class_exists
// would be dishonest. These tests exercise the delegation unit directly
// through a test seam; the preflight itself is covered end-to-end in
// tests/Feature/OAuthMisconfigTest.php.

function oauthDelegate(): object
{
    return new class extends AuthenticateOAuth
    {
        public function delegate(Request $request, Closure $next)
        {
            return $this->authenticateViaApiGuard($request, $next);
        }
    };
}

beforeEach(function () {
    // Resolvable api guard without Passport; setUser never hits the provider.
    config(['auth.guards.api' => ['driver' => 'session', 'provider' => 'users']]);
});

it('never implements AuthenticatesRequests', function () {
    // Laravel's middleware priority sorter hoists AuthenticatesRequests
    // implementors above the configured pre-auth throttle — and would have
    // hoisted a bare auth:api above the oauth preflight (deviation #3).
    expect(is_subclass_of(AuthenticateOAuth::class, AuthenticatesRequests::class))->toBeFalse();
});

it('rejects an unauthenticated api guard with 401 and WWW-Authenticate', function () {
    $called = false;

    $response = oauthDelegate()->delegate(
        Request::create('/mcp/statamic', 'POST'),
        function () use (&$called) {
            $called = true;

            return response('never reached');
        },
    );

    expect($called)->toBeFalse()
        ->and($response->getStatusCode())->toBe(401)
        ->and($response->headers->get('WWW-Authenticate'))->toBe('Bearer');
});

it('passes through and pins the api guard as default when authenticated', function () {
    Fixtures::site();

    $user = Fixtures::makeUser();

    Auth::guard('api')->setUser($user);

    $response = oauthDelegate()->delegate(
        Request::create('/mcp/statamic', 'POST'),
        fn () => response('ok'),
    );

    expect($response->getContent())->toBe('ok')
        // shouldUse('api'): downstream (EnsureMcpPermission, Request::user())
        // must resolve from the guard that actually authenticated.
        ->and(Auth::getDefaultDriver())->toBe('api')
        ->and(Auth::user()->email())->toBe($user->email());
});
