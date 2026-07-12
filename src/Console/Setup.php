<?php

namespace Danielgnh\StatamicMcp\Console;

use Closure;
use Danielgnh\StatamicMcp\Setup\EditResult;
use Danielgnh\StatamicMcp\Setup\EnvWriter;
use Illuminate\Console\Command;
use Statamic\Console\RunsInPlease;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

class Setup extends Command
{
    use RunsInPlease;

    protected $signature = 'statamic:mcp:setup';

    protected $description = 'Interactive setup wizard for the MCP server — token or OAuth mode.';

    public function handle(EnvWriter $env): int
    {
        $this->components->info('Statamic MCP setup');

        $mode = select(
            label: 'How will AI clients connect to this site?',
            options: [
                'token' => 'Token — Claude Code, Cursor, MCP Inspector',
                'oauth' => 'OAuth — claude.ai, Claude Desktop, ChatGPT connectors',
            ],
            default: config('statamic.mcp.auth', 'token'),
            hint: 'OAuth requires database (Eloquent) users — a Passport constraint.',
        );

        return $mode === 'oauth'
            ? $this->setupOAuth()
            : $this->setupToken($env);
    }

    protected function setupToken(EnvWriter $env): int
    {
        if (config('statamic.mcp.auth') === 'oauth') {
            $this->applyEdit(
                'Set STATAMIC_MCP_AUTH=token in .env',
                base_path('.env'),
                fn () => $env->apply(base_path('.env'), 'STATAMIC_MCP_AUTH', 'token'),
                fn () => $env->snippet('STATAMIC_MCP_AUTH', 'token'),
            );
        }

        $email = text(
            label: 'Which Statamic user should the first token act as?',
            placeholder: 'you@site.com',
            required: true,
        );

        // mcp:token owns issuance, output, and the permission/APP_URL warnings.
        return $this->call('statamic:mcp:token', ['email' => $email]);
    }

    protected function setupOAuth(): int
    {
        $this->components->error('OAuth setup is not implemented yet.');

        return self::FAILURE;
    }

    /**
     * The one rhythm every file edit follows: announce the change and the
     * file, confirm, apply — and on decline or bail, print the manual snippet
     * and carry on. The wizard never edits silently and never mangles a file
     * it doesn't recognize.
     *
     * @param  Closure(): EditResult  $apply
     * @param  Closure(): string  $snippet
     */
    protected function applyEdit(string $description, string $path, Closure $apply, Closure $snippet): void
    {
        $this->line('');
        $this->components->info($description);
        $this->line('  File: '.$path);
        $this->line('');
        $this->line('  '.str_replace("\n", "\n  ", $snippet()));
        $this->line('');

        if (! confirm('Apply this change to '.$path.'?')) {
            $this->components->warn('Skipped — apply the snippet above manually before connecting a client.');

            return;
        }

        match ($apply()) {
            EditResult::Applied => $this->components->twoColumnDetail($description, 'applied'),
            EditResult::Skipped => $this->components->twoColumnDetail($description, 'skipped — already in place'),
            EditResult::Bailed => $this->components->warn($path." doesn't look like the file this wizard expects — apply the snippet above manually."),
        };
    }
}
