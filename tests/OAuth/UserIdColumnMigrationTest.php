<?php

use Danielgnh\StatamicMcp\Tests\Support\OAuthFixtures;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function runUserIdMigration(): void
{
    $migration = require dirname(__DIR__, 2).'/database/migrations/2026_07_14_100000_make_passport_user_id_columns_fit_statamic_ids.php';
    $migration->up();
}

it('converts Passport stock bigint user_id columns to string(36)', function () {
    OAuthFixtures::migratePassportWithBigintUserIds();

    // An existing integer id (a stock-Eloquent install already using OAuth
    // mode) must survive the conversion.
    DB::table('oauth_access_tokens')->insert([
        'id' => str_repeat('a', 40),
        'user_id' => 42,
        'client_id' => 'client-1',
        'revoked' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    runUserIdMigration();

    expect(strtolower(Schema::getColumnType('oauth_access_tokens', 'user_id')))->not->toContain('int')
        ->and(strtolower(Schema::getColumnType('oauth_auth_codes', 'user_id')))->not->toContain('int')
        ->and((string) DB::table('oauth_access_tokens')->value('user_id'))->toBe('42');

    // A UUID now fits where it would have crashed before.
    DB::table('oauth_access_tokens')->insert([
        'id' => str_repeat('b', 40),
        'user_id' => 'ae0bbcf0-1d75-4f50-a1e3-6f4c3e9f0000',
        'client_id' => 'client-1',
        'revoked' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('oauth_access_tokens')->where('id', str_repeat('b', 40))->value('user_id'))
        ->toBe('ae0bbcf0-1d75-4f50-a1e3-6f4c3e9f0000');
});

it('no-ops on already-converted columns and on missing tables', function () {
    // Missing tables: nothing to do, nothing thrown.
    runUserIdMigration();

    OAuthFixtures::migratePassport(); // already string-typed

    runUserIdMigration();

    expect(strtolower(Schema::getColumnType('oauth_access_tokens', 'user_id')))->not->toContain('int');
});
