# Design: danielgnh/statamic-mcp — MCP Server Addon for Statamic v6

**Date:** 2026-07-08
**Status:** Approved by owner (design gate passed); pending spec review
**Package:** `danielgnh/statamic-mcp` (personally owned by Daniel Goncharov — not an inno-brain package), namespace `Danielgnh\StatamicMcp`, MIT, Packagist + Statamic Marketplace.

## 1. What this is

An open-source Statamic v6 addon that exposes a **remote (streamable-HTTP) MCP server** so AI clients (Claude Code, Cursor, claude.ai, Claude Desktop, ChatGPT) can manage content on a live Statamic site: CRUD for **entries, taxonomy terms, and globals**, plus read-only **discovery** (sites, collections, taxonomies, blueprints). Built on `laravel/mcp ^0.8`.

### Guiding principle (owner's words)

> "Handle the auth nicely, and keep the core really simple and straightforward so that users could configure this very well for the special needs within Statamic environment."

Every decision below is subordinate to that: a small boring core, auth as the flagship feature, one config file as the entire customization story.

### Decision log (owner-approved)

| Decision | Choice |
|---|---|
| Primary consumer | Remote/production over HTTP (not local stdio) |
| v1 surface | Entries, terms, globals CRUD + read-only discovery |
| Deletes | Config-gated, off by default |
| Distribution | Open-source addon, MIT, Marketplace |
| Approach | A: verb-family tools, two auth modes (token default, OAuth opt-in) |
| Write shape | Separate `create` + `update` tools (no upsert) |
| Publish flow | Drafts by default; publishing requires the distinct publish permission |

### Explicit non-goals for v1

Assets, navigations, forms, users tools; entry-localization creation; Bard HTML→ProseMirror write acceptance; CP token dashboard; Laravel Boost guidelines/skill contribution; **stdio/local mode** (cut on principle: a stdio process has no authenticated user to enforce permissions as; local dev is served by existing tools). All are v1.1+ candidates.

## 2. Verified foundation facts (research, 2026-07-08)

- **Statamic 6**: stable since 2026-01-28 (latest v6.24.1). PHP ^8.3, Laravel `^12.40 || ^13.0`. Addon = Composer package, provider extends `Statamic\Providers\AddonServiceProvider`, boot via `bootAddon()`. Testing via `Statamic\Testing\AddonTestCase` (Orchestra Testbench).
- **laravel/mcp v0.8.x**: first-party, implements MCP spec 2025-11-25. `Mcp::web(route, Server::class)` for streamable HTTP; tool classes with `schema(JsonSchema)`, `handle(Request): Response`, `$request->validate()` (failures become model-readable tool errors), `shouldRegister(Request): bool`, annotations `#[IsReadOnly]`/`#[IsDestructive]`/`#[IsIdempotent]`; `Server::actingAs($user)->tool(...)` test helpers; built-in cursor pagination. Pin `^0.8` (0.x — expect minor breakage; Boost pins the same way). No elicitation/sampling support — do not design around them.
- **Auth ground truth**: Passport 13 and Sanctum 4 both hard-require Eloquent user models (`HasApiTokens` = Eloquent relations). **Statamic users are file-based by default** → neither can authenticate a default install. Statamic has a documented file→Eloquent migration (`php please auth:migration` + `php please eloquent:import-users`). laravel/mcp's `Mcp::oauthRoutes()` hard-requires Passport and uses OAuth as a translation layer to the user model (single `mcp:use` scope; custom scopes unsupported). laravel/mcp explicitly blesses "any token passed via the Authorization header" as a first-class alternative.
- **Client compatibility (verified mid-2026)**: Claude Code and Cursor support static `Authorization: Bearer` headers. Individual claude.ai / Claude Desktop custom connectors **cannot** use static headers (org-admin-only Team/Enterprise beta) — they need OAuth (DCR supported) or no-auth. ChatGPT connectors: OAuth or no-auth only.
- **Statamic v6 API changes that matter here**: `->whereStatus()` (not `where('status', ...)`); `GlobalSet::addLocalization()` removed → `makeLocalization()`; `$entry->makeLocalization()`; supers only auto-pass Statamic abilities.
- **Evidence-backed MCP practice**: keep total tools under ~15 (selection accuracy + client ceilings); handles are parameters, never tool names; orientation tool kills exploratory chains; schema discovery via tools (not MCP resources); compact JSON in text blocks; no `outputSchema` (Cursor hard-fails without `structuredContent`); errors must name valid options (SEP-1303: tool errors, not protocol errors).
- **Competitor**: `cboxdk/statamic-mcp` — 11 domain routers, own OAuth 2.1 server, 21 custom scopes parallel to Statamic permissions, CP dashboard. Our differentiation: ~14 tools, zero parallel permission system, zero hand-rolled OAuth, one-screen config.

