<?php

namespace Danielgnh\StatamicMcp\Console;

use Danielgnh\StatamicMcp\OAuth\KeyStore;
use Danielgnh\StatamicMcp\OAuth\PassportKeys;
use Danielgnh\StatamicMcp\Setup\EditResult;
use Danielgnh\StatamicMcp\Setup\EnvWriter;
use Danielgnh\StatamicMcp\Support\OAuthPrerequisites;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Console\View\Components\Factory;
use Laravel\Passport\Passport;
use Statamic\Console\RunsInPlease;
use Symfony\Component\Console\Output\ConsoleOutputInterface;

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
            $this->messages()->error("Laravel Passport isn't installed — there are no keys to export. Run 'composer require laravel/passport' (or `php please mcp:setup --oauth`).");

            return self::FAILURE;
        }

        if (! $pair = $this->resolvePair($keys, $store, $prereqs)) {
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
    protected function resolvePair(PassportKeys $keys, KeyStore $store, OAuthPrerequisites $prereqs): ?array
    {
        if (filled(config('passport.private_key')) || filled(config('passport.public_key'))) {
            return $this->configuredPair();
        }

        if ($store->undecryptable()) {
            $this->messages()->error("The stored signing key can't be decrypted — APP_KEY changed since it was stored. Restore the previous APP_KEY, or delete the row in '".KeyStore::TABLE."' to let a fresh pair provision (every connected client must then reconnect).");

            return null;
        }

        if ($pair = $keys->resolve()) {
            if ($pair['source'] === 'files' && $failure = $prereqs->databaseFailure()) {
                $warning = "Can't reach the database ({$failure->getMessage()}), so these key files may not be the pair in use.";

                if ($this->option('write')) {
                    $this->messages()->error($warning.' Writing them to .env would override a stored pair for every connected client. Fix the connection and run this again, or run this without --write to print them.');

                    return null;
                }

                $this->messages()->warn($warning.' Check before pasting them: they would override a stored pair for every connected client.');
            }

            match ($pair['source']) {
                'generated' => $this->messages()->info('No keys found — generated a fresh pair into the database (shared across every server, nothing to paste anywhere).'),
                'files' => $store->has()
                    ? $this->messages()->info('Adopted the existing key files into the database — every server now reads the same managed copy.')
                    : null,
                default => null,
            };

            return [$pair['private'], $pair['public'], $pair['source']];
        }

        if ($failure = $prereqs->databaseFailure()) {
            $this->messages()->error("Can't reach the database ({$failure->getMessage()}), so a stored signing key can't be read. Fix the connection and run this again: a pair generated now would override a stored one for every connected client. On a site with no database, run php artisan passport:keys first.");

            return null;
        }

        return $this->generateFiles();
    }

    /**
     * @return array{string, string, string}|null
     */
    protected function configuredPair(): ?array
    {
        $private = $this->configuredKey('private');
        $public = $this->configuredKey('public');

        if ($private === null || $public === null) {
            [$set, $missing] = $private === null ? ['PUBLIC', 'PRIVATE'] : ['PRIVATE', 'PUBLIC'];
            $file = 'storage/oauth-'.strtolower($missing).'.key';

            $this->messages()->error("PASSPORT_{$set}_KEY is set but PASSPORT_{$missing}_KEY isn't, and there is no {$file}, so Passport has half a key pair. Set PASSPORT_{$missing}_KEY too, or unset PASSPORT_{$set}_KEY to use the database-managed pair.");

            return null;
        }

        $privateFile = $this->keyFile('private');
        $publicFile = $this->keyFile('public');

        if (filled(config('passport.private_key')) && filled(config('passport.public_key'))
            && $privateFile !== null && $publicFile !== null
            && ($privateFile !== $private || $publicFile !== $public)) {
            $this->messages()->warn("The PASSPORT_* env keys and the storage/oauth-*.key files differ — exporting the env keys, since they're what this environment verifies tokens against. Consider deleting the stale files.");
        }

        return [$private, $public, 'config'];
    }

    protected function configuredKey(string $type): ?string
    {
        $key = trim((string) config("passport.{$type}_key"));

        return $key !== '' ? $key : $this->keyFile($type);
    }

    protected function keyFile(string $type): ?string
    {
        $path = Passport::keyPath("oauth-{$type}.key");

        return file_exists($path) ? trim((string) file_get_contents($path)) : null;
    }

    /**
     * @return array{string, string, string}|null
     */
    protected function generateFiles(): ?array
    {
        $private = app(PassportKeys::class)->generate();
        $public = $private === null ? null : app(KeyStore::class)->publicKeyFor($private);

        if ($private === null || $public === null) {
            $this->messages()->error('OpenSSL could not generate an RSA key pair: '.(openssl_error_string() ?: 'unknown error'));

            return null;
        }

        $privatePath = Passport::keyPath('oauth-private.key');
        $publicPath = Passport::keyPath('oauth-public.key');

        file_put_contents($privatePath, $private.PHP_EOL);
        file_put_contents($publicPath, $public.PHP_EOL);
        chmod($privatePath, 0600);
        chmod($publicPath, 0600);

        $this->messages()->info("No keys found and the addon's key table is not migrated yet — generated a fresh pair into storage/oauth-*.key. Run 'php artisan migrate' to let the database manage them instead.");

        return [$private, $public, 'files'];
    }

    protected function writeToEnv(EnvWriter $env, string $private, string $public): int
    {
        foreach (['PASSPORT_PRIVATE_KEY' => $private, 'PASSPORT_PUBLIC_KEY' => $public] as $key => $pem) {
            $result = $env->apply(base_path('.env'), $key, $this->envValue($pem));

            if ($result === EditResult::Bailed) {
                $this->messages()->error(base_path('.env').' is missing or not writable — paste the output of `php please mcp:keys` in manually.');

                return self::FAILURE;
            }

            $this->components->twoColumnDetail($key, $result === EditResult::Skipped ? 'skipped — already set' : 'written to .env');
        }

        return self::SUCCESS;
    }

    protected function envValue(string $pem): string
    {
        return '"'.str_replace(["\r\n", "\n"], '\n', trim($pem)).'"';
    }

    protected function messages(): Factory
    {
        $output = $this->output->getOutput();

        return new Factory(new OutputStyle($this->input, $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output));
    }

    protected function printGuidance(string $source): void
    {
        $err = $this->output->getErrorStyle();

        $err->newLine();

        if (in_array($source, ['database', 'generated', 'files'], true) && app(KeyStore::class)->has()) {
            $err->writeln('These keys live in the database — provisioned automatically, shared across every');
            $err->writeln('server, surviving releases. Deploys need no key step; set the PASSPORT_* variables');
            $err->writeln('only to override the database copy (env config wins).');

            return;
        }

        $err->writeln('Paste both lines into the production environment — they must stay identical across servers and releases:');
        $err->writeln('  Forge/Ploi: the site\'s Environment panel');
        $err->writeln('  Vapor/serverless: the environment\'s secrets (`vapor env:pull` / `env:push`)');
        $err->writeln('  Plain server: the site\'s .env (or run `php please mcp:keys --write` there)');
    }
}
