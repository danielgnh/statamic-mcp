# Design: OAuth Connections UI — see and disconnect connector sessions in the CP

**Date:** 2026-07-12
**Status:** Approved by owner (design gate passed); pending spec review
**Parent specs:** `2026-07-08-statamic-mcp-design.md`, `2026-07-11-cp-token-utility-design.md` (this extends the utility that spec created)

## 1. Problem

Token mode has a full CP surface (list / issue / revoke). OAuth mode has none:
when a claude.ai or ChatGPT connector authorizes against the site, the only
evidence lives in Passport's database tables. A site owner cannot answer
"who is connected right now, since when, and how do I cut one off?" without
raw SQL. This feature adds that overview — and a disconnect action — to the
existing MCP utility page.

### Decision log (owner-approved)

| Decision | Choice |
|---|---|
| Placement | Extend the existing `mcp_tokens` utility into a mode-aware "MCP Access" page — no second utility, no second permission |
| Actions | Disconnect only. Rotation is explicitly rejected: OAuth self-rotates via refresh tokens, so server-side "rotate" is disconnect with extra steps |
| Data access | Passport's models (`Passport::token()`, `Passport::refreshToken()`, `Passport::client()`) behind the existing `class_exists` guard — and finally build the Passport-installed CI leg earmarked in `tests.yml` |

## 2. The concept: a connection

A **connection** is a `(user, client)` pair derived live from Passport's
tables. The addon stores nothing — the UI is a query. Connector clients
self-register via dynamic client registration, so `oauth_clients.name`
carries a human-readable label ("Claude", "ChatGPT").

Per connection the page shows:

| Column | Source | Notes |
|---|---|---|
| Client | `oauth_clients.name` | v-pre escaped — DCR names are attacker-controlled input |
| User | user email via `User::find()`, id fallback | supers only, mirroring the tokens table |
| Connected | `min(created_at)` of the pair's tokens | first authorization |
| Last refreshed | `max(created_at)` of the pair's tokens | honest proxy for activity; Passport records no per-request last-used |
| Status | Active / Expired pill | see below |

**Status semantics** answer the only security question that matters — *can
this connector still get in?* **Active** = the pair has at least one live
(non-revoked, non-expired) access token **or** live refresh token. A live
refresh token counts even when every access token is expired, because the
connector can return without re-consent. Anything else shows **Expired**.
Fully-revoked pairs still appear (audit trail); pruning old rows is
`passport:purge`, documented in the README, not our job.

## 3. Disconnect semantics

Disconnect revokes **every access token** for the `(user, client)` pair
**and each token's refresh tokens** — revoking only access tokens would leave
a silent way back in. The connector's next request 401s; well-behaved MCP
clients then re-run the OAuth flow, so the site user sees a normal Statamic
login + consent screen. Reversible from the user's side, immediate from the
server's side.

- Regular users with the utility permission: see and disconnect **their own**
  connections only.
- Supers: see and disconnect **everyone's** (offboarding, incident response).
- Owner-or-super is enforced server-side in the controller, exactly like
  token revocation — the view filtering is cosmetic.

## 4. Components

1. **`src/OAuth/ConnectionRepository.php`** — mirrors `Tokens\TokenRepository`
   in role. `all(): Collection` returns connection rows grouped from
   Passport's tokens joined with clients; `disconnect(string $userId, string
   $clientId): void` performs the revocation above via Passport's model
   accessors (respecting app-customized models). Every public method returns
   empty / no-ops when `class_exists(Passport::class)` is false, so the class
   is safely constructible in the Passport-less test leg.

2. **`McpConnectionsController`** — new controller, single RESTful `destroy`
   action on the utility's existing route group:
   `DELETE connections/{clientId}/{userId}` (composite because the resource
   is a pair). 404 for an unknown pair, 403 for non-owner non-supers. No
   `store` — connections are only ever created by the OAuth flow itself.

3. **`McpTokensUtility`** — handle stays `mcp_tokens` (permission
   compatibility for existing role configs); the visible title becomes
   "MCP Access" with an updated description. `viewData()` gains
   `connections` (presented rows, filtered to the current user unless super)
   and reuses the existing `oauthMode` flag.

4. **View (`mcp-tokens.blade.php`)** — one new panel, rendered only in OAuth
   mode, placed above the tokens panel (in that mode connections are the
   live surface, tokens the dormant one). Columns per §2, disconnect button
   with a confirm dialog, native form POST like token revocation. Empty
   state explains that connections appear when a connector (claude.ai,
   ChatGPT) is added and authorized. When OAuth mode is on but a
   prerequisite is missing (Passport absent, wrong guard, file users), the
   panel shows the existing "run mcp:doctor" style remedy alert instead of
   an empty table. All user-sourced strings (client names, emails) sit in
   `v-pre` spans per the view's standing rule.

## 5. Testing & CI

This feature builds the **Passport CI leg** that `tests.yml` already
earmarks: a second workflow job that installs `laravel/passport` (kept out
of `require-dev` — the main leg's tests depend on `class_exists(Passport)`
being genuinely false) and runs the suite against sqlite with Passport's
migrations.

- Passport-dependent tests live in the normal suite but skip when the class
  is absent (`->skip(fn () => ! class_exists(...))`), so the main leg stays
  green and honest.
- Coverage: grouping logic (multiple tokens → one row, correct min/max
  timestamps, status for live-access / live-refresh-only / all-dead pairs);
  disconnect revokes access **and** refresh tokens; owner-or-super gate
  (403/404); panel renders in oauth mode, absent in token mode; remedy alert
  when prerequisites fail.
- Existing Passport-less tests (`OAuthMisconfigTest`,
  `AuthenticateOAuthTest`) are untouched.

## 6. Out of scope (deliberate)

- **Rotation** — OAuth self-rotates; rejected above.
- **Per-request last-used tracking** — would cost a write per MCP request;
  "last refreshed" is the free, honest proxy. Revisit only if users ask.
- **`mcp:connections` console command** — natural follow-up for CLI parity
  with `mcp:token-list`, not part of this change.
- **Purging dead rows** — `passport:purge` exists; README gets a pointer.
