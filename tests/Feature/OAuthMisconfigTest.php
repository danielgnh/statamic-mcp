<?php

use Danielgnh\StatamicMcp\Middleware\AuthenticateOAuth;
use Danielgnh\StatamicMcp\Tests\UsesOAuthMode;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Passport;

uses(UsesOAuthMode::class);

// The preflight reads config at request time, so runtime config() honestly
// simulates a site working through the prerequisites one by one. Passport is
// deliberately absent from require-dev, so class_exists() is genuinely false in
// the main CI leg; the one test that asserts that absence skips itself when the
// Passport CI leg installs the package. Checking the config prerequisites first
// keeps every 503 branch reachable.

function misconfiguredOAuthInitialize($test): TestResponse
{
    return $test->postJson('/mcp/statamic', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
    ]);
}

it('answers 503 on the MCP route naming the missing Eloquent-users prerequisite', function () {
    // The app booted in setUp() with 'auth' => 'oauth' and zero OAuth
    // prerequisites installed — reaching this line at all proves
    // bootAddon() did not throw (spec §5: misconfig never bricks the site).
    expect(config('statamic.mcp.auth'))->toBe('oauth')
        ->and(config('statamic.users.repository'))->toBe('file');

    $response = misconfiguredOAuthInitialize($this);

    $response
        ->assertStatus(503)
        ->assertHeader('Retry-After', '60') // RFC 9110 pacing for well-behaved retry clients
        ->assertJsonPath('error', 'MCP OAuth mode is misconfigured.');

    expect($response->json('remedy'))
        ->toContain('eloquent:import-users')
        ->toContain("'auth' => 'token'");

    // T24's doctor checks the same prerequisites without short-circuiting.
    expect($response->json('doctor'))->toContain('mcp:doctor');
});

it('rejects a file-driven users repository regardless of its name', function () {
    // The repository NAME proves nothing — the preflight tests the RESOLVED
    // driver, so a file-driven repository called 'custom' still 503s here
    // instead of failing confusingly after OAuth setup completes.
    config([
        'statamic.users.repository' => 'custom',
        'statamic.users.repositories.custom.driver' => 'file',
    ]);

    $response = misconfiguredOAuthInitialize($this);

    $response
        ->assertStatus(503)
        ->assertJsonPath('error', 'MCP OAuth mode is misconfigured.');

    expect($response->json('remedy'))
        ->toContain('eloquent:import-users');
});

it('names the missing api guard once users are eloquent', function () {
    config(['statamic.users.repository' => 'eloquent']);

    $response = misconfiguredOAuthInitialize($this);

    $response
        ->assertStatus(503)
        ->assertJsonPath('error', 'MCP OAuth mode is misconfigured.');

    expect($response->json('remedy'))
        ->toContain("'api' guard")
        ->toContain('config/auth.php');
});

it('rejects an api guard whose driver is not passport', function () {
    // Presence alone is a trap: a pre-existing session/token/sanctum 'api'
    // guard would pass preflight once Passport lands, let discovery and token
    // issuance complete (Passport's routes work), then 401-loop forever on
    // tokens the guard ignores — misconfiguration presenting as credential
    // failure. The remedy already prescribes the passport driver.
    config([
        'statamic.users.repository' => 'eloquent',
        'auth.guards.api' => ['driver' => 'session', 'provider' => 'users'],
    ]);

    $response = misconfiguredOAuthInitialize($this);

    $response
        ->assertStatus(503)
        ->assertJsonPath('error', 'MCP OAuth mode is misconfigured.');

    expect($response->json('remedy'))
        ->toContain("'driver' => 'passport'");
});

it('names Passport when only the package is missing', function () {
    config([
        'statamic.users.repository' => 'eloquent',
        'auth.guards.api' => ['driver' => 'passport', 'provider' => 'users'],
    ]);

    expect(class_exists(Passport::class))->toBeFalse();

    $response = misconfiguredOAuthInitialize($this);

    $response
        ->assertStatus(503)
        ->assertJsonPath('error', 'MCP OAuth mode is misconfigured.');

    expect($response->json('remedy'))
        ->toContain('laravel/passport')
        ->toContain("'auth' => 'token'");
})->skip(fn () => class_exists(Passport::class), 'asserts Passport absence — skipped in the Passport CI leg');

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
