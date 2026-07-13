<?php

use Danielgnh\StatamicMcp\Middleware\AuthenticateOAuth;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Auth\User as AuthUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Stand-in for what Passport hands the api guard: an Eloquent Authenticatable
 * with HasApiTokens' tokenCan(). $scopes controls which scopes the token grants.
 */
function oauthTokenUser(array $scopes): AuthUser
{
    return new class($scopes) extends AuthUser
    {
        /** @param  list<string>  $scopes */
        public function __construct(public array $scopes) {}

        public function email(): string
        {
            return 'scoped@site.test';
        }

        public function tokenCan(string $scope): bool
        {
            return in_array('*', $this->scopes, true) || in_array($scope, $this->scopes, true);
        }
    };
}

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
        // rewritten with resource_metadata by laravel/mcp's
        // AddWwwAuthenticateHeader on the real route — see the Passport CI leg
        ->and($response->headers->get('WWW-Authenticate'))->toBe('Bearer');
});

it('passes through and pins the api guard as default when the token grants mcp:use', function () {
    Auth::guard('api')->setUser(oauthTokenUser(['mcp:use']));

    $response = oauthDelegate()->delegate(
        Request::create('/mcp/statamic', 'POST'),
        fn () => response('ok'),
    );

    expect($response->getContent())->toBe('ok')
        // shouldUse('api'): downstream (EnsureMcpPermission, Request::user())
        // must resolve from the guard that actually authenticated.
        ->and(Auth::getDefaultDriver())->toBe('api')
        ->and(Auth::user()->email())->toBe('scoped@site.test');
});

it('accepts the Passport * superscope', function () {
    Auth::guard('api')->setUser(oauthTokenUser(['*']));

    $response = oauthDelegate()->delegate(
        Request::create('/mcp/statamic', 'POST'),
        fn () => response('ok'),
    );

    expect($response->getContent())->toBe('ok');
});

it('rejects an authenticated token that lacks the mcp:use scope with 403 insufficient_scope', function () {
    $called = false;

    // A valid Passport token minted for some other first-party client — passes
    // the guard, but carries no mcp:use scope, so it must not reach the server.
    Auth::guard('api')->setUser(oauthTokenUser(['some-other-scope']));

    $response = oauthDelegate()->delegate(
        Request::create('/mcp/statamic', 'POST'),
        function () use (&$called) {
            $called = true;

            return response('never reached');
        },
    );

    expect($called)->toBeFalse()
        ->and($response->getStatusCode())->toBe(403)
        ->and($response->headers->get('WWW-Authenticate'))->toContain('insufficient_scope');
});
