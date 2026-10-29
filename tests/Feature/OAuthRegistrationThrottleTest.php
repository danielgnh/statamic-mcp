<?php

use Danielgnh\StatamicMcp\Tests\Support\OAuthFixtures;
use Danielgnh\StatamicMcp\Tests\UsesBearerKeyedThrottle;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Passport\Passport;

uses(UsesBearerKeyedThrottle::class);

beforeEach(function () {
    OAuthFixtures::migratePassport();

    RateLimiter::for('mcp', fn (Request $request) => Limit::perMinute(120)->by($request->bearerToken()));
});

it('throttles client registration per address even when the mcp route throttles by bearer token', function () {
    $codes = collect(range(1, 61))->map(fn (int $i) => $this->postJson('/oauth/register', [
        'client_name' => 'Client '.$i,
        'redirect_uris' => ['https://client.example.com/callback'],
    ])->getStatusCode());

    expect($codes->countBy()->all())->toBe([201 => 60, 429 => 1])
        ->and(Passport::client()->newQuery()->count())->toBe(60);
});
