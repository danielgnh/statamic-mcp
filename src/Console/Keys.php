<?php

namespace Danielgnh\StatamicMcp\Console;

use Danielgnh\StatamicMcp\Setup\EditResult;
use Danielgnh\StatamicMcp\Setup\EnvWriter;
use Danielgnh\StatamicMcp\Support\OAuthPrerequisites;
use Illuminate\Console\Command;
use Laravel\Passport\Passport;
use Statamic\Console\RunsInPlease;

/**
 * Turns "get the Passport keys onto production" into one command: ensure a
 * stable pair exists, then print it as paste-ready PASSPORT_* env variables —
 * escaping done, nothing to hand-edit. Source precedence mirrors what Passport
 * itself trusts at runtime: config (env vars) first, then the key files, and
 * only when neither exists is a fresh pair generated. Existing keys are never
 * overwritten — regeneration would silently 401 every connected client.
 */
class Keys extends Command
{
    use RunsInPlease;

    protected $signature = 'statamic:mcp:keys
        {--write : Write the variables into this environment\'s .env instead of printing them}
        {--json : Print raw JSON for piping into secret-store CLIs}';

    protected $description = "Export Passport's encryption keys as deploy-ready PASSPORT_* env variables — generates a pair first when none exists.";

    public function handle(OAuthPrerequisites $prereqs, EnvWriter $env): int
    {
        if (! $prereqs->passportInstalled()) {
            $this->components->error("Laravel Passport isn't installed — there are no keys to export. Run 'composer require laravel/passport' (or `php please mcp:setup --oauth`).");

            return self::FAILURE;
        }

        if (! $pair = $this->resolvePair()) {
            return self::FAILURE;
        }

        [$private, $public] = $pair;

        if ($this->option('json')) {
            $this->line(json_encode(['PASSPORT_PRIVATE_KEY' => $private, 'PASSPORT_PUBLIC_KEY' => $public], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        if ($this->option('write')) {
            return $this->writeToEnv($env, $private, $public);
        }

        $this->line('PASSPORT_PRIVATE_KEY='.$this->envValue($private));
        $this->line('PASSPORT_PUBLIC_KEY='.$this->envValue($public));

        $this->printPasteTargets();

        return self::SUCCESS;
    }

    /**
     * @return array{string, string}|null
     */
    protected function resolvePair(): ?array
    {
        $configPrivate = trim((string) config('passport.private_key'));
        $configPublic = trim((string) config('passport.public_key'));

        $privatePath = Passport::keyPath('oauth-private.key');
        $publicPath = Passport::keyPath('oauth-public.key');
        $filesExist = file_exists($privatePath) && file_exists($publicPath);

        if ($configPrivate !== '' && $configPublic !== '') {
            if ($filesExist && (trim((string) file_get_contents($privatePath)) !== $configPrivate || trim((string) file_get_contents($publicPath)) !== $configPublic)) {
                $this->components->warn("The PASSPORT_* env keys and the storage/oauth-*.key files differ — exporting the env keys, since they're what this environment verifies tokens against. Consider deleting the stale files.");
            }

            return [$configPrivate, $configPublic];
        }

        if ($filesExist) {
            return [trim((string) file_get_contents($privatePath)), trim((string) file_get_contents($publicPath))];
        }

        return $this->generatePair($privatePath, $publicPath);
    }

    /**
     * Same output as `passport:keys`: a 4096-bit RSA pair written to the
     * standard key files (0600), so local issuance keeps working off the files
     * and re-running this command exports the identical pair.
     *
     * @return array{string, string}|null
     */
    protected function generatePair(string $privatePath, string $publicPath): ?array
    {
        $rsa = openssl_pkey_new(['private_key_bits' => 4096, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $details = $rsa === false ? false : openssl_pkey_get_details($rsa);

        if ($rsa === false || $details === false || ! openssl_pkey_export($rsa, $private)) {
            $this->components->error('OpenSSL could not generate an RSA key pair: '.(openssl_error_string() ?: 'unknown error'));

            return null;
        }

        $public = $details['key'];

        file_put_contents($privatePath, $private);
        file_put_contents($publicPath, $public);
        chmod($privatePath, 0600);
        chmod($publicPath, 0600);

        $this->components->info('No keys found — generated a fresh pair (storage/oauth-*.key).');

        return [trim((string) $private), trim((string) $public)];
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
    protected function printPasteTargets(): void
    {
        $err = $this->output->getErrorStyle();

        $err->newLine();
        $err->writeln('Paste both lines into the production environment — they must stay identical across servers and releases:');
        $err->writeln('  Forge/Ploi: the site\'s Environment panel');
        $err->writeln('  Vapor/serverless: the environment\'s secrets (`vapor env:pull` / `env:push`)');
        $err->writeln('  Plain server: the site\'s .env (or run `php please mcp:keys --write` there)');
    }
}
