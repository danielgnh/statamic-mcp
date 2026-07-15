<?php

namespace Danielgnh\StatamicMcp\Console;

use Danielgnh\StatamicMcp\OAuth\KeyStore;
use Danielgnh\StatamicMcp\OAuth\PassportKeys;
use Danielgnh\StatamicMcp\Setup\EditResult;
use Danielgnh\StatamicMcp\Setup\EnvWriter;
use Danielgnh\StatamicMcp\Support\OAuthPrerequisites;
use Illuminate\Console\Command;
use Laravel\Passport\Passport;
use Statamic\Console\RunsInPlease;

/**
 * Provision and export Passport's keys. Since the addon manages keys in the
 * database (provisioned automatically, shared across the fleet, encrypted
 * with APP_KEY), deploys need no key step at all — this command remains as
 * the explicit path: provision now instead of on first request, adopt
 * existing key files into the store, or export the pair as PASSPORT_* env
 * variables for hosts that want config-level keys. Source precedence mirrors
 * what the runtime trusts: config (env vars), then the database, then key
 * files — and only when nothing exists anywhere is a fresh pair generated
 * (into the database when its table exists, else the legacy key files).
 * Existing keys are never overwritten — regeneration would silently 401
 * every connected client.
 */
class Keys extends Command
{
    use RunsInPlease;

    protected $signature = 'statamic:mcp:keys
        {--write : Write the variables into this environment\'s .env instead of printing them}
        {--json : Print raw JSON for piping into secret-store CLIs}';

    protected $description = "Provision Passport's encryption keys (database-managed) and export them as PASSPORT_* env variables.";

    public function handle(OAuthPrerequisites $prereqs, PassportKeys $keys, KeyStore $store, EnvWriter $env): int
    {
        if (! $prereqs->passportInstalled()) {
            $this->components->error("Laravel Passport isn't installed — there are no keys to export. Run 'composer require laravel/passport' (or `php please mcp:setup --oauth`).");

            return self::FAILURE;
        }

        if (! $pair = $this->resolvePair($keys, $store)) {
            return self::FAILURE;
        }

        [$private, $public, $source] = $pair;

        if ($this->option('json')) {
            $this->line(json_encode(['PASSPORT_PRIVATE_KEY' => $private, 'PASSPORT_PUBLIC_KEY' => $public], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        if ($this->option('write')) {
            return $this->writeToEnv($env, $private, $public);
        }

        $this->line('PASSPORT_PRIVATE_KEY='.$this->envValue($private));
        $this->line('PASSPORT_PUBLIC_KEY='.$this->envValue($public));

        $this->printGuidance($source);

        return self::SUCCESS;
    }

    /**
     * @return array{string, string, string}|null
     */
    protected function resolvePair(PassportKeys $keys, KeyStore $store): ?array
    {
        $configPrivate = trim((string) config('passport.private_key'));
        $configPublic = trim((string) config('passport.public_key'));

        if ($configPrivate !== '' && $configPublic !== '') {
            $privatePath = Passport::keyPath('oauth-private.key');
            $publicPath = Passport::keyPath('oauth-public.key');

            if (file_exists($privatePath) && file_exists($publicPath)
                && (trim((string) file_get_contents($privatePath)) !== $configPrivate || trim((string) file_get_contents($publicPath)) !== $configPublic)) {
                $this->components->warn("The PASSPORT_* env keys and the storage/oauth-*.key files differ — exporting the env keys, since they're what this environment verifies tokens against. Consider deleting the stale files.");
            }

            return [$configPrivate, $configPublic, 'config'];
        }

        // Named before resolve(): an undecryptable row must fail loudly, never
        // fall through to a regeneration that 401s every connected client.
        if ($store->undecryptable()) {
            $this->components->error("The stored signing key can't be decrypted — APP_KEY changed since it was stored. Restore the previous APP_KEY, or delete the row in '".KeyStore::TABLE."' to let a fresh pair provision (every connected client must then reconnect).");

            return null;
        }

        if ($pair = $keys->resolve()) {
            match ($pair['source']) {
                'generated' => $this->components->info('No keys found — generated a fresh pair into the database (shared across every server, nothing to paste anywhere).'),
                'files' => $store->has()
                    ? $this->components->info('Adopted the existing key files into the database — every server now reads the same managed copy.')
                    : null,
                default => null,
            };

            return [$pair['private'], $pair['public'], $pair['source']];
        }

        // No store table to provision into (migrate hasn't run) — fall back
        // to the key files, exactly what `passport:keys` would produce.
        return $this->generateFiles();
    }

    /**
     * @return array{string, string, string}|null
     */
    protected function generateFiles(): ?array
    {
        $private = app(PassportKeys::class)->generate();
        $public = $private === null ? null : app(KeyStore::class)->publicKeyFor($private);

        if ($private === null || $public === null) {
            $this->components->error('OpenSSL could not generate an RSA key pair: '.(openssl_error_string() ?: 'unknown error'));

            return null;
        }

        $privatePath = Passport::keyPath('oauth-private.key');
        $publicPath = Passport::keyPath('oauth-public.key');

        file_put_contents($privatePath, $private.PHP_EOL);
        file_put_contents($publicPath, $public.PHP_EOL);
        chmod($privatePath, 0600);
        chmod($publicPath, 0600);

        $this->components->info("No keys found and the addon's key table is not migrated yet — generated a fresh pair into storage/oauth-*.key. Run 'php artisan migrate' to let the database manage them instead.");

        return [$private, $public, 'files'];
    }

    protected function writeToEnv(EnvWriter $env, string $private, string $public): int
    {
        foreach (['PASSPORT_PRIVATE_KEY' => $private, 'PASSPORT_PUBLIC_KEY' => $public] as $key => $pem) {
            $result = $env->apply(base_path('.env'), $key, $this->envValue($pem));

            if ($result === EditResult::Bailed) {
                $this->components->error(base_path('.env').' is missing or not writable — paste the output of `php please mcp:keys` in manually.');

                return self::FAILURE;
            }

            $this->components->twoColumnDetail($key, $result === EditResult::Skipped ? 'skipped — already set' : 'written to .env');
        }

        return self::SUCCESS;
    }

    /**
     * One paste-safe line: real newlines become the two characters \n, which
     * phpdotenv expands back inside double quotes — the exact format every
     * host env panel and .env file accepts.
     */
    protected function envValue(string $pem): string
    {
        return '"'.str_replace(["\r\n", "\n"], '\n', trim($pem)).'"';
    }

    /**
     * Goes to stderr on purpose: `mcp:keys | pbcopy` and shell redirection
     * capture only the two variables above.
     */
    protected function printGuidance(string $source): void
    {
        $err = $this->output->getErrorStyle();

        $err->newLine();

        if (in_array($source, ['database', 'generated', 'files'], true) && app(KeyStore::class)->has()) {
            $err->writeln('These keys live in the database — provisioned automatically, shared across every');
            $err->writeln('server, surviving releases. Deploys need no key step; pasting the lines above into');
            $err->writeln('the environment is only for overriding the database copy (env config wins).');

            return;
        }

        $err->writeln('Paste both lines into the production environment — they must stay identical across servers and releases:');
        $err->writeln('  Forge/Ploi: the site\'s Environment panel');
        $err->writeln('  Vapor/serverless: the environment\'s secrets (`vapor env:pull` / `env:push`)');
        $err->writeln('  Plain server: the site\'s .env (or run `php please mcp:keys --write` there)');
    }
}
