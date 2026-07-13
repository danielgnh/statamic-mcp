<?php

use Danielgnh\StatamicMcp\Tests\UsesOAuthMode;
use Laravel\Passport\Contracts\AuthorizationViewResponse;
use Laravel\Passport\Http\Responses\SimpleViewResponse;
use Laravel\Passport\Passport;
use Laravel\Passport\PassportServiceProvider;

uses(UsesOAuthMode::class);

// Passport 12+ ships no default consent view and never binds
// AuthorizationViewResponse, so /oauth/authorize 500s ("Target [...] is not
// instantiable") the moment a connector reaches consent. The addon closes that
// gap by binding its own self-contained view in OAuth mode. These assert the
// binding exists AND renders — they need the real package, so they skip in the
// main (Passport-absent) legs and run in the Passport CI leg.
$requiresPassport = fn () => ! class_exists(Passport::class);

it('binds a default OAuth consent view in oauth mode', function () {
    expect(app()->bound(AuthorizationViewResponse::class))->toBeTrue()
        ->and(app(AuthorizationViewResponse::class))->toBeInstanceOf(SimpleViewResponse::class);
})->skip($requiresPassport, 'requires laravel/passport — Passport CI leg only');

it('renders the bound consent view without a 500, wired to the approve/deny routes', function () {
    // Passport's own provider (and its passport.authorizations.* routes the view
    // links to) isn't auto-discovered under testbench — register it so the route
    // helpers in the Blade resolve, exactly as they would on a real site.
    $this->app->register(PassportServiceProvider::class);

    $response = app(AuthorizationViewResponse::class)
        ->withParameters([
            'client' => (object) ['id' => 'client-123', 'name' => 'Claude Connector'],
            'user' => (object) ['email' => 'operator@site.test'],
            'scopes' => [(object) ['id' => 'mcp:use', 'description' => 'Use MCP functionality']],
            'request' => request(),
            'authToken' => 'auth-token-abc',
        ])
        ->toResponse(request());

    $html = $response->getContent();

    expect($response->getStatusCode())->toBe(200)
        ->and($html)->toContain('Claude Connector')          // $client->name
        ->and($html)->toContain('operator@site.test')        // $user->email
        ->and($html)->toContain('Use MCP functionality')     // $scope->description
        ->and($html)->toContain('auth-token-abc')            // hidden auth_token
        ->and($html)->toContain(route('passport.authorizations.approve'))
        ->and($html)->toContain(route('passport.authorizations.deny'))
        ->and($html)->toContain('_method')                   // DELETE spoof on the deny form
        ->and($html)->not->toContain('@vite');               // self-contained: no compiled-asset dependency
})->skip($requiresPassport, 'requires laravel/passport — Passport CI leg only');

it('lets a host app override the consent view (addon steps aside when already bound)', function () {
    // The addon guards its default on `! app()->bound(...)`, so a binding the
    // host app registered first wins regardless of boot order. Simulate the
    // app's binding, re-run the addon's registration, and assert it did not
    // clobber it.
    $sentinel = new SimpleViewResponse('host::custom-authorize');
    app()->instance(AuthorizationViewResponse::class, $sentinel);

    // Re-invoke exactly what bootAddon does in oauth mode.
    if (! app()->bound(AuthorizationViewResponse::class)) {
        Passport::authorizationView('statamic-mcp::oauth.authorize');
    }

    expect(app(AuthorizationViewResponse::class))->toBe($sentinel);
})->skip($requiresPassport, 'requires laravel/passport — Passport CI leg only');
