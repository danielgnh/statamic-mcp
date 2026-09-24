<?php

use Danielgnh\StatamicMcp\Tests\Support\OAuthFixtures;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

function runUserIdMigration(): void
{
    $migration = require glob(dirname(__DIR__, 2).'/database/migrations/*_make_passport_user_id_columns_fit_statamic_ids.php')[0];
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

it('converts the columns of Passport migrations published today, in the same migrate', function () {
    // vendor:publish stamps Passport's migrations with the moment they are
    // published, a second apart, and a fresh install then runs them together
    // with the addon's migrations in one migrate.
    $published = sys_get_temp_dir().'/mcp-passport-'.Str::random(8);
    $at = now();

    File::ensureDirectoryExists($published);

    foreach (File::files(dirname((new ReflectionClass(Passport::class))->getFileName(), 2).'/database/migrations') as $file) {
        File::copy($file->getPathname(), $published.'/'.preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', $at->addSecond()->format('Y_m_d_His').'_', $file->getFilename()));
    }

    try {
        $this->artisan('migrate', ['--path' => [$published, dirname(__DIR__, 2).'/database/migrations'], '--realpath' => true])->assertSuccessful();
    } finally {
        File::deleteDirectory($published);
    }

    foreach (['oauth_auth_codes', 'oauth_access_tokens', 'oauth_device_codes'] as $table) {
        expect(strtolower(Schema::getColumnType($table, 'user_id')))->not->toContain('int');
    }
});

it('converts the columns on the next migrate of a site whose earlier run came too soon', function () {
    OAuthFixtures::migratePassportWithBigintUserIds();

    // Under its old name the migration ran before Passport's tables existed,
    // did nothing, and was recorded as run.
    $this->artisan('migrate:install')->assertSuccessful();

    DB::table('migrations')->insert([
        'migration' => '2026_07_14_100000_make_passport_user_id_columns_fit_statamic_ids',
        'batch' => 1,
    ]);

    $this->artisan('migrate', ['--path' => dirname(__DIR__, 2).'/database/migrations', '--realpath' => true])->assertSuccessful();

    expect(strtolower(Schema::getColumnType('oauth_access_tokens', 'user_id')))->not->toContain('int')
        ->and(strtolower(Schema::getColumnType('oauth_auth_codes', 'user_id')))->not->toContain('int');
});
