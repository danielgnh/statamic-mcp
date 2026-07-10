<?php

namespace Danielgnh\StatamicMcp\Tokens;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Statamic\Contracts\Auth\User;
use Statamic\Facades\YAML;

/**
 * Single-writer assumption: issuance and revocation are expected from the CLI
 * (`php please mcp:token*` commands), so writes are serialized by LOCK_EX alone.
 * Any future WEB-triggered issuance path must add real locking around the whole
 * read-modify-write (flock on the file, or Cache::lock) — otherwise interleaved
 * revoke() + issue() can write back pre-revoke state and resurrect a token.
 * The torn-write failure mode fails closed: corrupt YAML breaks authentication,
 * it never opens it up.
 */
class TokenRepository
{
    public function issue(User $user, ?string $name = null, ?int $expiresDays = null): PlainToken
    {
        $tokenId = Str::lower(Str::random(12));
        $secret = Str::random(40);

        $expiresAt = $expiresDays ? Carbon::now()->addDays($expiresDays) : null;

        $tokens = $this->read();

        while (isset($tokens[$tokenId])) {
            $tokenId = Str::lower(Str::random(12));
        }

        $tokens[$tokenId] = [
            'user' => (string) $user->id(),
            'name' => $name,
            'hash' => hash('sha256', $secret),
            'created_at' => Carbon::now()->toIso8601String(),
            'expires_at' => $expiresAt?->toIso8601String(),
        ];

        $this->write($tokens);

        return new PlainToken(
            tokenId: $tokenId,
            token: "mcp_{$tokenId}_{$secret}",
            userId: (string) $user->id(),
            name: $name,
            expiresAt: $expiresAt,
        );
    }

    /**
     * @return array<string, array{user: string, name: ?string, hash: string, created_at: string, expires_at: ?string}>
     */
    public function all(): array
    {
        return $this->read();
    }

    /**
     * Returns the raw record regardless of expiry — expiry is enforced by the
     * authentication middleware.
     *
     * @return array{user: string, name: ?string, hash: string, created_at: string, expires_at: ?string}|null
     */
    public function find(string $tokenId): ?array
    {
        return $this->read()[$tokenId] ?? null;
    }

    public function revoke(string $tokenId): bool
    {
        $tokens = $this->read();

        if (! array_key_exists($tokenId, $tokens)) {
            return false;
        }

        unset($tokens[$tokenId]);

        $this->write($tokens);

        return true;
    }

    protected function path(): string
    {
        return storage_path('statamic/mcp/tokens.yaml');
    }

    protected function read(): array
    {
        return File::exists($this->path())
            ? YAML::parse(File::get($this->path()))
            : [];
    }

    protected function write(array $tokens): void
    {
        $directory = dirname($this->path());

        File::ensureDirectoryExists($directory, 0700);
        File::chmod($directory, 0700); // mkdir's mode is umask-subject; chmod is not

        File::put($this->path(), YAML::dump($tokens), lock: true);
        File::chmod($this->path(), 0600);
    }
}
