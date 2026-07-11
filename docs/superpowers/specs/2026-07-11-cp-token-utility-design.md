# Design: MCP Tokens Utility — self-service token management in the CP

**Date:** 2026-07-11
**Status:** Approved by owner (design gate passed); pending spec review
**Parent spec:** `2026-07-08-statamic-mcp-design.md` (this lifts the "CP token dashboard" item out of the v1 non-goals)

## 1. Problem

In token mode, every new user needs someone with server access to run
`php please mcp:token user@site.com`. OAuth mode is already fully user-driven
(login + consent in the browser) but requires Passport + Eloquent users, so
token-mode clients (Claude Code, Cursor, scripts) and file-user sites have no
self-service path. This feature closes that gap: a CP user issues and revokes
their own tokens from the Control Panel.

### Decision log (owner-approved)

| Decision | Choice |
|---|---|
| Target | CP users issuing their **own** tokens (not admins issuing for others, not external non-CP users) |
| Permission model | Permission-gated own-token management; supers additionally see/revoke everyone's tokens; issuing *for* someone else stays console-only |
| CP placement | Statamic **Utility** (Tools → Utilities → MCP Tokens), not a nav section or profile area |
| Page scope | Basics (list / issue / revoke / show-once) **plus** a connection-help panel; no doctor duplication on the page |

## 2. Permission gate

Registering the utility gives us Statamic's native per-utility permission
("Access MCP Tokens utility") for free — no bespoke permission. Site owners
assign that permission to roles to decide who may self-issue.

- Regular users with the permission: see, issue, and revoke **their own** tokens only.
- Super admins: bypass the permission as usual and see/revoke **all** tokens
  (audit and offboarding). Even supers cannot issue a token *for* another user
  from the CP — that remains `php please mcp:token`.
- The page warns (non-blocking) when the current user lacks `access mcp`,
  mirroring the console command's issuance warning: their token would
  authenticate but every request would 403.

## 3. The page

One Blade view on CP components, three parts:

1. **Token list** — the user's own tokens (supers: all tokens, grouped by
   user), showing name, created date, expiry, and status (active/expired).
   Revoke button per row. Revocation is enforced owner-or-super server-side,
   not just hidden in the UI.
2. **Issue form** — optional name (`max:100`), expiry preset: never / 30 /
   90 / 365 days (whitelist-validated). On success the plain token is flashed
   to the session and rendered **once** with a copy button — same show-once
   contract as the console.
3. **Connection help panel** — the site's MCP endpoint URL (from the `route`
   config), a copy-paste `claude mcp add …` command, and a JSON config
   snippet. On the show-once screen these have the fresh token pre-filled;
   otherwise a `<token>` placeholder. Banners:
   - warning when the app URL is `http://` (Bearer tokens travel unencrypted);
   - notice when `auth` is `oauth` (tokens exist but won't be accepted until
     the mode is switched back to `token`). The page stays functional in
     oauth mode — it manages the store, not the mode.

## 4. Concurrency (required by existing code)

`TokenRepository` documents a single-writer CLI assumption: interleaved web
`revoke()` + `issue()` can write back pre-revoke state and resurrect a token.
The fix lives **inside the repository** so no caller needs to know: wrap the
full read-modify-write of `issue()` and `revoke()` in `Cache::lock()`
(short TTL, blocking acquire with timeout). On lock timeout, fail closed with
an exception the controller renders as "try again" — never a partial write.
The class docblock's warning is updated to describe the lock instead of
demanding one.

## 5. Routing & security

- Utility registered in the ServiceProvider via `Utility::extend()` with the
  utility's `routes()` hook: `GET` (index), `POST` (store), `DELETE
  {tokenId}` (destroy).
- Utility routes live inside the CP route group → CP session auth, CSRF, and
  the utility permission check come for free.
- `store` validates name and expiry preset; `destroy` returns 403 for
  non-owner non-supers and 404 for unknown token ids.
- The plain token appears only in the one-time flash; it is never logged,
  never re-derivable (store keeps SHA-256 hashes only — unchanged).

## 6. Testing

Feature tests (CP HTTP layer):
- no utility permission → 403 on all three routes;
- user sees only own tokens; super sees all;
- issuance flashes the secret exactly once (second GET shows no secret);
- revoke: owner ok, other user's token → 403 (regular) / ok (super), unknown id → 404;
- expiry preset outside whitelist rejected;
- oauth-mode notice banner renders; http warning renders; missing-`access mcp`
  warning renders.

Unit tests:
- repository locking — concurrent issue/revoke cannot resurrect a revoked
  token (simulate via held lock → blocked/timeout path).

Docs: README gains "issue from the CP" alongside the console instructions;
CHANGELOG entry.

## 7. Non-goals

- Issuing tokens for other users from the CP.
- Per-token scopes or permission matrices (security model stays "the token IS
  the user").
- Surfacing `mcp:doctor` checks on the page.
- Any change to OAuth mode.
