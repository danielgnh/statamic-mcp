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
- **OAuth mode** — connector clients that cannot send static headers: individual-plan claude.ai, Claude Desktop, ChatGPT. Requires Laravel Passport and a database for Passport's **own** tables (sqlite is fine). **Users stay wherever they are — file users work; no user migration.**

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

Unattended, this installs Passport, flips `STATAMIC_MCP_AUTH=oauth`, runs the
migrations — Passport's tables, the addon's key table, and the conversion of
`user_id` columns to strings (Statamic ids are UUIDs; Passport's stock columns
are bigint) — and provisions the encryption keys into the database. The migrate
step runs **after** the env flip on purpose: the addon's migrations only load in
OAuth mode. It finishes by running `mcp:doctor` and exits non-zero if anything
is still wrong.

There is no user migration, no `HasApiTokens` trait, and no `api` guard step: the
addon registers its own auth guard that validates bearers with Passport's
ResourceServer and resolves the user through the Statamic repository —
`config/auth.php` and the user model are never touched.

The wizard is idempotent — re-running skips satisfied steps, so it is always safe to
run again after fixing a problem.

## Deploying to other environments

Nothing OAuth-related lands in git except composer.json. Each environment needs the
mode flag and a migrate run:

```shell
STATAMIC_MCP_AUTH=oauth
```

Then `php artisan migrate --force` and `php please mcp:doctor` (non-zero exit on
problems — pipeline-friendly) per environment. There is no key step: keys are
database-managed — provisioned automatically on first use, encrypted with
APP_KEY, shared across every server, surviving releases and read-only
filesystems. To override with explicit env keys, `php please mcp:keys` prints
the pair as `PASSPORT_*` lines (`--json` for secret-store CLIs, `--write` to
fill a local `.env`) — configured keys beat the database copy. Never run
`passport:keys` per release: regenerating silently invalidates every connected
client.

## Verify and connect

After setup, `php please mcp:doctor` must be green. The connector URL is the site URL
plus the configured route (default `https://your-site.com/mcp/statamic`). OAuth
connectors need the site reachable over HTTPS from the internet — they discover the
OAuth server and register themselves; no credentials are pasted anywhere.

## Troubleshooting

- **503 from the endpoint** — the response body names the missing prerequisite and its remedy; `mcp:doctor` shows the full picture.
- **First consent crashes on insert** — Passport's `user_id` columns are still bigint; run `php artisan migrate` with `STATAMIC_MCP_AUTH=oauth` set so the addon's conversion migration loads.
- **Every request 401s after a deploy** — the environment regenerated Passport keys (`passport:keys` in the release script), invalidating old tokens. Remove the per-release keygen; with the database-managed keys (run `php artisan migrate`) the pair is stable across releases by construction.
- **503 "stored signing key can't be decrypted"** — `APP_KEY` changed after the keys were provisioned. Restore the previous `APP_KEY`, or delete the row in `statamic_mcp_oauth_keys` to provision a fresh pair (every connected client must then reconnect).
- **Wizard prints a manual snippet instead of editing** — the target file is non-standard and the wizard refused to guess. Show the snippet to the developer and let them place it; do not restructure their file yourself.
- **403 from tools** — the acting user lacks the **Access MCP** permission; grant it on their role in the Control Panel.