## 3. Architecture

```
Client ──HTTP──> /mcp/statamic (Mcp::web)
                   │  middleware: config('...middleware') + auth (per mode)
                   ▼
                 Server (laravel/mcp) ── 14 Tool classes
                   │                        │ base Tool: user(), ensureExposed(),
                   │                        │ json(), notFound(), liveness text
                   ▼                        ▼
                 Statamic Facades only: Entry, Term, GlobalSet, Collection,
                 Taxonomy, Site, Blueprint, User  (never filesystem, never
                 storage-driver assumptions → works on flat-file and Eloquent installs)
```

No database tables, no CP UI, no views, no JS. 23 small classes.

### Package layout

```
statamic-cms-mcp/
├── composer.json           # php ^8.3, statamic/cms ^6.0, laravel/mcp ^0.8
│                           # suggest: laravel/passport; extra.statamic.{name,description}
├── config/mcp.php          # published to config/statamic/mcp.php
├── src/
│   ├── ServiceProvider.php # bootAddon(): register route + commands (see §5, §7)
│   ├── Server.php          # #[Name('Statamic')] #[Instructions('Call statamic_overview first…')]
│   ├── Middleware/AuthenticateMcpToken.php
│   ├── Tokens/TokenRepository.php   # storage/statamic/mcp/tokens.yaml via YAML facade
│   ├── Console/ (IssueToken, ListTokens, RevokeToken, Doctor)
│   └── Tools/
│       ├── Tool.php        # the ONE abstract base
│       ├── StatamicOverview.php  BlueprintsGet.php
│       ├── EntriesList/Get/Create/Update/Delete.php
│       ├── TermsList/Get/Create/Update/Delete.php
│       └── GlobalsGet/Update.php
└── tests/                  # Pest 4, AddonTestCase, PreventsSavingStacheItemsToDisk
```

## 4. Tool surface (14 tools)

Names are explicit via `#[Name]` (snake_case, resource-first so related tools sort together). All list tools paginate (default `per_page` from config, hard cap 100 in code) returning totals + next-page hints. All read/write content tools **except the deletes** accept optional `site` (defaults to `Site::default()->handle()`); single-site installs never see it. **Site/id precedence:** when `id` is provided, `site` must be omitted or match that entry's own site — a mismatch is an error listing the entry's sibling localization ids to use instead.

