<?php

use Danielgnh\StatamicMcp\Middleware\AuthenticateOAuth;
use Danielgnh\StatamicMcp\Tests\UsesOAuthMode;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

uses(UsesOAuthMode::class);

// The preflight reads config at request time, so runtime config() honestly
// simulates a site working through the prerequisites one by one. Passport is
// installed (require-dev) but no keys are configured in the test env, so the
// keys branch is the naturally-reachable 503.

function misconfiguredOAuthInitialize($test): TestResponse
{
    return $test->postJson('/mcp/statamic', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
    ]);
}

it('answers 503 on the MCP route naming the missing keys prerequisite', function () {
    // The app booted in setUp() with 'auth' => 'oauth' and no Passport keys —
    // reaching this line at all proves bootAddon() did not throw (misconfig
    // never bricks the site).
    expect(config('statamic.mcp.auth'))->toBe('oauth');

    $response = misconfiguredOAuthInitialize($this);

    $response
        ->assertStatus(503)
        ->assertHeader('Retry-After', '60') // RFC 9110 pacing for well-behaved retry clients
        ->assertJsonPath('error', 'MCP OAuth mode is misconfigured.');

    expect($response->json('remedy'))
        ->toContain('php artisan migrate')
        ->toContain('mcp:keys');

    // The doctor checks the same prerequisites without short-circuiting.
    expect($response->json('doctor'))->toContain('mcp:doctor');
});

it('reaches authentication once keys are configured, and 401s without a bearer', function () {
    // Keys via config — the PASSPORT_PRIVATE_KEY env path. With the preflight
    // satisfied, the addon guard runs: no bearer token → clean 401, proving
    // the guard is wired on the route without any config/auth.php edit.
    config([
        'passport.private_key' => '-----BEGIN RSA PRIVATE KEY-----fake',
        'passport.public_key' => '-----BEGIN PUBLIC KEY-----fake',
    ]);

    misconfiguredOAuthInitialize($this)
        ->assertStatus(401)
        ->assertJsonPath('error', 'Unauthenticated.');
});

it('leaves every other route of the site untouched', function () {
    // Structural: the preflight is mounted on the MCP route and nowhere else.
    $routesWithPreflight = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => in_array(AuthenticateOAuth::class, $route->gatherMiddleware(), true))
        ->map(fn ($route) => $route->uri())
        ->unique()
        ->values()
        ->all();

    expect($routesWithPreflight)->toBe(['mcp/statamic']);

    // Behavioral: the frontend catch-all still answers — a plain 404 for an
    // unknown page, never the oauth 503. (A route registered inside a test
    // body would land behind the catch-all, hence the catch-all itself is
    // the probe.)
    $this->get('/not-mcp')->assertNotFound();
});
