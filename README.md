# Statamic MCP

[![Latest Version](https://img.shields.io/packagist/v/danielgnh/statamic-mcp)](https://packagist.org/packages/danielgnh/statamic-mcp)
[![Tests](https://github.com/danielgnh/statamic-mcp/actions/workflows/tests.yml/badge.svg)](https://github.com/danielgnh/statamic-mcp/actions/workflows/tests.yml)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE.md)

A remote (streamable-HTTP) **MCP server for Statamic v6**. Connect Claude Code, Cursor,
claude.ai, Claude Desktop, or ChatGPT to a live Statamic site and manage content:
CRUD for **entries, taxonomy terms, and globals**, plus read-only discovery of sites,
collections, taxonomies, and blueprints. Built on the first-party
[`laravel/mcp`](https://laravel.com/docs/mcp) package.

**Design principle:** a small boring core, auth as the flagship feature, one config
file as the entire customization story. No parallel permission system, no hand-rolled
OAuth, no database tables, no CP UI.

## Requirements

- PHP ^8.3
- Statamic ^6.0 (Laravel 12 or 13)
- For OAuth mode only: `laravel/passport` + database (Eloquent) users

## Quickstart (2 minutes)

```bash
composer require danielgnh/statamic-mcp
php please mcp:token you@site.com
```

That's it — no config publishing required. The token command prints your token **once**,
plus ready-to-paste client snippets:

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

The connected user needs the **Access MCP** permission (or super). Grant it in the
Control Panel under the role's permissions — it appears in its own "MCP" group.
`mcp:token` warns you at issuance if the user doesn't have it yet.

Check your setup any time:

```bash
php please mcp:doctor
```

## Client compatibility (the honest matrix)

Static-header support across MCP clients is uneven. This is where each client stood
as of mid-2026 — client capabilities shift, the code doesn't care:

| Client | Token mode (`Authorization` header) | OAuth mode |
|---|---|---|
| Claude Code | ✅ | ✅ |
| Cursor | ✅ | ✅ |
| claude.ai custom connector (individual plan) | ❌ no static headers | ✅ |
| Claude Desktop custom connector (individual plan) | ❌ no static headers | ✅ |
| Claude Team/Enterprise connectors | ⚠️ org-admin-configured headers only | ✅ |
| ChatGPT connectors | ❌ OAuth or no-auth only | ✅ |
| Any header-capable MCP client | ✅ | depends on client |

**Rule of thumb:** developer tools work with token mode today; individual-plan
claude.ai/Claude Desktop and ChatGPT connectors need OAuth mode.

## The tools (14)

| Tool | What it does |
|---|---|
| `statamic_overview` | Call this first. Sites; the collections, taxonomies, and global sets exposed to MCP and visible to you; your capability flags per resource (`can_create`, `can_edit`, `can_publish`, `can_delete` — delete flags appear only when deletes are enabled); the acting user; server flags (`read_only`, `deletes`). |
| `blueprints_get` | A blueprint's fields (handle, type, rules, required, options, instructions) plus a valid example payload for writes. Works for collections, taxonomies, and globals. |
| `entries_list` | Paginated summaries (id, title, slug, status, url, date, updated_at) — never field data. Deterministic ordering: dated collections newest-first, others alphabetical, id as tiebreaker. |
| `entries_get` | Full entry by id or collection + slug. Raw (round-trippable) by default; `format=augmented` for display only. Long rich-text values are truncated to previews unless requested via `fields`. On revision-enabled entries, `has_working_copy` reports staged changes; the returned data is always the live entry. |
| `entries_create` | Raw-data create through Statamic's own validation. Saves an unpublished **draft by default**; `published: true` requires the publish permission. On revision-enabled collections entries are always created as drafts with an attributed initial revision. |
| `entries_update` | Shallow top-level merge of raw data (nested structures replaced wholesale). On revision-enabled collections, edits to a published entry become a **working copy** — the live entry is never touched; an existing working copy is amended (created vs amended is stated in the result). No-op updates save nothing. |
| `entries_delete` | Only registered when `deletes` is enabled. Deleting an origin cascades to all localizations (requires site access to each); revision files stay on disk as orphans, same as the CP. |
| `terms_list` | Paginated term summaries — no publish state on terms, so no status filter. |
| `terms_get` | Term by id (`taxonomy::slug`) or taxonomy + slug, raw or augmented. With `site`, `data` holds local overrides and `inherited` what falls back from the term's origin site (the taxonomy's first configured site). |
| `terms_create` | Creates in the taxonomy's origin site; localize afterwards with `terms_update` + `site`. Terms have no draft state — created terms are live immediately. |
| `terms_update` | Same merge contract as entries. `slug` renames the term: on the default site this changes the term id and moves the file; on other sites it stores a localized slug override. |
| `terms_delete` | Only registered when `deletes` is enabled. Removes the term from every site at once; Statamic's reference updater then strips references from entries (runs on the queue; skipped when `statamic.system.update_references` is false). |
| `globals_get` | Raw global variables — one set by handle, or every set you can access. With `site`, includes values inherited from the origin site. |
| `globals_update` | Merge-patch a set's variables per site (localizations created transparently on first write). Globals have no draft state: saved values are live immediately. |

Every write response states the resulting liveness ("saved as draft — not live",
"published", "working copy created — live entry unchanged", "working copy amended —
live entry unchanged", "created — live") and includes `cp_edit_url` linking the CP
edit page. Collections with revisions enabled get working copies through the same
mechanism the CP uses — the live entry is never mutated, publishing stays in the
Control Panel.

## Auth mode 1: `token` (default)

Works on **every** install, including the default file-based users. Tokens look like
`mcp_{tokenId}_{secret}`, are stored SHA-256-hashed in `storage/statamic/mcp/tokens.yaml`
(no database, no migrations), and are shown exactly once at issuance.

```bash
php please mcp:token you@site.com --name="Claude" --expires-days=90   # issue
php please mcp:tokens                                                 # list
php please mcp:token:revoke {tokenId}                                 # revoke
```

A token authenticates as the Statamic user it was issued for. Delete the user and the
token dies with them — no orphan bookkeeping.

## Auth mode 2: `oauth` (for claude.ai / Claude Desktop / ChatGPT connectors)

OAuth mode delegates everything to `laravel/mcp` + Laravel Passport (dynamic client
registration, PKCE, metadata discovery, consent screen). This addon ships **zero**
OAuth code — just this setup path:

> **The trade-off, plainly:** OAuth mode requires database (Eloquent) users because
> Passport requires an Eloquent model — a Passport constraint, not ours. File-based
> user installs must migrate first (step 1). What matters is the **driver** your
> configured user repository resolves to, not its name — `mcp:doctor` checks exactly
> that.

**Step 1 — Migrate users to the database** (skip if already on Eloquent users):

```bash
php please auth:migration        # generates the users migration
php artisan migrate
php please eloquent:import-users # imports your file users
```

Set `'repository' => 'eloquent'` in `config/statamic/users.php` per the
[Statamic guide](https://statamic.dev/tips/storing-users-in-a-database).

**Step 2 — Install Passport** and prepare the user model:

```bash
composer require laravel/passport
php artisan vendor:publish --tag=passport-migrations
php artisan migrate
php artisan passport:keys
```

Add the `Laravel\Passport\HasApiTokens` trait to your user model (`App\Models\User`),
and the `OAuthenticatable` interface per the [laravel/mcp OAuth docs](https://laravel.com/docs/mcp#authentication).

**Step 3 — Define the `api` guard.** This is the gotcha: **Laravel 12 and 13 ship no
`api` guard**, and the guard's driver must be **`passport`** — with a leftover
session/sanctum `api` guard, OAuth discovery and token issuance complete, then every
request 401-loops on tokens the guard ignores. In `config/auth.php`:

```php
'guards' => [
    'web' => [
        'driver' => 'session',
        'provider' => 'users',
    ],

    'api' => [
        'driver' => 'passport',
        'provider' => 'users',
    ],
],
```

**Step 4 — Switch the mode** and publish the consent view:

```bash
# .env
STATAMIC_MCP_AUTH=oauth
```

```bash
php artisan vendor:publish --tag=mcp-views   # laravel/mcp's OAuth consent screen
```

Now `php please mcp:doctor` should be all green, and connector clients can add
`https://your-site.com/mcp/statamic` with no manual credentials — they discover the
OAuth server, register themselves, and send your users through a normal Statamic
login + consent screen. The resulting OAuth token maps to that real Statamic user,
so permission enforcement is identical to token mode.

If any prerequisite is missing, the MCP endpoint answers **503 with the exact remedy**
(and a pointer to `mcp:doctor`) — the rest of your site is untouched, and token mode
keeps working if you switch back.

## Configuration

```bash
php artisan vendor:publish --tag=statamic-mcp-config   # → config/statamic/mcp.php
```

| Key | Default | What it does |
|---|---|---|
| `enabled` | `true` (`STATAMIC_MCP_ENABLED`) | Kill switch. When `false` the MCP route is never registered. |
| `route` | `mcp/statamic` | Where the streamable-HTTP endpoint mounts. |
| `auth` | `token` (`STATAMIC_MCP_AUTH`) | `token` or `oauth` (see above). |
| `middleware` | `['throttle:60,1']` | Prepended to the auth middleware on the MCP route. Plain Laravel. |
| `read_only` | `false` (`STATAMIC_MCP_READ_ONLY`) | Hides every write/delete tool from the server entirely. |
| `deletes` | `false` (`STATAMIC_MCP_DELETES`) | Delete tools are not even registered unless `true`. |
| `resources` | all `true` | Exposure allowlist per type: `true` = all handles, or an array like `'collections' => ['blog', 'pages']`. Controls **exposure only** — who may read/write is decided by the user's Statamic roles. |
| `per_page` | `25` | Default page size for list tools (hard-capped at 100 in code). |

## Security model: the token IS the user

There are no API scopes, no per-token permission matrices, no parallel ACL. **Every
MCP request is authenticated as a real Statamic user, and authorization is always
Statamic's native permission system** — the same roles UI you already use:

1. **Read-only switch** — `read_only` hides all write/delete tools; handlers re-check
   on every call in case a client cached the old tool list.
2. **Exposure allowlist** — `resources` decides what exists as far as MCP is concerned.
3. **Native permissions on every call** — `view/edit/create/delete {handle} entries`
   (and term/global equivalents) via the user's roles. Changing publish state — in
   either direction — additionally requires `publish {handle} entries`, exactly like
   the CP. Multi-site writes require `access {site} site`. Denials name the missing
   permission and the remedy.
4. **Deletes off by default** — delete tools aren't registered unless you opt in.

Entry creates and updates save **drafts by default**: agents draft, humans publish
(unless you explicitly pass `published: true` and the user holds the publish
permission). On revision-enabled collections publish state is CP-owned entirely:
explicit `published` values are rejected, edits become working copies, and the live
entry is never touched. Terms and globals have no draft state — writes to them are
live immediately.

## Permission cookbook

A restricted agent = **a dedicated Statamic user + a restricted role**. Manage it all
in the CP roles UI — nothing MCP-specific beyond the single `Access MCP` permission.

**A drafting agent for the blog (no publishing, no deleting):**

1. CP → Users → Roles → create role `content-agent` with permissions:
   `Access MCP`, `View blog entries`, `Edit blog entries`, `Create blog entries`.
2. CP → Users → create `claude@your-site.com` with role `content-agent`.
3. `php please mcp:token claude@your-site.com --name="Blog agent"`.

Every write this agent makes lands as a draft; it cannot publish, delete, or even see
other collections in `statamic_overview`.

**A read-only analyst:** either set `'read_only' => true` server-wide, or give the
agent's role only `Access MCP` + `View … entries` permissions — both work, use the
role when other agents on the same server still need write access.

**A publishing agent:** add `Publish blog entries` to the role. Transitions to
`published: true` now succeed (on non-revision collections — revisions publish from
the CP).

**A cleanup agent that may delete:** set `'deletes' => true` in the config **and**
add `Delete blog entries` to the role. Both gates must open.

**Scoping to one site of a multi-site install:** grant `Access {site} site` for only
that site — writes to other sites are denied with the exact missing permission named.
(Site permissions only exist on multi-site installs.)

## Troubleshooting

```bash
php please mcp:doctor
```

One command answers "why doesn't my MCP endpoint work?". It prints the endpoint and
auth mode, then runs every check without short-circuiting, so all problems are named
at once — each `[FAIL]` carries the same remedy text the middleware answers with at
runtime:

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
- **OAuth mode** — Passport installed; users database-backed (checks the **resolved
  driver** of your configured repository, not its name); `HasApiTokens` on the user
  model; the `api` guard exists **and** uses the `passport` driver.

Common responses and what they mean:

| Response | Meaning |
|---|---|
| `401` + `WWW-Authenticate: Bearer` | Missing, malformed, expired, or revoked token — or the token's user was deleted. Deliberately identical in every case (no token enumeration). In OAuth mode: the Passport guard rejected the token. |
| `403` "requires 'access mcp'…" | The user authenticated fine but lacks the `Access MCP` permission — grant it to one of their roles in the CP. |
| `503` + `remedy` (OAuth mode) | An OAuth prerequisite is missing; the body names the exact fix. Only the MCP route is affected. |
| `404` on the endpoint | MCP is disabled, or enabled but failed to mount — check the log for `Statamic MCP failed to mount` and run `mcp:doctor`. |

You can also point the MCP Inspector at your server (from laravel/mcp):

```bash
php artisan mcp:inspector mcp/statamic
```

## Testing

```bash
composer test     # Pest
composer format   # Pint
```

## License

MIT — see [LICENSE.md](LICENSE.md).