| # | Tool | Annotations | Contract |
|---|---|---|---|
| 1 | `statamic_overview` | read-only, idempotent | Zero params. Returns: sites; exposed collections (handle, title, dated?, revisions?, blueprints); taxonomies; global sets; **acting user** (email, roles, is_super) + capability flags per exposed resource — `can_create`, `can_edit`, `can_publish`, `can_delete` per collection/taxonomy (delete flags only when deletes are enabled) and `can_edit` per global set, all computed via `hasPermission()`; server flags (`read_only`, deletes enabled). Filtered to the config allowlist AND what the user may view. |
| 2 | `blueprints_get` | read-only | `type` (collection\|taxonomy\|global), `handle`, optional `blueprint`. Returns fields via the **Fields API** (handle, type, rules, required, options, instructions) — never YAML parsing (v6 tabs-vs-sections YAML shape is unverified) — plus a valid example payload. Example generation is bounded: real examples for text/textarea/markdown/slug, numeric, toggle, date, select/radio/checkboxes (first option), and relation fields (obviously fake placeholders, e.g. `"REPLACE-WITH-REAL-ENTRY-ID"`); every other fieldtype falls back to `null` plus a `type` note. |
| 3 | `entries_list` | read-only | `collection`, optional `site`, `status`, `search`, `limit`, `page`. Summary columns only: id, title, slug, status, url, date, updated_at. Never field data. Status filtering via `whereStatus()`. |
| 4 | `entries_get` | read-only | `id` (or `collection`+`slug`), optional `site`, `format` (raw default \| augmented), `fields` (array of top-level field handles; no nesting in v1). Raw `$entry->data()` is the round-trippable shape. Augmented = shallow `toAugmentedArray()` with description warning "never send augmented back into update". Long Bard/rich-text values truncated to previews `{__preview, truncated: true, note: "NOT writable — fetch raw field before editing"}` unless `fields` requests them. Localized entries: annotate per-field inherited-from-origin vs local override; include origin entry id. |
| 5 | `entries_create` | write | `collection`, `data` (raw shape), optional `slug` (generated from title if absent), `site`, `date` (required for dated collections), `published` (default **false** → draft). Slug collision → error containing the existing entry's id + "use entries_update". |
| 6 | `entries_update` | write, idempotent | `id`, `data` (a **shallow top-level-key merge** — nested structures are replaced wholesale, not deep-merged), optional `slug`, `date`, `published`; `site` follows the id-precedence rule above (selector only — never creates or moves localizations). Publish state untouched unless `published` explicitly sent. Explicit `null` stores a local null (clears the field; clearing a required field errors via merged validation); resetting a field to origin-inheritance is deferred to v1.1 and the tool description says so. An update whose merged result equals current data is a no-op (no save, no revision). |
| 7 | `entries_delete` | destructive | `id`. Registered only when deletes enabled (see §6). |
| 8–12 | `terms_list/get/create/update/delete` | as entries | Same contracts against `Term` facade; `taxonomy` instead of `collection`; no `date`/`published` (terms have no status). Multi-site: term localizations are data overrides within one term (not separate entities), so `terms_get`/`terms_update` with `site` read/write the site's localized data via `$term->in($site)`, transparently creating it on first write — the globals rule, not the entries rule. |
| 13 | `globals_get` | read-only | `handle` (or omit → all sets filtered to the allowlist AND what the user may view, silently omitting the rest — same rule as `statamic_overview`), optional `site`. Returns variables for the site localization. An existing-but-unexposed handle is indistinguishable from a missing one: same `notFound()` error listing only exposed handles. |
| 14 | `globals_update` | write, idempotent | `handle`, `data` merge-patch, optional `site`. Creates a missing site localization transparently via `$set->makeLocalization($site)` → `$vars->save()`. |

Every write response states **resulting liveness** ("saved as draft — not live", "published", "working copy created — live entry unchanged") and includes `cp_edit_url` so humans can jump from the transcript into the CP.

## 5. Auth — the flagship

**Invariant (both modes): the MCP request is authenticated as a real Statamic user; authorization is always Statamic's native permission system.** No parallel scopes: a restricted agent = a dedicated Statamic user with a restricted role, managed in the CP roles UI. Tokens die with their user (`User::find` fails → 401) — no orphan bookkeeping.

### Mode 1 — `token` (default; works on every install, including file-based users)

- Format: `mcp_{tokenId}_{secret}` (prefix enables secret-scanning rules; tokenId gives O(1) lookup). Middleware: cap header length (hash-DoS guard) → parse positionally → `hash_equals` against stored SHA-256 → expiry check → `Statamic\Facades\User::find($userId)`.
- **Critical detail:** the middleware authenticates via `Auth::setUser($user)` + `Auth::shouldUse(...)` (not merely `$request->setUserResolver()`), so `User::current()`, revision authorship, and `EntrySaved`-style listeners all see the acting user.
- Storage: `storage/statamic/mcp/tokens.yaml` — hash, user id, name, created/expires. Plaintext shown exactly once at issuance. No DB, no migrations.
- Commands: `php please mcp:token {email} {--name=} {--expires-days=}` (prints the token once **plus ready-to-paste Claude Code `claude mcp add …--header` and Cursor `mcp.json` snippets**); `mcp:tokens`; `mcp:token:revoke {id}`.
- Client coverage (stated honestly in README and command output): Claude Code, Cursor, any header-capable client, Claude Desktop/claude.ai **Team/Enterprise via org-admin header config only**. Individual claude.ai/Desktop/ChatGPT users → OAuth mode.

### Mode 2 — `oauth` (opt-in: `'auth' => 'oauth'`)

- Delegates entirely to `Mcp::oauthRoutes()` + Laravel Passport: DCR, PKCE, .well-known metadata, consent view — **we ship zero OAuth code**, only a documented setup path: (1) migrate users to Eloquent if file-based (`php please auth:migration` + `eloquent:import-users` — a documented, mostly mechanical Statamic guide); (2) install Passport (`HasApiTokens` + `OAuthenticatable` on the user model); (3) **define the `api` guard in `config/auth.php`** (`['driver' => 'passport', 'provider' => 'users']`) — Laravel 12/13 ship no `api` guard and `auth:api` throws without it; (4) publish laravel/mcp's consent view.
- README states the trade-off plainly: *"OAuth mode requires database (Eloquent) users because Passport requires an Eloquent model — a Passport constraint, not ours."*
- Consent login runs through Statamic's normal web guard → the OAuth token maps to the same real Statamic user → identical permission enforcement.

