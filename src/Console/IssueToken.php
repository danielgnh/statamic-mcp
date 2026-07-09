<?php

namespace Danielgnh\StatamicMcp\Console;

use Danielgnh\StatamicMcp\Tokens\TokenRepository;
use Illuminate\Console\Command;
use Statamic\Console\RunsInPlease;
use Statamic\Facades\User;

class IssueToken extends Command
{
    use RunsInPlease;

    protected $signature = 'statamic:mcp:token
        {email : Email of the Statamic user the token will act as}
        {--name= : Label for this token, e.g. "claude-code laptop"}
        {--expires-days= : Days until the token expires (never expires when omitted)}';

    protected $description = 'Issue an MCP access token for a Statamic user';

    public function handle(TokenRepository $tokens): int
    {
        $email = $this->argument('email');

        $user = User::findByEmail($email);

        if (! $user) {
            $this->error("No user with email {$email} — create one in the Control Panel first.");

            return self::FAILURE;
        }

        $days = $this->option('expires-days');

        if ($days !== null && (! ctype_digit((string) $days) || (int) $days < 1)) {
            $this->error('--expires-days must be a positive whole number.');

            return self::FAILURE;
        }

        $plain = $tokens->issue($user, $this->option('name'), $days === null ? null : (int) $days);

        $url = url(config('statamic.mcp.route'));

        $this->line('');
        $this->info('Token created. This is the ONLY time it will be displayed — copy it now:');
        $this->line('');
        $this->line("  {$plain->token}");
        $this->line('');
        $this->line("Token id: {$plain->tokenId} (revoke with: php please mcp:token:revoke {$plain->tokenId})");
        $this->line($plain->expiresAt ? "Expires: {$plain->expiresAt->toIso8601String()}" : 'Expires: never');

        if (! $user->isSuper() && ! $user->hasPermission('access mcp')) {
            $this->warn("Heads up: {$email} does not have the 'access mcp' permission yet — requests will get 403 until you grant it to one of their roles in the Control Panel.");
        }

        $this->line('');
        $this->info('Claude Code:');
        $this->line('');
        $this->line("  claude mcp add --transport http statamic {$url} --header \"Authorization: Bearer {$plain->token}\"");
        $this->line('');
        $this->info('Cursor (.cursor/mcp.json):');
        $this->line('');
        $this->line(json_encode([
            'mcpServers' => [
                'statamic' => [
                    'url' => $url,
                    'headers' => ['Authorization' => "Bearer {$plain->token}"],
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->line('');
        $this->line('Works with Claude Code, Cursor, and any MCP client that can send a static Authorization header.');
        $this->line('Individual claude.ai and Claude Desktop connectors cannot send static headers (that is an');
        $this->line("org-admin beta for Team/Enterprise plans) — for those clients use OAuth mode ('auth' => 'oauth').");
        $this->line('See the README client-compatibility matrix.');

        return self::SUCCESS;
    }
}
