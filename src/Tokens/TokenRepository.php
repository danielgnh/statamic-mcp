<?php

namespace Danielgnh\StatamicMcp\Tokens;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Statamic\Contracts\Auth\User;
use Statamic\Facades\YAML;

class TokenRepository
{
    public function issue(User $user, ?string $name = null, ?int $expiresDays = null): PlainToken
    {
        $tokenId = Str::lower(Str::random(12));
        $secret = Str::random(40);

        $expiresAt = $expiresDays ? Carbon::now()->addDays($expiresDays) : null;

        $tokens = $this->read();

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
        File::ensureDirectoryExists(dirname($this->path()));
        File::put($this->path(), YAML::dump($tokens));
    }
}
