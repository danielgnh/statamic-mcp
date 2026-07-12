<?php

use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tokens\TokenRepository;
use Laravel\Passport\Passport;

it('serves token-mode requests while every oauth prerequisite is missing', function () {
    // This environment IS the oauth-broken state:
    expect(class_exists(Passport::class))->toBeFalse()
        ->and(config('statamic.users.repository'))->toBe('file')
        ->and(config('auth.guards.api'))->toBeNull();

    $user = Fixtures::makeUser();
    $token = app(TokenRepository::class)->issue($user, 'isolation-test')->token;

    $this->withHeaders([
        'Authorization' => 'Bearer '.$token,
        'Accept' => 'application/json, text/event-stream',
    ])->postJson('/mcp/statamic', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2025-11-25',
            'capabilities' => (object) [],
            'clientInfo' => ['name' => 'pest', 'version' => '1.0.0'],
        ],
    ])
        ->assertOk()
        ->assertSee('Statamic');
})->skip(fn () => class_exists(Passport::class), 'asserts Passport absence — skipped in the Passport CI leg');
