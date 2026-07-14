<?php

use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tests\Support\OAuthFixtures;
use Danielgnh\StatamicMcp\Tokens\TokenRepository;
use Illuminate\Support\Facades\Artisan;
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

// No user-repository or api-guard checks in oauth mode anymore: the addon's
// own guard authenticates file users and Eloquent users alike, so the doctor
// checks what actually matters — Passport, its keys, and its tables.

it('fails oauth mode naming every missing prerequisite at once', function () {
    config(['statamic.mcp.auth' => 'oauth']);

    // Passport is installed (require-dev), keys and tables are not — both are
    // reported in the same run, no short-circuiting.
    $this->artisan('statamic:mcp:doctor')
        ->expectsOutputToContain('Auth mode: oauth')
        ->expectsOutputToContain('[ OK ] Laravel Passport is installed.')
        // One expectation on purpose: a written line satisfies only the first
        // matching expectsOutputToContain, so the remedy rides along here.
        ->expectsOutputToContain("[FAIL] Passport's encryption keys are missing. Run 'php please mcp:keys'")
        ->expectsOutputToContain("[FAIL] Passport's tables are missing")
        ->assertExitCode(1);
});

it('accepts keys provided via environment config instead of key files', function () {
    config([
        'statamic.mcp.auth' => 'oauth',
        'passport.private_key' => '-----BEGIN RSA PRIVATE KEY-----fake',
        'passport.public_key' => '-----BEGIN PUBLIC KEY-----fake',
    ]);

    $this->artisan('statamic:mcp:doctor')
        ->expectsOutputToContain('[ OK ] Passport encryption keys are available.')
        ->assertExitCode(1); // tables still missing
});

it('fails when Passport tables exist but user_id columns are integers', function () {
    config([
        'statamic.mcp.auth' => 'oauth',
        'passport.private_key' => 'fake',
        'passport.public_key' => 'fake',
    ]);

    OAuthFixtures::migratePassportWithBigintUserIds();

    $this->artisan('statamic:mcp:doctor')
        ->expectsOutputToContain("[FAIL] Passport's user_id columns are integers, but Statamic ids are UUID strings")
        ->assertExitCode(1);
});

it('passes the table check once user_id columns are string-typed', function () {
    config([
        'statamic.mcp.auth' => 'oauth',
        'passport.private_key' => 'fake',
        'passport.public_key' => 'fake',
    ]);

    OAuthFixtures::migratePassport();

    $this->artisan('statamic:mcp:doctor')
        ->expectsOutputToContain("[ OK ] Passport tables exist and user_id columns fit Statamic's ids.");
});

// With Passport present, doctor must catch the consent-view gap that every
// other check is blind to — Passport binds no default, so an unbound view
// means /oauth/authorize 500s while the rest reports green.
it('fails when no consent view is bound', function () {
    config(['statamic.mcp.auth' => 'oauth']);

    // This suite boots in token mode, so the addon never bound its default —
    // the exact false-green scenario Passport 13 introduces.
    $this->artisan('statamic:mcp:doctor')
        ->expectsOutputToContain('[FAIL] No OAuth consent view is bound')
        ->assertExitCode(1);
});

it('reports the consent view as OK once one is bound', function () {
    config(['statamic.mcp.auth' => 'oauth']);

    Passport::authorizationView('statamic-mcp::oauth.authorize');

    $this->artisan('statamic:mcp:doctor')
        ->expectsOutputToContain('[ OK ] OAuth consent view is bound.');
});

it('warns about a leftover duplicate auth-tables migration', function () {
    $dir = database_path('migrations');
    File::ensureDirectoryExists($dir);

    $kept = $dir.'/2026_07_13_100000_statamic_auth_tables.php';
    $orphan = $dir.'/2026_07_13_155644_statamic_auth_tables.php';
    File::put($kept, '<?php // first');
    File::put($orphan, '<?php // orphan');

    // The whole warning is one line, so assert against the captured output
    // directly — expectsOutputToContain consumes a line once matched and can't
    // match several substrings on the same line.
    try {
        $exit = Artisan::call('statamic:mcp:doctor');
        $output = Artisan::output();
    } finally {
        File::delete([$kept, $orphan]);
    }

    expect($exit)->toBe(0)
        ->and($output)->toContain('2026_07_13_155644_statamic_auth_tables.php')
        ->and($output)->toContain('looks like a leftover from an interrupted OAuth setup')
        // The oldest file is the one that actually ran — keep it, drop the rest.
        ->and($output)->toContain('keeping 2026_07_13_100000_statamic_auth_tables.php');
});

it('stays silent when only one auth-tables migration exists', function () {
    $dir = database_path('migrations');
    File::ensureDirectoryExists($dir);

    $only = $dir.'/2026_07_13_100000_statamic_auth_tables.php';
    File::put($only, '<?php // healthy single migration');

    try {
        $this->artisan('statamic:mcp:doctor')
            ->doesntExpectOutputToContain('leftover from an interrupted OAuth setup')
            ->assertExitCode(0);
    } finally {
        File::delete($only);
    }
});

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
