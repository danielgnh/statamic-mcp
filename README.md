# Statamic MCP

[![Latest Version](https://img.shields.io/packagist/v/danielgnh/statamic-mcp)](https://packagist.org/packages/danielgnh/statamic-mcp)
[![Tests](https://github.com/danielgnh/statamic-mcp/actions/workflows/tests.yml/badge.svg)](https://github.com/danielgnh/statamic-mcp/actions/workflows/tests.yml)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE.md)

A remote (streamable-HTTP) **MCP server for Statamic v6**. Connect Claude Code, Cursor,
claude.ai, Claude Desktop, or ChatGPT to a live Statamic site and manage entries,
taxonomy terms, globals, and assets — with Statamic's own permission system deciding
who may do what. Built on the first-party [`laravel/mcp`](https://laravel.com/docs/mcp) package.

## Requirements

- PHP ^8.3
- Statamic ^6.0 (Laravel 12 or 13)
- For OAuth mode only: `laravel/passport` + database (Eloquent) users

## Installation

```bash
composer require danielgnh/statamic-mcp
php please mcp:token you@site.com
```

That's it — no config publishing required. The token is printed **once**, along with
ready-to-paste client snippets.

The connected user needs the **Access MCP** permission (or super). Grant it in the
Control Panel under the role's permissions — `mcp:token` warns you at issuance if
the user doesn't have it yet.

Prefer a guided setup? One interactive command handles either auth mode — including
every OAuth prerequisite — and finishes by running `mcp:doctor` as proof:

```bash
php please mcp:setup
```

## Connecting a client

```bash
# Claude Code
claude mcp add --transport http statamic https://your-site.com/mcp/statamic --header "Authorization: Bearer <token>"
```

```json
// Cursor — .cursor/mcp.json
{
    "mcpServers": {
        "statamic": {
            "url": "https://your-site.com/mcp/statamic",
            "headers": {
                "Authorization": "Bearer mcp_xxxxxxxxxxxx_yyyyyyyy"
            }
        }
    }
}
```

**Rule of thumb:** developer tools (Claude Code, Cursor, any header-capable client)
work with token mode today; individual-plan claude.ai / Claude Desktop connectors
and ChatGPT require [OAuth mode](#oauth-mode).

| Client | Token mode | OAuth mode |
|---|---|---|
| Claude Code / Cursor | ✅ | ✅ |
| claude.ai / Claude Desktop connectors (individual plan) | ❌ no static headers | ✅ |
| Claude Team/Enterprise connectors | ⚠️ org-admin-configured headers only | ✅ |
| ChatGPT connectors | ❌ OAuth or no-auth only | ✅ |

## What it can do

19 tools across five areas — every agent session should start with `statamic_overview`,
which reports the sites, resources, and capabilities visible to the acting user:

- **Discovery** — `statamic_overview`, `blueprints_get` (fields + a valid example payload for writes)
- **Entries** — `entries_list`, `entries_get`, `entries_create`, `entries_update`, `entries_delete`
- **Taxonomy terms** — `terms_list`, `terms_get`, `terms_create`, `terms_update`, `terms_delete`
- **Globals** — `globals_get`, `globals_update`
- **Assets** — `assets_list`, `assets_get`, `assets_upload`, `assets_update`, `assets_delete`

The write semantics are deliberately conservative:

- Entry creates and updates save **drafts by default** — agents draft, humans publish.
- On revision-enabled collections, edits become **working copies** through the same
  mechanism the CP uses; the live entry is never touched.
- Delete tools aren't even registered unless you opt in (`deletes` config).
- Every write response states the resulting liveness ("saved as draft — not live",
  "working copy created — live entry unchanged", …) and links the CP edit page.

See **[docs/tools.md](docs/tools.md)** for the full per-tool reference, including
upload limits and the SSRF policy for URL-based asset uploads.

## Authentication

### Token mode (default)

Works on **every** install, including file-based users. Tokens are stored
SHA-256-hashed in `storage/statamic/mcp/tokens.yaml` — no database, no migrations —
and shown exactly once at issuance. A token authenticates as the Statamic user it
was issued for; delete the user and the token dies with them.

```bash
php please mcp:token you@site.com --name="Claude" --expires-days=90   # issue
php please mcp:tokens                                                 # list
php please mcp:token:revoke {tokenId}                                 # revoke
```

Users can also issue and revoke their own tokens in the Control Panel at
**Tools → Utilities → MCP Access** — grant the **Access MCP Tokens utility**
permission to enable it. Super admins see (and can revoke) everyone's tokens.

### OAuth mode

For claude.ai, Claude Desktop, and ChatGPT connectors. Delegates everything to
`laravel/mcp` + Laravel Passport (dynamic client registration, PKCE, consent
screen) — this addon ships zero OAuth code. It requires database (Eloquent)
users, because Passport does.

The easy path is the wizard:

```bash
php please mcp:setup
```

It migrates users to the database, installs Passport, prepares the user model,
adds the `api` guard, and flips `STATAMIC_MCP_AUTH=oauth` — never editing a file
without showing the change first. The manual steps, plus the CP panel for viewing
and disconnecting OAuth connections, are in **[docs/oauth.md](docs/oauth.md)**.

If any prerequisite is missing, the MCP endpoint answers **503 with the exact
remedy** — the rest of your site is untouched.

## Configuration

```bash
php artisan vendor:publish --tag=statamic-mcp-config   # → config/statamic/mcp.php
```

| Key | Default | What it does |
|---|---|---|
| `enabled` | `true` (`STATAMIC_MCP_ENABLED`) | Kill switch. When `false` the MCP route is never registered. |
| `route` | `mcp/statamic` | Where the streamable-HTTP endpoint mounts. |
| `auth` | `token` (`STATAMIC_MCP_AUTH`) | `token` or `oauth`. |
| `middleware` | `['throttle:60,1']` | Prepended to the auth middleware on the MCP route. Plain Laravel. |
| `read_only` | `false` (`STATAMIC_MCP_READ_ONLY`) | Hides every write/delete tool from the server entirely. |
| `deletes` | `false` (`STATAMIC_MCP_DELETES`) | Delete tools are not even registered unless `true`. |
| `resources` | all `true` | Exposure allowlist per type: `true` = all handles, or an array like `'collections' => ['blog', 'pages']`. |
| `per_page` | `25` | Default page size for list tools (hard-capped at 100). |
| `uploads.max_size` | `10240` | Per-upload cap in **kilobytes** for `assets_upload`. |
| `uploads.source_allowlist` | `null` | Exact-host allowlist for `assets_upload` URLs. `null` = any public host; private/reserved addresses are always blocked. |

> Upgrading from v1.0? Re-publish the config or add `'asset_containers' => true`
> to `resources` — a published config **without** the key exposes no containers
> (safe by default).

## Security model: the token is the user

There are no API scopes and no parallel ACL. Every MCP request authenticates as a
real Statamic user, and authorization is always Statamic's native permission
system — the same roles UI you already use:

1. **Read-only switch** — `read_only` hides all write/delete tools.
2. **Exposure allowlist** — `resources` decides what exists as far as MCP is concerned.
3. **Native permissions on every call** — `view/edit/create/delete {handle} entries`
   (and term/global equivalents), publish permissions for publish-state changes,
   site permissions on multi-site. Denials name the missing permission and the remedy.
4. **Deletes off by default** — both the config flag and the role permission must open.

A restricted agent is just a dedicated Statamic user with a restricted role.
Ready-made recipes — drafting agent, read-only analyst, publishing agent, site
scoping — are in **[docs/permissions.md](docs/permissions.md)**.

## Troubleshooting

```bash
php please mcp:doctor
```

One command answers "why doesn't my MCP endpoint work?" — it runs every check
without short-circuiting and names each problem with the exact remedy.

| Response | Meaning |
|---|---|
| `401` | Missing, malformed, expired, or revoked token — deliberately identical in every case. |
| `403` "requires 'access mcp'…" | Authenticated fine, but the user lacks the `Access MCP` permission. |
| `503` + `remedy` (OAuth mode) | An OAuth prerequisite is missing; the body names the exact fix. |
| `404` on the endpoint | MCP is disabled, or failed to mount — run `mcp:doctor`. |

Details on every doctor check, and the MCP Inspector, are in
**[docs/troubleshooting.md](docs/troubleshooting.md)**.

## Testing

```bash
composer test     # Pest
composer format   # Pint
```

## License

MIT — see [LICENSE.md](LICENSE.md).
