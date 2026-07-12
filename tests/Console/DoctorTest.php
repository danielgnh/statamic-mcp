<?php

use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tokens\TokenRepository;
use Illuminate\Support\Facades\File;
use Laravel\Passport\Passport;

beforeEach(function () {
    // Other tests in the run may have issued tokens into the shared
    // Testbench storage path — make the token state deterministic.
    File::delete(storage_path('statamic/mcp/tokens.yaml'));
});

it('reports a healthy token-mode setup with exit code 0', function () {
    $user = Fixtures::makeUser();
    app(TokenRepository::class)->issue($user, 'doctor-test');

    $this->artisan('statamic:mcp:doctor')
        ->expectsOutputToContain('Endpoint:  http://localhost/mcp/statamic')
        ->expectsOutputToContain('Auth mode: token')
        ->expectsOutputToContain('[ OK ] MCP is enabled.')
        ->expectsOutputToContain('[ OK ] MCP route is mounted.')
        ->expectsOutputToContain('[ OK ] Configured middleware resolves.')
        ->expectsOutputToContain('[ OK ] Token store is writable')
        ->expectsOutputToContain('token(s) issued.')
        ->expectsOutputToContain('No blocking problems found.')
        ->assertExitCode(0);
});

it('warns about the locked door when zero tokens exist', function () {
    $this->artisan('statamic:mcp:doctor')
        ->expectsOutputToContain('[WARN] No tokens issued — the endpoint is a locked door. Run: php please mcp:token you@site.com')
        ->assertExitCode(0);
});

it('warns when the server is disabled', function () {
    config(['statamic.mcp.enabled' => false]);

    $this->artisan('statamic:mcp:doctor')
        ->expectsOutputToContain('[WARN] MCP is disabled')
        ->assertExitCode(0);
});

it('fails with exit code 1 when the token store directory is not writable', function () {
    $dir = storage_path('statamic/mcp');
    File::ensureDirectoryExists($dir);

    chmod($dir, 0500);

    try {
        $this->artisan('statamic:mcp:doctor')
            ->expectsOutputToContain('[FAIL] Token store is not writable')
            ->expectsOutputToContain('Problems found. Fix the [FAIL] items above.')
            ->assertExitCode(1);
    } finally {
        chmod($dir, 0755);
    }
})->skip(fn () => function_exists('posix_geteuid') && posix_geteuid() === 0, 'chmod-based permission checks are a no-op for root');

it('fails when tokens.yaml exists but is not writable', function () {
    // Root-issued/deploy-user ownership mismatch: the ancestor-directory
    // probe passes but writes to the existing file itself would fail.
    app(TokenRepository::class)->issue(Fixtures::makeUser(), 'doctor-test');

    $file = storage_path('statamic/mcp/tokens.yaml');

    chmod($file, 0400);

    try {
        $this->artisan('statamic:mcp:doctor')
            ->expectsOutputToContain('[FAIL] '.$file.' exists but is not writable')
            ->assertExitCode(1);
    } finally {
        chmod($file, 0644);
    }
})->skip(fn () => function_exists('posix_geteuid') && posix_geteuid() === 0, 'chmod-based permission checks are a no-op for root');

it('fails without crashing when tokens.yaml is corrupt YAML', function () {
    // The mount-failure log sends operators here — the doctor must survive
    // the exact file states that break authentication.
    File::ensureDirectoryExists(storage_path('statamic/mcp'));
    File::put(storage_path('statamic/mcp/tokens.yaml'), "abc123:\n  user: [unclosed");

    $this->artisan('statamic:mcp:doctor')
        ->expectsOutputToContain('[FAIL] tokens.yaml is corrupt or unreadable')
        ->expectsOutputToContain('Problems found. Fix the [FAIL] items above.')
        ->assertExitCode(1);
});

it('fails without crashing when tokens.yaml parses to a scalar', function () {
    File::ensureDirectoryExists(storage_path('statamic/mcp'));
    File::put(storage_path('statamic/mcp/tokens.yaml'), 'just-a-string');

    $this->artisan('statamic:mcp:doctor')
        ->expectsOutputToContain('[FAIL] tokens.yaml is corrupt or unreadable')
        ->assertExitCode(1);
});

it('warns the locked door when every token is expired', function () {
    $this->travelTo(now()->subDays(10));
    app(TokenRepository::class)->issue(Fixtures::makeUser(), 'stale', 1);
    $this->travelBack();

    $this->artisan('statamic:mcp:doctor')
        ->expectsOutputToContain('[WARN] 1 token(s) issued but none are active (1 expired) — the endpoint is a locked door. Run: php please mcp:token you@site.com')
        ->assertExitCode(0);
});

it('warns the locked door when every token belongs to a deleted user', function () {
    $user = Fixtures::makeUser();
    app(TokenRepository::class)->issue($user, 'orphan');
    $user->delete();

    $this->artisan('statamic:mcp:doctor')
        ->expectsOutputToContain('[WARN] 1 token(s) issued but none are active (1 orphaned-user) — the endpoint is a locked door. Run: php please mcp:token you@site.com')
        ->assertExitCode(0);
});

