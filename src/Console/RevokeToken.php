<?php

namespace Danielgnh\StatamicMcp\Console;

use Danielgnh\StatamicMcp\Tokens\TokenRepository;
use Illuminate\Console\Command;
use Statamic\Console\RunsInPlease;

class RevokeToken extends Command
{
    use RunsInPlease;

    protected $signature = 'statamic:mcp:token:revoke
        {id : The token id (first column of mcp:tokens)}';

    protected $description = 'Revoke an MCP access token';

    public function handle(TokenRepository $tokens): int
    {
        $id = $this->argument('id');

        if (! $tokens->revoke($id)) {
            $this->error("No token with id {$id} — list tokens with: php please mcp:tokens");

            return self::FAILURE;
        }

        $this->info("Token {$id} revoked. Clients using it will get 401 on their next request.");

        return self::SUCCESS;
    }
}
