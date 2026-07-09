<?php

use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tokens\TokenRepository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    File::delete(storage_path('statamic/mcp/tokens.yaml'));

    $this->repo = app(TokenRepository::class);
});

it('lists issued tokens with id, user email, name, and expiry', function () {
    $user = Fixtures::makeUser();
    $plain = $this->repo->issue($user, 'laptop', 30);

    // Asserted via Artisan::output() rather than expectsOutputToContain():
    // PendingCommand matches expected substrings against individual doWrite()
    // calls first-match-wins, and id, email, and name all live on the same
    // table-row line, so only the first substring would ever match.
    $exit = Artisan::call('statamic:mcp:tokens');
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain($plain->tokenId)
        ->toContain($user->email())
        ->toContain('laptop');
});

it('says so when no tokens exist', function () {
    $this->artisan('statamic:mcp:tokens')
        ->expectsOutputToContain('No MCP tokens issued')
        ->assertExitCode(0);
});

it('revokes a token by id', function () {
    $user = Fixtures::makeUser();
    $plain = $this->repo->issue($user);

    $this->artisan('statamic:mcp:token:revoke', ['id' => $plain->tokenId])
        ->expectsOutputToContain("Token {$plain->tokenId} revoked")
        ->assertExitCode(0);

    expect($this->repo->find($plain->tokenId))->toBeNull();
});

it('fails to revoke an unknown token id', function () {
    $this->artisan('statamic:mcp:token:revoke', ['id' => 'nope'])
        ->expectsOutputToContain('No token with id nope')
        ->assertExitCode(1);
});

it('doctor prints the endpoint and auth mode and warns about the locked door', function () {
    $this->artisan('statamic:mcp:doctor')
        ->expectsOutputToContain('http://localhost/mcp/statamic')
        ->expectsOutputToContain('Auth mode: token')
        ->expectsOutputToContain('locked door')
        ->assertExitCode(0);
});