it('breaks down mixed live and dead tokens while staying OK', function () {
    $repo = app(TokenRepository::class);

    $this->travelTo(now()->subDays(10));
    $repo->issue(Fixtures::makeUser(), 'stale', 1);
    $this->travelBack();

    $repo->issue(Fixtures::makeUser(), 'live');

    $this->artisan('statamic:mcp:doctor')
        ->expectsOutputToContain('[ OK ] 2 token(s) issued (1 active, 1 expired).')
        ->assertExitCode(0);
});

it('fails a middleware entry that is neither a class nor an alias', function () {
    config(['statamic.mcp.middleware' => ['throttle:60,1', 'App\\Http\\Middleware\\Nope']]);

    $this->artisan('statamic:mcp:doctor')
        ->expectsOutputToContain("[FAIL] Configured middleware 'App\\Http\\Middleware\\Nope' is neither a class nor a registered middleware alias or group")
        ->assertExitCode(1);
});

it('fails oauth mode naming every missing prerequisite', function () {
    config(['statamic.mcp.auth' => 'oauth']);

    // No short-circuiting: all three prerequisites are reported at once.
    $this->artisan('statamic:mcp:doctor')
        ->expectsOutputToContain('Auth mode: oauth')
        ->expectsOutputToContain('Laravel Passport is not installed')
        ->expectsOutputToContain('Users are file-based')
        ->expectsOutputToContain("No 'api' guard is defined — Laravel 12 and 13 ship none")
        ->assertExitCode(1);
})->skip(fn () => class_exists(Passport::class), 'asserts Passport absence — skipped in the Passport CI leg');

it('names the exact api guard config to add', function () {
    config(['statamic.mcp.auth' => 'oauth']);

    $this->artisan('statamic:mcp:doctor')
        ->expectsOutputToContain("'api' => ['driver' => 'passport', 'provider' => 'users']")
        ->assertExitCode(1);
});

it('fails when the api guard exists but is not passport-driven', function () {
    config([
        'statamic.mcp.auth' => 'oauth',
        'auth.guards.api' => ['driver' => 'session', 'provider' => 'users'],
    ]);

    $this->artisan('statamic:mcp:doctor')
        ->expectsOutputToContain("[FAIL] The 'api' guard uses the 'session' driver, not 'passport'")
        ->assertExitCode(1);
});

it('resolves the users repository driver, not the repository name', function () {
    config([
        'statamic.mcp.auth' => 'oauth',
        'statamic.users.repository' => 'custom',
        'statamic.users.repositories.custom.driver' => 'file',
    ]);

    $this->artisan('statamic:mcp:doctor')
        ->expectsOutputToContain('Users are file-based')
        ->assertExitCode(1);
});

it('fails the users check for any non-eloquent driver', function () {
    // Mirror of AuthenticateOAuth's !== 'eloquent' predicate: not-file is
    // not good enough.
    config([
        'statamic.mcp.auth' => 'oauth',
        'statamic.users.repository' => 'custom',
        'statamic.users.repositories.custom.driver' => 'mongodb',
    ]);

    $this->artisan('statamic:mcp:doctor')
        ->expectsOutputToContain("[FAIL] Users use the 'mongodb' driver (repository: custom)")
        ->assertExitCode(1);
});

it('passes the users and guard checks independently of Passport', function () {
    config([
        'statamic.mcp.auth' => 'oauth',
        'statamic.users.repository' => 'eloquent',
        'statamic.users.repositories.eloquent.driver' => 'eloquent',
        'auth.guards.api' => ['driver' => 'passport', 'provider' => 'users'],
    ]);

    $this->artisan('statamic:mcp:doctor')
        ->expectsOutputToContain('[ OK ] Users are database-backed (repository: eloquent, driver: eloquent).')
        ->expectsOutputToContain("[ OK ] The 'api' guard uses the passport driver.")
        ->expectsOutputToContain('Laravel Passport is not installed')
        // The HasApiTokens check only runs once Passport is present — without
        // it the Passport [FAIL] already owns that remedy (T27 CI leg pins it).
        ->doesntExpectOutputToContain('HasApiTokens')
        ->assertExitCode(1);
})->skip(fn () => class_exists(Passport::class), 'asserts Passport absence — skipped in the Passport CI leg');

it('warns when APP_URL is the Laravel default', function () {
    $this->artisan('statamic:mcp:doctor')
        ->expectsOutputToContain("[WARN] APP_URL is Laravel's default (http://localhost)")
        ->assertExitCode(0);
});

it('warns when APP_URL is plain http', function () {
    config(['app.url' => 'http://example.com']);

    $this->artisan('statamic:mcp:doctor')
        ->expectsOutputToContain('[WARN] APP_URL is plain http — Bearer tokens over http travel unencrypted')
        ->assertExitCode(0);
});

it('does not warn about the URL when APP_URL is https', function () {
    config(['app.url' => 'https://example.com']);

    $this->artisan('statamic:mcp:doctor')
        ->doesntExpectOutputToContain('[WARN] APP_URL')
        ->assertExitCode(0);
});
