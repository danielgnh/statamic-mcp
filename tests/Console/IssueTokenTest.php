<?php

use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tokens\TokenRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Statamic\Facades\User;

beforeEach(function () {
    File::delete(storage_path('statamic/mcp/tokens.yaml'));
});

it('issues a token, prints it once, and prints ready-to-paste client snippets', function () {
    $user = Fixtures::makeUser();

    // Asserted via Artisan::output() rather than expectsOutputToContain():
    // PendingCommand matches each expected substring against individual
    // doWrite() calls first-match-wins, so two substrings inside the same
    // write (the pretty-printed Cursor JSON block) can never both match.
    $exit = Artisan::call('statamic:mcp:token', ['email' => $user->email(), '--name' => 'ci token']);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('ONLY time')
        // Claude Code one-liner, exactly as the docs spell it, with the real URL + token:
        ->toContain('claude mcp add --transport http statamic http://localhost/mcp/statamic --header "Authorization: Bearer mcp_')
        // Cursor mcp.json snippet:
        ->toContain('"mcpServers"')
        ->toContain('"Authorization": "Bearer mcp_')
        // Honest client-coverage note (spec §2/§5):
        ->toContain('claude.ai')
        ->toContain("'auth' => 'oauth'");

    $tokens = app(TokenRepository::class)->all();

    expect($tokens)->toHaveCount(1);

    $record = array_values($tokens)[0];

    expect($record['user'])->toBe((string) $user->id())
        ->and($record['name'])->toBe('ci token')
        ->and($record['expires_at'])->toBeNull();
});

it('records an expiry when --expires-days is given', function () {
    $this->travelTo(Carbon::parse('2026-07-09T12:00:00Z'));

    $user = Fixtures::makeUser();

    $this->artisan('statamic:mcp:token', ['email' => $user->email(), '--expires-days' => '30'])
        ->expectsOutputToContain('Expires: 2026-08-08T12:00:00+00:00')
        ->assertExitCode(0);

    $record = array_values(app(TokenRepository::class)->all())[0];

    expect($record['expires_at'])->toBe('2026-08-08T12:00:00+00:00');
});

it('fails for an unknown email without touching the token store', function () {
    $this->artisan('statamic:mcp:token', ['email' => 'ghost@site.test'])
        ->expectsOutputToContain('No user with email ghost@site.test')
        ->assertExitCode(1);

    expect(app(TokenRepository::class)->all())->toBe([]);
});

it('rejects a non-positive or non-numeric --expires-days', function (string $days) {
    $user = Fixtures::makeUser();

    $this->artisan('statamic:mcp:token', ['email' => $user->email(), '--expires-days' => $days])
        ->expectsOutputToContain('--expires-days must be a positive whole number')
        ->assertExitCode(1);

    expect(app(TokenRepository::class)->all())->toBe([]);
})->with(['zero' => '0', 'negative' => '-3', 'word' => 'soon']);

it("warns when the user lacks 'access mcp' but still issues the token", function () {
    tap(User::make()->email('bare@site.test'))->save(); // no roles at all

    $this->artisan('statamic:mcp:token', ['email' => 'bare@site.test'])
        ->expectsOutputToContain("does not have the 'access mcp' permission")
        ->assertExitCode(0);

    expect(app(TokenRepository::class)->all())->toHaveCount(1);
});
