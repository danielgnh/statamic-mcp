<?php

use Danielgnh\StatamicMcp\OAuth\KeyStore;
use Danielgnh\StatamicMcp\Setup\EditResult;
use Danielgnh\StatamicMcp\Setup\EnvWriter;
use Danielgnh\StatamicMcp\Support\OAuthPrerequisites;
use Danielgnh\StatamicMcp\Tests\Support\OAuthFixtures;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

/**
 * A PEM as it appears in the exported env block: one line, real newlines
 * escaped to \n, wrapped in double quotes — exactly what phpdotenv and every
 * host env panel expect to be pasted.
 */
function envEscapedKey(string $pem): string
{
    return '"'.str_replace("\n", '\n', trim($pem)).'"';
}

beforeEach(function () {
    $this->keyDir = sys_get_temp_dir().'/mcp-keys-test-'.Str::random(8);
    @mkdir($this->keyDir, 0700, true);

    Passport::loadKeysFrom($this->keyDir);
});

afterEach(function () {
    array_map(unlink(...), glob($this->keyDir.'/*') ?: []);
    @rmdir($this->keyDir);
    Passport::$keyPath = null;
});

it('generates a key pair and prints a paste-ready env block when no keys exist', function () {
    expect(Artisan::call('statamic:mcp:keys'))->toBe(0);

    $private = file_get_contents($this->keyDir.'/oauth-private.key');
    $public = file_get_contents($this->keyDir.'/oauth-public.key');

    expect($private)->toContain('PRIVATE KEY-----')
        ->and($public)->toContain('-----BEGIN PUBLIC KEY-----')
        ->and(Artisan::output())
        ->toContain('PASSPORT_PRIVATE_KEY='.envEscapedKey($private))
        ->toContain('PASSPORT_PUBLIC_KEY='.envEscapedKey($public));
});

it('exports existing key files verbatim and never regenerates them', function () {
    file_put_contents($this->keyDir.'/oauth-private.key', "FAKE\nPRIVATE\nPEM");
    file_put_contents($this->keyDir.'/oauth-public.key', "FAKE\nPUBLIC\nPEM");

    expect(Artisan::call('statamic:mcp:keys'))->toBe(0)
        ->and(Artisan::output())
        ->toContain('PASSPORT_PRIVATE_KEY="FAKE\nPRIVATE\nPEM"')
        ->toContain('PASSPORT_PUBLIC_KEY="FAKE\nPUBLIC\nPEM"')
        ->and(file_get_contents($this->keyDir.'/oauth-private.key'))->toBe("FAKE\nPRIVATE\nPEM");
});

it('handles passport config keys that exist as null — the real host-app shape', function () {
    // On a host app Passport's config file is loaded, so the keys EXIST with
    // value null (env('PASSPORT_PRIVATE_KEY') unset) — config()->string()
    // throws on that; a missing key would silently get the default instead.
    config(['passport.private_key' => null, 'passport.public_key' => null]);
    file_put_contents($this->keyDir.'/oauth-private.key', "FILE\nPRIVATE");
    file_put_contents($this->keyDir.'/oauth-public.key', "FILE\nPUBLIC");

    expect(Artisan::call('statamic:mcp:keys'))->toBe(0)
        ->and(Artisan::output())->toContain('PASSPORT_PRIVATE_KEY="FILE\nPRIVATE"');
});

it('prefers configured env keys over files and warns about the stale files', function () {
    config(['passport.private_key' => "CONF\nPRIVATE", 'passport.public_key' => "CONF\nPUBLIC"]);
    file_put_contents($this->keyDir.'/oauth-private.key', 'stale');
    file_put_contents($this->keyDir.'/oauth-public.key', 'stale');

    expect(Artisan::call('statamic:mcp:keys'))->toBe(0)
        ->and(Artisan::output())
        ->toContain('PASSPORT_PRIVATE_KEY="CONF\nPRIVATE"')
        ->toContain('PASSPORT_PUBLIC_KEY="CONF\nPUBLIC"')
        ->toContain('differ');
});

it('exports the same pair when run twice', function () {
    Artisan::call('statamic:mcp:keys');
    $first = Artisan::output();

    Artisan::call('statamic:mcp:keys');

    // Line-level comparison: the env block is identical, only footer styling
    // could ever differ.
    expect(str(Artisan::output())->explode("\n")->filter(fn ($l) => str_starts_with($l, 'PASSPORT_'))->values()->all())
        ->toBe(str($first)->explode("\n")->filter(fn ($l) => str_starts_with($l, 'PASSPORT_'))->values()->all());
});

