## Statamic MCP

This package serves an MCP endpoint (default `/mcp/statamic`) so AI clients can manage
Statamic entries, taxonomy terms, globals, and assets. Every request authenticates as a
real Statamic user and honors that user's permissions — there is no parallel permission
system to configure.

### Auth modes

- `token` (default) — static Bearer tokens for header-capable clients: Claude Code, Cursor, MCP Inspector. Works on every install, including file-based users.
- `oauth` — Passport-backed OAuth for claude.ai, Claude Desktop, and ChatGPT connectors. Requires database (Eloquent) users — a Passport constraint.

### Commands

@verbatim
<code-snippet name="Diagnose the MCP endpoint (run first when MCP misbehaves)" lang="shell">
php please mcp:doctor
</code-snippet>

<code-snippet name="Unattended token-mode setup (issues the first token)" lang="shell">
php please mcp:setup --token --user=you@site.com --yes
</code-snippet>

<code-snippet name="Unattended OAuth setup (Passport, keys, user model, api guard, env flip)" lang="shell">
php please mcp:setup --oauth --yes
</code-snippet>
@endverbatim

Every `[FAIL]` line from `mcp:doctor` includes its exact remedy. The setup wizard is
idempotent — re-running skips satisfied steps.

### Rules

- If the site stores users in files, OAuth setup must first migrate them to the database. `--yes` deliberately refuses to do this on its own: **ask the developer for explicit approval** (and a backup), then re-run with `--migrate-users`.
- Statamic file users are keyed by **UUID** and `eloquent:import-users` preserves those ids — the user model needs the `HasUuids` trait and `users.id` must be a UUID column. On a stock Laravel schema (bigint `users.id`) the wizard stops and prints the exact conversion steps: add `HasUuids` to the model, migrate `users.id` (and every referencing foreign key) to UUID, `php artisan migrate`, re-run. This is expected and solvable — apply the steps rather than abandoning OAuth setup.
- Never flip `config/statamic/users.php` to `'eloquent'` by hand while the users table is empty — control panel login reads that table and everyone gets locked out. The wizard flips it only after verifying imported users actually exist, and reverts if they don't.
- Never hand-edit `config/auth.php`, `config/statamic/users.php`, or the User model for MCP setup — the wizard applies those edits deterministically and prints a manual snippet when a file is non-standard.
- The connected user needs the **Access MCP** permission (or super).
