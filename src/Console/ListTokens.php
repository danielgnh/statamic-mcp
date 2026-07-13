<?php

namespace Danielgnh\StatamicMcp\Console;

use Danielgnh\StatamicMcp\Tokens\TokenRepository;
use Illuminate\Console\Command;
use Statamic\Console\RunsInPlease;
use Statamic\Facades\User;

class ListTokens extends Command
{
    use RunsInPlease;

    protected $signature = 'statamic:mcp:tokens';

    protected $description = 'List issued MCP access tokens';

    public function handle(TokenRepository $tokens): int
    {
        $all = $tokens->all();

        if ($all === []) {
            $this->info('No MCP tokens issued. Create one with: php please mcp:token you@site.com');

            return self::SUCCESS;
        }

        $this->table(
            ['Id', 'User', 'Name', 'Created', 'Expires'],
            collect($all)->map(fn (array $record, string $tokenId) => [
                $tokenId,
                User::find($record['user'])?->email() ?? "{$record['user']} (user deleted — token dead)",
                $record['name'] ?? '—',
                $record['created_at'],
                $record['expires_at'] ?? 'never',
            ])->values()->all(),
        );

        return self::SUCCESS;
    }
}
