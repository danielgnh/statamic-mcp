<?php

use Danielgnh\StatamicMcp\Tokens\PlainToken;
use Danielgnh\StatamicMcp\Tokens\TokenRepository;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\File;
use Statamic\Facades\User;
use Statamic\Facades\YAML;
use Statamic\Yaml\ParseException;

beforeEach(function () {
    // Testbench's storage dir survives between tests — start clean every time.
    File::delete(storage_path('statamic/mcp/tokens.yaml'));

    $this->repo = app(TokenRepository::class);
    $this->user = tap(User::make()->email('claude@site.test'))->save();
});

afterEach(function () {
    // storage_path() resolves into vendor/orchestra — leave nothing behind.
    File::deleteDirectory(storage_path('statamic/mcp'));
});

it('issues a token in the mcp_{tokenId}_{secret} format', function () {
    $plain = $this->repo->issue($this->user);

    expect($plain)->toBeInstanceOf(PlainToken::class);

    $parts = explode('_', (string) $plain->token, 3);

    expect($parts)->toHaveCount(3)
        ->and($parts[0])->toBe('mcp')
        ->and($parts[1])->toBe($plain->tokenId)
        ->and(strlen($parts[2]))->toBe(40)
        ->and($plain->userId)->toBe((string) $this->user->id())
        ->and($plain->expiresAt)->toBeNull();
});

it('stores only the sha-256 hash in tokens.yaml — never the plaintext secret', function () {
    $plain = $this->repo->issue($this->user, 'laptop');

    $secret = explode('_', (string) $plain->token, 3)[2];

    $raw = File::get(storage_path('statamic/mcp/tokens.yaml'));

    expect(str_contains($raw, $secret))->toBeFalse()
        ->and(str_contains($raw, (string) $plain->token))->toBeFalse();

    $parsed = YAML::parse($raw);

    expect($parsed)->toHaveKey($plain->tokenId)
        ->and($parsed[$plain->tokenId]['hash'])->toBe(hash('sha256', $secret))
        ->and($parsed[$plain->tokenId]['user'])->toBe((string) $this->user->id())
        ->and($parsed[$plain->tokenId]['name'])->toBe('laptop')
        ->and($parsed[$plain->tokenId]['expires_at'])->toBeNull()
        ->and($parsed[$plain->tokenId]['created_at'])->not->toBeNull();
});

it('records an iso-8601 expiry when issued with expires days', function () {
    $this->travelTo(Date::parse('2026-07-09T12:00:00Z'));

    $plain = $this->repo->issue($this->user, null, 30);

    expect($plain->expiresAt->toIso8601String())->toBe('2026-08-08T12:00:00+00:00');

    $record = $this->repo->find($plain->tokenId);

    expect($record['expires_at'])->toBe('2026-08-08T12:00:00+00:00');
});

it('finds a token record by id', function () {
    $plain = $this->repo->issue($this->user, 'laptop');

    $record = $this->repo->find($plain->tokenId);

    expect($record)->not->toBeNull()
        ->and($record['user'])->toBe((string) $this->user->id())
        ->and($record['name'])->toBe('laptop');
});

it('returns null for an unknown token id', function () {
    expect($this->repo->find('doesnotexist'))->toBeNull();
});

it('revokes a token and rewrites the file without it, leaving others intact', function () {
    $keep = $this->repo->issue($this->user, 'keep');
    $kill = $this->repo->issue($this->user, 'kill');

    expect($this->repo->revoke($kill->tokenId))->toBeTrue()
        ->and($this->repo->find($kill->tokenId))->toBeNull()
        ->and($this->repo->find($keep->tokenId))->not->toBeNull();

    $raw = File::get(storage_path('statamic/mcp/tokens.yaml'));

    expect(str_contains($raw, (string) $kill->tokenId))->toBeFalse()
        ->and(str_contains($raw, (string) $keep->tokenId))->toBeTrue();
});

it('returns false when revoking an unknown token id', function () {
    expect($this->repo->revoke('doesnotexist'))->toBeFalse();
});

it('throws loudly when tokens.yaml is corrupt instead of treating it as empty', function () {
    File::ensureDirectoryExists(storage_path('statamic/mcp'));
    File::put(storage_path('statamic/mcp/tokens.yaml'), 'just a string');

    // A corrupt auth store must fail closed and loud — never an empty token list.
    expect(fn () => $this->repo->all())->toThrow(ParseException::class);
});

it('returns all issued tokens keyed by token id', function () {
    $a = $this->repo->issue($this->user, 'a');
    $b = $this->repo->issue($this->user, 'b');

    $all = $this->repo->all();

    expect($all)->toHaveCount(2)
        ->and($all)->toHaveKeys([$a->tokenId, $b->tokenId]);
});
