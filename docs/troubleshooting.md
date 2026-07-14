# Troubleshooting

```bash
php please mcp:doctor
```

One command answers "why doesn't my MCP endpoint work?". It prints the endpoint and
auth mode, then runs every check without short-circuiting, so all problems are named
at once — each `[FAIL]` carries the same remedy text the middleware answers with at
runtime.

## What doctor checks

- **Kill switch and mounting** — is MCP enabled, and is the route actually mounted?
  "Enabled but not mounted" means boot failed: the addon never bricks the host site,
  it logs `Statamic MCP failed to mount; run php please mcp:doctor` and the endpoint
  404s until the underlying exception is fixed.
- **Middleware** — every configured middleware entry must resolve to a class, alias,
  or group; a typo'd entry mounts fine and then 500s every request.
- **APP_URL** — warns on the Laravel default (`http://localhost`) and on plain
  `http://` (Bearer tokens over http travel unencrypted).
- **Token mode** — token store writable (including the ownership-mismatch case where
  `tokens.yaml` was created by a different user, e.g. root); corrupt `tokens.yaml`
  is reported with a recovery path instead of crashing (authentication fails closed
  on it); token counts distinguish **active** from **expired** and **orphaned-user**
  tokens — "5 tokens issued but none are active" is a locked door that looks
  configured.
- **OAuth mode** — Passport installed; encryption keys available (from the
  `PASSPORT_PRIVATE_KEY` / `PASSPORT_PUBLIC_KEY` env vars or key files —
  `php please mcp:keys` generates and exports them); Passport's
  tables migrated with string `user_id` columns (Statamic ids are UUIDs — the addon's
  migration converts them); a consent view bound. No user-repository check: file
  users work in OAuth mode.

## Common responses

| Response | Meaning |
|---|---|
| `401` + `WWW-Authenticate: Bearer` | Missing, malformed, expired, or revoked token — or the token's user was deleted. Deliberately identical in every case (no token enumeration). In OAuth mode: Passport's ResourceServer rejected the bearer, or its user no longer resolves. |
| `403` "requires 'access mcp'…" | The user authenticated fine but lacks the `Access MCP` permission — grant it to one of their roles in the CP. |
| `503` + `remedy` (OAuth mode) | An OAuth prerequisite is missing; the body names the exact fix. Only the MCP route is affected. |
| `404` on the endpoint | MCP is disabled, or enabled but failed to mount — check the log for `Statamic MCP failed to mount` and run `mcp:doctor`. |

## Token store locking

Concurrent token writes — CLI and CP alike — are serialized behind one of Laravel's
cache locks, so the default cache store must support atomic locks and be shared by
every writer. Array/null cache stores, or per-server caches on multi-server
deploys, degrade to no cross-process locking.

## MCP Inspector

You can also point the MCP Inspector at your server (from laravel/mcp):

```bash
php artisan mcp:inspector mcp/statamic
```