### Shared plumbing

- The base `Tool::user()` normalizes via `Statamic\Facades\User::fromUser($request->user())` so tools are mode-agnostic (under Passport, `$request->user()` is the Eloquent model; permission checks need the Statamic user). Any middleware that checks permissions normalizes the same way.
- **Misconfiguration never bricks the site**: `bootAddon()` never throws. If `auth=oauth` but Passport is missing or users aren't Eloquent, the MCP route itself answers 503 with the remedy; the rest of the site is untouched.
- `php please mcp:doctor`: prints endpoint + auth mode; checks token-store writability; in oauth mode checks Passport installed, user model traits, `api` guard defined; warns when zero tokens exist ("locked door") and when `enabled=false`.
- One addon permission registered via `Permission::extend()`: **`access mcp`** — the only custom permission in the entire package. Every request requires it (checked after auth, normalized user); everything else is Statamic's own strings.

## 6. Authorization & safety (four independent layers)

1. **Read-only switch** (`read_only` config): write/delete tools hidden via `shouldRegister()` AND re-checked in-handler (stale client tool caches are a documented UX wart, not a security hole).
2. **Exposure allowlist** (`resources` config): what exists as far as MCP is concerned. The config comment states: *"This controls EXPOSURE only. Who may read/write is decided by the connected user's Statamic roles."* `ensureExposed()` in the base Tool enforces on every call.
3. **Native permissions on every call**: `view/edit/create/delete {handle} entries` (and term/global equivalents) via `$user->hasPermission('…')` — the canonical API for Statamic permission strings (supers auto-pass). Entity policies (`$user->can('edit', $entry)`) are not used as the primary check; permission strings keep the check uniform across resources. **Publish is distinct**: any transition to `published: true` (create or update) additionally requires `publish {collection} entries` — matching the CP's own gate. Non-default `site` additionally requires `access {site} site`. Denials name the exact missing permission + remedy: `"requires 'edit articles entries' — grant it to a role of claude@site.com in the Control Panel"`.
4. **Deletes off by default** (`deletes` config): delete tools unregistered unless enabled; `#[IsDestructive]` when on.

### Drafts & revisions

- Creates default to `published: false`. Agents draft, humans publish (overridable per call, permission-gated).
- Collections with **revisions enabled**: data writes create an unpublished **working copy** through the same revision mechanism the CP uses (attributed to the acting user, with a revision message naming the tool) — never mutating the live entry. Any explicit `published` value (**true or false**) on such collections is rejected with "this collection uses revisions; publish/unpublish from the Control Panel". Creates in revision-enabled collections produce an unpublished draft through the same mechanism. An update whose merged data equals current data is a no-op — no save, no working copy, no revision. *(Exact working-copy API call flagged for build-time verification — the behavioral contract is fixed regardless.)*

## 7. Config — the entire customization story (8 keys)

Published to `config/statamic/mcp.php` (mirrors first-party `config/statamic/api.php` idioms):

```php
return [
    // Kill switch. When false the MCP route is never registered.
    'enabled' => env('STATAMIC_MCP_ENABLED', true),

    // Where Mcp::web() mounts the streamable-HTTP endpoint.
    'route' => 'mcp/statamic',

    // 'token' — addon-issued tokens (file or Eloquent users): php please mcp:token you@site.com
    // 'oauth' — Laravel Passport via laravel/mcp, for claude.ai/ChatGPT connectors.
    //           Requires Eloquent users + laravel/passport. See README.
    'auth' => env('STATAMIC_MCP_AUTH', 'token'),

    // Prepended to the auth middleware on the MCP route. Plain Laravel.
    'middleware' => ['throttle:60,1'],

    // Hide every write/delete tool from the server entirely.
    'read_only' => env('STATAMIC_MCP_READ_ONLY', false),

    // Delete tools are not even registered unless true.
    'deletes' => env('STATAMIC_MCP_DELETES', false),

    // What exists as far as MCP is concerned. true = all handles, or an
    // array of handles: 'collections' => ['blog', 'pages'].
    // NOTE: this controls EXPOSURE only. Who may read/write what is decided
    // by the connected user's Statamic roles & permissions — nothing here.
    'resources' => [
        'collections' => true,
        'taxonomies'  => true,
        'globals'     => true,
    ],

    // Default page size for list tools (hard-capped at 100 in code).
    'per_page' => 25,
];
```

