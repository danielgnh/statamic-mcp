<?php

namespace Danielgnh\StatamicMcp\Tokens;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Statamic\Contracts\Auth\User;
use Statamic\Facades\YAML;

class TokenRepository
{
    public function __construct(protected int $lockWaitSeconds = 5) {}

    public function issue(User $user, ?string $name = null, ?int $expiresDays = null): PlainToken
    {
        return $this->withLock(function () use ($user, $name, $expiresDays) {
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
        });
    }

    /**
     * @return array<string, array{user: string, name: ?string, hash: string, created_at: string, expires_at: ?string}>
     */
    public function all(): array
    {
        return $this->read();
    }

    /**
     * @return array{user: string, name: ?string, hash: string, created_at: string, expires_at: ?string}|null
     */
    public function find(string $tokenId): ?array
    {
        return $this->read()[$tokenId] ?? null;
    }

    public function revoke(string $tokenId): bool
    {
        return $this->withLock(function () use ($tokenId) {
            $tokens = $this->read();

            if (! array_key_exists($tokenId, $tokens)) {
                return false;
            }

            unset($tokens[$tokenId]);

            $this->write($tokens);

            return true;
        });
    }

    /**
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    protected function withLock(callable $operation): mixed
    {
        return Cache::lock('statamic-mcp-token-store', 10)->block($this->lockWaitSeconds, $operation);
    }

    protected function path(): string
    {
        return storage_path('statamic/mcp/tokens.yaml');
    }

    /**
     * @return array<string, array{user: string, name: ?string, hash: string, created_at: string, expires_at: ?string}>
     */
    protected function read(): array
    {
        return File::exists($this->path())
            ? YAML::parse(File::get($this->path()))
            : [];
    }

    /**
     * @param  array<string, array{user: string, name: ?string, hash: string, created_at: string, expires_at: ?string}>  $tokens
     */
    protected function write(array $tokens): void
    {
        $directory = dirname($this->path());

        File::ensureDirectoryExists($directory, 0700);
        File::chmod($directory, 0700);

        File::put($this->path(), YAML::dump($tokens), lock: true);
        File::chmod($this->path(), 0600);
    }
}
