## Statamic MCP

This package serves an MCP endpoint (default `/mcp/statamic`) so AI clients can manage
Statamic entries, taxonomy terms, globals, and assets. Every request authenticates as a
real Statamic user and honors that user's permissions — there is no parallel permission
system to configure.

### Auth modes

- `token` (default) — static Bearer tokens for header-capable clients: Claude Code, Cursor, MCP Inspector. Works on every install, including file-based users.
- `oauth` — Passport-backed OAuth for claude.ai, Claude Desktop, and ChatGPT connectors. Works with file users AND database users — the addon's own guard resolves tokens through the Statamic repository. Passport just needs a database for its own tables (sqlite is fine).

### Commands

@verbatim
<code-snippet name="Diagnose the MCP endpoint (run first when MCP misbehaves)" lang="shell">
php please mcp:doctor
</code-snippet>

<code-snippet name="Unattended token-mode setup (issues the first token)" lang="shell">
php please mcp:setup --token --user=you@site.com --yes
</code-snippet>

<code-snippet name="Unattended OAuth setup (Passport, keys, env flip, migrations)" lang="shell">
php please mcp:setup --oauth --yes
</code-snippet>
@endverbatim

Every `[FAIL]` line from `mcp:doctor` includes its exact remedy. The setup wizard is
idempotent — re-running skips satisfied steps.

### Rules

- OAuth setup never migrates users, never edits `config/auth.php`, and never touches the User model — do not do any of that by hand for MCP either. The addon registers its own auth guard in memory.
- The env flip runs BEFORE the migrate step on purpose: the addon's migration converting Passport's `user_id` columns to strings (Statamic ids are UUIDs) only loads when `STATAMIC_MCP_AUTH=oauth`. If migrations ran too early, set the env var and run `php artisan migrate` again.
- For deploys, put the Passport keys in `PASSPORT_PRIVATE_KEY` / `PASSPORT_PUBLIC_KEY` env vars instead of relying on `storage/oauth-*.key` files — per-release `passport:keys` runs silently invalidate every connected client.
- The connected user needs the **Access MCP** permission (or super).
