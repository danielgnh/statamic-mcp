---
name: statamic-mcp-setup
description: Connect AI clients to a Statamic site via the Statamic MCP addon — diagnose with mcp:doctor, set up token or OAuth mode unattended, troubleshoot 401/503 responses.
---

# Statamic MCP Setup

## When to use this skill

Use this skill when the developer wants to connect an AI client (Claude Code, Cursor,
claude.ai, Claude Desktop, ChatGPT) to their Statamic site, or when the MCP endpoint
answers 401/503 and needs fixing.

## Pick the auth mode

- **Token mode** — clients that can send an `Authorization: Bearer` header: Claude Code, Cursor, MCP Inspector. Works on every install, no database needed.
- **OAuth mode** — connector clients that cannot send static headers: individual-plan claude.ai, Claude Desktop, ChatGPT. Requires Laravel Passport and database (Eloquent) users.

## Always diagnose first

```shell
php please mcp:doctor
```

Every `[FAIL]` line carries its exact remedy; `[WARN]`s alone still exit 0. Fix
`[FAIL]`s via the setup wizard below — not by hand-editing config files.

## Token mode

```shell
php please mcp:setup --token --user=you@site.com --yes
```

This issues the first token (shown exactly once) with ready-to-paste client snippets.
More tokens: `php please mcp:token another@site.com --name="Claude" --expires-days=90`.
The user must have the **Access MCP** permission (or be super).

## OAuth mode

```shell
php please mcp:setup --oauth --yes
```

Unattended, this installs Passport, publishes and runs its migrations, generates
encryption keys, adds `HasApiTokens` (+ `OAuthenticatable`) to the user model, writes
the `passport`-driver `api` guard into `config/auth.php`, and flips
`STATAMIC_MCP_AUTH=oauth` last — so an aborted run never leaves a broken mode live.
It finishes by running `mcp:doctor` and exits non-zero if anything is still wrong.

**The one human decision:** if the site stores users in files, they must be migrated to
the database first (a Passport constraint). `--yes` refuses to run this data migration
on its own. Ask the developer for explicit approval and recommend a backup, then:

```shell
php please mcp:setup --oauth --yes --migrate-users
```

The wizard is idempotent — re-running skips satisfied steps, so it is always safe to
run again after fixing a problem.

### If the wizard stops on the users schema (UUID ids)

Statamic file users are keyed by **UUID**, and `eloquent:import-users` preserves those
ids. A stock Laravel `users` table (bigint auto-increment `id`) can never hold them —
Statamic ships no converting migration. The wizard detects this and refuses **before
touching anything**, printing the remedy. This is a known, solvable state — apply the
steps, don't abort:

1. Add `Illuminate\Database\Eloquent\Concerns\HasUuids` to `App\Models\User`.
2. Write a migration converting `users.id` to a UUID primary key
   (`$table->uuid('id')->primary()`) **and every column referencing it** —
   `sessions.user_id` on a stock app, plus `role_user.user_id` / `group_user.user_id`
   if a previous attempt already created those tables. On an empty or throwaway
   `users` table, drop-and-recreate is the simplest correct conversion.
3. `php artisan migrate`, then re-run the wizard with `--migrate-users`.

When the schema is ready, the wizard handles the rest itself: it patches the
generated Statamic auth migration's `user_id` foreign keys to `foreignUuid`, and it
verifies the import actually landed users before leaving the eloquent repository
active — if the import comes up empty, it reverts `config/statamic/users.php` so CP
login keeps working. **Never flip the repository to `eloquent` by hand while the
users table is empty** — that locks everyone out of the control panel.

## Verify and connect

After setup, `php please mcp:doctor` must be green. The connector URL is the site URL
plus the configured route (default `https://your-site.com/mcp/statamic`). OAuth
connectors need the site reachable over HTTPS from the internet — they discover the
OAuth server and register themselves; no credentials are pasted anywhere.

## Troubleshooting

- **503 from the endpoint** — the response body names the missing prerequisite and its remedy; `mcp:doctor` shows the full picture.
- **Nobody can log into the Control Panel after an OAuth attempt** — `config/statamic/users.php` says `'repository' => 'eloquent'` but the users table is empty (an import that never ran). Set it back to `'file'` to restore login, then follow the UUID schema steps above.
- **`php artisan migrate` crashes on a duplicate `super` column** — a leftover `*_statamic_auth_tables.php` from an interrupted run; `mcp:doctor` names which duplicate to delete.
- **OAuth flow completes but every request 401s** — the `api` guard in `config/auth.php` exists but its driver is not `passport` (a leftover session/sanctum guard). The wizard fixes this; re-run it.
- **Wizard prints a manual snippet instead of editing** — the target file is non-standard and the wizard refused to guess. Show the snippet to the developer and let them place it; do not restructure their file yourself.
- **403 from tools** — the acting user lacks the **Access MCP** permission; grant it on their role in the Control Panel.