**Zero-config path:** `composer require danielgnh/statamic-mcp` → `php please mcp:token you@site.com` → paste URL + header into the client. No publish step required.

**Deliberately absent** (YAGNI — each with a built-in alternative): per-token scopes (use a dedicated user + role), per-resource read/write matrices (use roles), site allowlists (use `access {site} site`), audit-log config (listen to Statamic's own events), response-format knobs, tool enable/disable maps, CP dashboard toggles.

## 8. Data semantics & error design

- **Raw data is the only write shape.** Writes validate through Statamic's own pipeline — `$blueprint->fields()->addValues($merged)->validator()->validate()` where `$merged = array_merge($existingValues, $patch)` (deliberately a **shallow top-level merge** — nested structures are replaced wholesale, matching the update tools' contract) — so partial updates never false-fail required fields. Validation failures → `Response::error()` with field-level messages (never protocol errors; enables one-round-trip self-correction).
- **Unknown keys rejected** (Statamic would silently store typos): diff data keys against blueprint handles; error lists valid handles + Levenshtein "did you mean `hero_image`?".
- **Not-found errors name valid options**: `"collection 'blog_post' not found — available: blog, pages, news"` (shared `notFound()` helper).
- Responses: compact JSON inside `Response::text()`; stable IDs everywhere for chaining. **No `outputSchema`** (Cursor hard-fails unless `structuredContent` always present; not worth the contract).
- v6-correct calls throughout: `whereStatus()`, `makeLocalization()`, `Entry::make()->date()` for dated collections, `User::fromUser()`.

## 9. Testing & quality gates

- Pest 4 on `Statamic\Testing\AddonTestCase` + `PreventsSavingStacheItemsToDisk`; fixtures under `tests/__fixtures__`.
- Per-tool feature tests via `Server::actingAs($user)->tool(ToolClass::class, [...])->assertOk()/assertHasErrors()` covering: happy paths, permission denials per role (incl. edit-without-publish), exposure filtering, draft default, revision working-copy behavior, unknown-key rejection, merged-validation on partial updates, multi-site localization reads/writes, pagination.
- Middleware tests: 401 (bad/expired/revoked token, deleted user), header length cap, `Auth::setUser` visibility (`User::current()` inside a tool).
- `shouldRegister` tests for `read_only`/`deletes`/misconfigured-oauth 503.
- CI: GitHub Actions matrix — Statamic 6 lowest/highest × Laravel 12/13, Pint (`composer format`), PHPStan.
- Manual loop: `php artisan mcp:inspector` against the local endpoint.

## 10. Risks & build-time verifications

| Risk | Mitigation |
|---|---|
| laravel/mcp is 0.x; MCP spec 2026-07-28 RC (stateless core) imminent | Pin `^0.8`; zero reliance on protocol session state; first-party package absorbs spec churn |
| Exact revisions working-copy API in v6 | Verify against statamic/cms 6.x source during build; behavioral contract fixed (§6) |
| `$blueprint->fields()->addValues()->validator()` exact signature in v6 | Verify during build; it's the CP's own path |
| Blueprint YAML `tabs:` vs `sections:` in v6 | Never parse YAML; Fields API only |
| laravel/mcp `Request::user()` source | Mitigated by `Auth::setUser` + `shouldUse` (auth manager, not just resolver) |
| Client auth capabilities shift (e.g. individual-user header support ships) | Compatibility matrix isolated to README section; no code impact |
| Statamic 6.6+ pin question (competitor pins 6.6) | Start at `^6.0`; raise only if a needed API demands it |

## 11. Milestones

1. **M1 — Skeleton**: addon scaffold, composer, ServiceProvider, config, route registration, Server class, `statamic_overview` + `blueprints_get`, token auth end-to-end (guard + `mcp:token` + tests). *Connectable and useful read-only.*
2. **M2 — Entries**: list/get/create/update (+delete behind flag), drafts/publish gate, revisions handling, validation + error design, multi-site reads.
3. **M3 — Terms + globals**: remaining 7 tools.
4. **M4 — OAuth mode + doctor**: Passport path, 503-with-remedy, `mcp:doctor`, README client matrix.
5. **M5 — Release**: docs, Marketplace listing, CI matrix, tag v1.0.0.