it('outputs the raw pair as machine-readable json with --json', function () {
    file_put_contents($this->keyDir.'/oauth-private.key', "FAKE\nPRIVATE");
    file_put_contents($this->keyDir.'/oauth-public.key', "FAKE\nPUBLIC");

    Artisan::call('statamic:mcp:keys', ['--json' => true]);

    expect(json_decode(Artisan::output(), true))->toBe([
        'PASSPORT_PRIVATE_KEY' => "FAKE\nPRIVATE",
        'PASSPORT_PUBLIC_KEY' => "FAKE\nPUBLIC",
    ]);
});

it('writes the variables into .env with --write', function () {
    file_put_contents($this->keyDir.'/oauth-private.key', "FAKE\nPRIVATE");
    file_put_contents($this->keyDir.'/oauth-public.key', "FAKE\nPUBLIC");

    $env = new class extends EnvWriter
    {
        public array $writes = [];

        public function apply(string $path, string $key, string $value): EditResult
        {
            $this->writes[] = [$key, $value];

            return EditResult::Applied;
        }
    };
    app()->instance(EnvWriter::class, $env);

    expect(Artisan::call('statamic:mcp:keys', ['--write' => true]))->toBe(0)
        ->and($env->writes)->toBe([
            ['PASSPORT_PRIVATE_KEY', '"FAKE\nPRIVATE"'],
            ['PASSPORT_PUBLIC_KEY', '"FAKE\nPUBLIC"'],
        ]);
});

it('exports the stored database key with its derived public half', function () {
    OAuthFixtures::migrateKeyStore();
    $pem = OAuthFixtures::rsaPrivateKey();
    (new KeyStore)->put($pem);

    expect(Artisan::call('statamic:mcp:keys'))->toBe(0)
        ->and(Artisan::output())
        ->toContain('PASSPORT_PRIVATE_KEY='.envEscapedKey($pem))
        ->toContain('PASSPORT_PUBLIC_KEY="-----BEGIN PUBLIC KEY-----');
});

it('prefers the stored database key over key files — mirroring the runtime', function () {
    OAuthFixtures::migrateKeyStore();
    $pem = OAuthFixtures::rsaPrivateKey();
    (new KeyStore)->put($pem);

    file_put_contents($this->keyDir.'/oauth-private.key', "STALE\nPRIVATE");
    file_put_contents($this->keyDir.'/oauth-public.key', "STALE\nPUBLIC");

    expect(Artisan::call('statamic:mcp:keys'))->toBe(0)
        ->and(Artisan::output())->toContain('PASSPORT_PRIVATE_KEY='.envEscapedKey($pem));
});

it('generates into the database instead of key files when the store table exists', function () {
    OAuthFixtures::migrateKeyStore();

    expect(Artisan::call('statamic:mcp:keys'))->toBe(0)
        ->and((new KeyStore)->get())->toContain('PRIVATE KEY-----')
        ->and(file_exists($this->keyDir.'/oauth-private.key'))->toBeFalse();
});

it('adopts existing key files into the database', function () {
    OAuthFixtures::migrateKeyStore();
    $pem = OAuthFixtures::rsaPrivateKey();
    file_put_contents($this->keyDir.'/oauth-private.key', $pem);
    file_put_contents($this->keyDir.'/oauth-public.key', (new KeyStore)->publicKeyFor($pem));

    expect(Artisan::call('statamic:mcp:keys'))->toBe(0)
        ->and((new KeyStore)->get())->toBe($pem)
        ->and(Artisan::output())->toContain('PASSPORT_PRIVATE_KEY='.envEscapedKey($pem));
});

it('fails with the APP_KEY remedy instead of regenerating over an undecryptable stored key', function () {
    OAuthFixtures::migrateKeyStore();
    (new KeyStore)->put(OAuthFixtures::rsaPrivateKey());
    $cipher = DB::table(KeyStore::TABLE)->value('private_key');

    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    app()->forgetInstance('encrypter');
    Crypt::clearResolvedInstance('encrypter');

    expect(Artisan::call('statamic:mcp:keys'))->toBe(1)
        ->and(Artisan::output())->toContain('APP_KEY')
        ->and(DB::table(KeyStore::TABLE)->value('private_key'))->toBe($cipher);
});

it('fails with guidance when Passport is not installed', function () {
    app()->instance(OAuthPrerequisites::class, new class extends OAuthPrerequisites
    {
        public function passportInstalled(): bool
        {
            return false;
        }
    });

    expect(Artisan::call('statamic:mcp:keys'))->toBe(1)
        ->and(Artisan::output())->toContain('composer require laravel/passport');
});
