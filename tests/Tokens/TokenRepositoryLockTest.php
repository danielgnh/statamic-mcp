<?php

use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tokens\TokenRepository;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    config(['cache.default' => 'array']);
    File::delete(storage_path('statamic/mcp/tokens.yaml'));
});

it('refuses to issue while another writer holds the token-store lock', function () {
    $user = Fixtures::makeUser();

    expect(Cache::lock('statamic-mcp-token-store', 10)->get())->toBeTrue();

    $repository = new TokenRepository(lockWaitSeconds: 0);

    expect(fn () => $repository->issue($user))->toThrow(LockTimeoutException::class)
        ->and($repository->all())->toBeEmpty(); // fail closed: nothing written
});

it('refuses to revoke while another writer holds the token-store lock', function () {
    $user = Fixtures::makeUser();

    $plain = app(TokenRepository::class)->issue($user);

    expect(Cache::lock('statamic-mcp-token-store', 10)->get())->toBeTrue();

    $repository = new TokenRepository(lockWaitSeconds: 0);

    expect(fn () => $repository->revoke($plain->tokenId))->toThrow(LockTimeoutException::class)
        ->and($repository->all())->toHaveCount(1); // fail closed: token survives
});

it('issues and revokes normally when the lock is free', function () {
    $user = Fixtures::makeUser();

    $repository = app(TokenRepository::class);

    $plain = $repository->issue($user, 'lock test');

    expect($repository->all())->toHaveCount(1)
        ->and($repository->revoke($plain->tokenId))->toBeTrue()
        ->and($repository->all())->toBeEmpty();
});
