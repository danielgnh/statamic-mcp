# MCP Setup Wizard (`mcp:setup`) — Design

**Date:** 2026-07-12
**Status:** Approved for planning

## Problem

Connecting an AI client to the MCP server has two auth modes. Token mode is one
command. OAuth mode (claude.ai / Claude Desktop / ChatGPT connectors) is a
four-step, ~10-action README walkthrough: migrate users to Eloquent, install
Passport, hand-edit the User model, hand-edit `config/auth.php`, flip `.env`,
publish views. `mcp:doctor` diagnoses every missing prerequisite but fixes
nothing. Devs must do each remedy by hand.

## Solution

One interactive command — `php please mcp:setup` (also mounted as
`php artisan statamic:mcp:setup`) — that replaces the README walkthrough as the
onboarding path for **both** auth modes. It is the doctor's sibling: the doctor
diagnoses, setup heals. Built on Laravel Prompts, idempotent (re-running skips
everything already satisfied), and it never touches a file without a
per-step confirmation.

## Flow

### Mode selection

`intro` banner, then a `select`:

- **Token** — Claude Code, Cursor, MCP Inspector
- **OAuth** — claude.ai, Claude Desktop, ChatGPT connectors

The select is pre-highlighted from the current `statamic.mcp.auth` config. The
hint text states the trade-off: OAuth requires database (Eloquent) users — a
Passport constraint, not ours.

### Token path

1. Ensure `.env` has `STATAMIC_MCP_AUTH=token` (or unset — token is the default).
2. Prompt for the user email, issue a token via the existing `TokenRepository`
   (same path as `mcp:token`).
3. Print the plaintext token once, plus a ready-to-paste client config snippet
   containing the endpoint URL.

### OAuth path

Each step follows the same rhythm: **check → already satisfied? print
`✓ skipped` → otherwise show what will happen → confirm → apply.** In order:

1. **Eloquent users.** If the resolved users driver is not `eloquent`: warn
   that this migrates real user data and suggest a backup, then run
   `php please auth:migration` → `php artisan migrate` →
   `php please eloquent:import-users`, and flip `'repository' => 'eloquent'`
   in `config/statamic/users.php` (file edit, see below).
2. **Passport installed.** If `Laravel\Passport\Passport` is missing: run
   `composer require laravel/passport` via `Process`, output streamed.
3. **Passport plumbing.** `vendor:publish --tag=passport-migrations`,
   `php artisan migrate`, `php artisan passport:keys` (skipped when keys
   already exist).
4. **User model.** Insert the `Laravel\Passport\HasApiTokens` trait and the
   `OAuthenticatable` interface into the configured user model (file edit).
   The exact `OAuthenticatable` FQCN is read from the installed
   passport/laravel-mcp packages at runtime, not hardcoded — versions differ.
5. **`api` guard.** Insert
   `'api' => ['driver' => 'passport', 'provider' => 'users']` into the
   `'guards'` array of `config/auth.php` (file edit). If an `api` guard exists
   with the wrong driver, offer to rewrite its `driver` to `passport`.
6. **Consent views.** Offer `vendor:publish --tag=mcp-views` (optional step).
7. **Flip the mode — last.** Write `STATAMIC_MCP_AUTH=oauth` to `.env` only
   after every prerequisite above passed, so an aborted run never leaves a
   broken oauth mode live; token mode keeps working until the flip.

### Finale

Run the doctor's checks inline. All green → `outro` with the exact endpoint
URL to paste into claude.ai/ChatGPT and a reminder that connectors need the
site reachable over HTTPS. Anything red → name it and exit non-zero, exactly
like `mcp:doctor`.

## File edits — conservative, confirmed, with printed fallback

Four files belong to the developer: the user model, `config/auth.php`,
`config/statamic/users.php`, and `.env`. Rules for every edit:

- **Anchor-based string insertion.** E.g. find `'guards' => [` in
  `config/auth.php` and insert after; find the class declaration in the user
  model and extend it. No AST rewriting, no reformatting of surrounding code.
- **Bail, don't guess.** If the anchor isn't found (custom formatting, unusual
  model), the editor prints the exact snippet to paste manually and the wizard
  continues to the next step. Worst case for that step is today's README
  experience — never a mangled file.
- **Announce before touching.** Each edit shows a mini-diff of what will change,
  then a `confirm`. Declining prints the manual snippet and continues.
- **Idempotent.** An edit whose result is already present reports `✓ skipped`.

## Failure handling

- External command fails (composer, migrate): stop the wizard, print what
  completed and what remains, point at `mcp:doctor` and the README. Because the
  `.env` flip is last, the site is never left in a half-configured oauth mode.
- No rollback command: every step is individually skippable and re-runnable,
  and the doctor names anything still broken.

## Structure

- `src/Console/Setup.php` — the command; orchestration and prompts only.
- `src/Setup/` — one small single-verb class per file edit, each taking a path
  and returning applied / skipped / bailed:
  - `UserModelEditor`
  - `AuthGuardEditor`
  - `UsersRepositoryEditor`
  - `EnvWriter`
- `src/Support/OAuthPrerequisites` — the doctor's predicates (resolved users
  driver, api-guard driver, Passport present, trait on model, keys exist)
  extracted so `Doctor`, `AuthenticateOAuth`, and `Setup` answer from one
  source of truth. `Doctor` and `AuthenticateOAuth` are refactored to consume
  it; their observable behavior (messages, remedies, status codes) is
  unchanged.

## Testing

Pest, matching the existing suite style:

- **Editors:** fixture-file tests per editor — standard file is edited
  correctly; already-edited file is skipped; non-standard file bails with
  instructions and leaves the original byte-identical.
- **Command:** `Process::fake()` for composer/artisan subprocesses, faked
  prompts, assertions on resulting files/config; token path asserts a working
  token is issued.
- **Idempotency:** run the wizard twice on a satisfied install — second run is
  all skips and exits success.
- **Regression:** existing `Doctor` and `AuthenticateOAuth` tests keep passing
  after the predicate extraction.

## Documentation

README's "Auth mode" sections lead with `php please mcp:setup`; the manual
steps remain as the reference path below it (they are the wizard's bail-out
instructions). CHANGELOG entry under Unreleased.

## Out of scope (YAGNI)

- No `--no-interaction` / CI flag — this is an interactive onboarding wizard;
  scripted deploys keep the README path.
- No rollback command.
- No changes to runtime auth behavior — the wizard only arranges the same
  prerequisites the middleware already enforces.
