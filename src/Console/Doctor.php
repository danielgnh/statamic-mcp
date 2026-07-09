<?php

namespace Danielgnh\StatamicMcp\Console;

use Danielgnh\StatamicMcp\Tokens\TokenRepository;
use Illuminate\Console\Command;
use Statamic\Console\RunsInPlease;

/**
 * MINIMAL doctor: token-mode checks only. Task 24 replaces this implementation
 * wholesale with the full version (OAuth prerequisites etc.). Every output line
 * below is worded exactly as the full version prints it, so tests written
 * against these lines survive the replacement.
 */
class Doctor extends Command
{
    use RunsInPlease;

    protected $signature = 'statamic:mcp:doctor';

    protected $description = 'Check the MCP server configuration and auth setup';

    public function handle(TokenRepository $tokens): int
    {
        $ok = true;

        $mode = config('statamic.mcp.auth', 'token');

        $this->line('Statamic MCP doctor');
        $this->line('');
        $this->line('  Endpoint:  '.url(config('statamic.mcp.route', 'mcp/statamic')));
        $this->line('  Auth mode: '.$mode);
        $this->line('');

        if (config('statamic.mcp.enabled')) {
            $this->info('[ OK ] MCP is enabled.');
        } else {
            $this->warn("[WARN] MCP is disabled ('enabled' => false) — the endpoint is not registered. Set STATAMIC_MCP_ENABLED=true to serve requests.");
        }

        if ($mode === 'token') {
            $dir = storage_path('statamic/mcp');

            // The directory may not exist before the first token is issued —
            // probe the closest existing ancestor for writability.
            $probe = $dir;

            while (! is_dir($probe)) {
                $probe = dirname($probe);
            }

            if (is_writable($probe)) {
                $this->info('[ OK ] Token store is writable ('.$dir.').');
            } else {
                $this->error('[FAIL] Token store is not writable — fix permissions on '.$probe.' so tokens can be saved to '.$dir.'/tokens.yaml.');
                $ok = false;
            }

            $count = count($tokens->all());

            if ($count === 0) {
                $this->warn('[WARN] No tokens issued — the endpoint is a locked door. Run: php please mcp:token you@site.com');
            } else {
                $this->info('[ OK ] '.$count.' token(s) issued.');
            }
        }

        $this->line('');

        if (! $ok) {
            $this->error('Problems found. Fix the [FAIL] items above.');

            return self::FAILURE;
        }

        $this->info('No blocking problems found.');

        return self::SUCCESS;
    }
}
