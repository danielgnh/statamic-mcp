# OAuth mode

OAuth mode is for connector clients that can't send static headers — individual-plan
claude.ai and Claude Desktop connectors, and ChatGPT. Dynamic client registration,
PKCE, metadata discovery, and the consent screen are delegated to `laravel/mcp` +
Laravel Passport.

**Your users stay exactly where they are — file users work.** The addon brings its
own auth guard: bearer tokens are validated by Passport's ResourceServer (signature,
expiry, revocation — identical to Passport's stock guard), and the token's user is
resolved through the *Statamic* repository instead of Eloquent. No user migration,
no `HasApiTokens` trait, no `api` guard to configure — the addon defines its own
guard (`statamic-mcp`) in memory, so `config/auth.php` is never touched.

What OAuth mode does need:

1. **`laravel/passport`** — for the OAuth endpoints and token machinery.
2. **A database for Passport's own tables** (clients, tokens, auth codes) — sqlite
   is fine. Only Passport's bookkeeping lives there; your users don't.
3. **Passport's encryption keys** — from `passport:keys` files or (better for
   deploys) the `PASSPORT_PRIVATE_KEY` / `PASSPORT_PUBLIC_KEY` env vars.
4. **String `user_id` columns on Passport's tables** — Statamic ids are UUID
   strings, Passport's stock columns are bigint. The addon ships a migration that
   converts them (loaded automatically in OAuth mode; safe for integer ids too).

## The wizard (recommended)

```bash
php please mcp:setup
```

One command checks, confirms, and applies all four prerequisites — installing
Passport, generating keys, flipping `STATAMIC_MCP_AUTH=oauth`, and running the
migrations (deliberately after the flip, so the addon's user_id migration loads).
Re-running is safe — satisfied steps are skipped. It finishes by running
`mcp:doctor` as proof.

### Unattended (for scripts and AI agents)

```bash
php please mcp:setup --oauth --yes
```

`--yes` applies every change without confirming — each edit is still printed.
Token mode is scriptable too: `php please mcp:setup --token --user=you@site.com --yes`.

## Manual setup

```bash
composer require laravel/passport
php artisan passport:keys                                   # or set PASSPORT_* env vars
# .env
STATAMIC_MCP_AUTH=oauth
php artisan vendor:publish --tag=passport-migrations
php artisan migrate                                          # passport tables + the addon's user_id conversion
```

Order matters once: set `STATAMIC_MCP_AUTH=oauth` **before** the final
`php artisan migrate`, because the addon's user_id migration only loads in OAuth
mode. Running migrate again after the flip fixes an accidental early run.

## Deploying

Everything above is either committed (nothing — no app files change) or
per-environment (env vars, migrations, keys). To ship an existing site:

```bash
# each environment's secret store
STATAMIC_MCP_AUTH=oauth
PASSPORT_PRIVATE_KEY="-----BEGIN RSA PRIVATE KEY-----\n...\n-----END RSA PRIVATE KEY-----"
PASSPORT_PUBLIC_KEY="-----BEGIN PUBLIC KEY-----\n...\n-----END PUBLIC KEY-----"
```

Generate the keys once (locally via `passport:keys`, then copy the file contents),
and let the deploy pipeline run `php artisan migrate --force`. Keys in env survive
releases, work on read-only filesystems (Vapor), and are shared across a
horizontally-scaled fleet — re-running `passport:keys` per release would silently
invalidate every connected client. `php please mcp:doctor` verifies each
environment and exits non-zero, so it slots into a deploy step.

## The consent screen

In OAuth mode the addon binds a working, self-contained consent screen
automatically. (Passport 12+ ships no default consent view and never binds
`AuthorizationViewResponse`, so without this `/oauth/authorize` would 500 with
*"Target [Laravel\Passport\Contracts\AuthorizationViewResponse] is not
instantiable"*. The addon closes that gap; `mcp:doctor` verifies it.)

To restyle it, publish the Blade and edit the copy — no need to publish
laravel/mcp's own view, which depends on a compiled Vite/Tailwind bundle and
500s on sites without one:

```bash
php artisan vendor:publish --tag=statamic-mcp-views   # → resources/views/vendor/statamic-mcp/oauth/authorize.blade.php
```

Or supply your own view entirely by calling `Passport::authorizationView(...)`
in your `AppServiceProvider::boot()` — the addon steps aside if you do.

Now `php please mcp:doctor` should be all green, and connector clients can add
`https://your-site.com/mcp/statamic` with no manual credentials — they discover the
OAuth server, register themselves, and send your users through a normal Statamic
login + consent screen. The resulting OAuth token maps to that real Statamic user,
so permission enforcement is identical to token mode: the `mcp:use` scope gates
entry, then Statamic's native permissions decide everything else.

If any prerequisite is missing, the MCP endpoint answers **503 with the exact remedy**
(and a pointer to `mcp:doctor`) — the rest of your site is untouched, and token mode
keeps working if you switch back.

## Upgrading from the Eloquent-users setup

Earlier versions required database (Eloquent) users, the Passport `api` guard, and
`HasApiTokens` on the user model. All of that keeps working — existing tokens stay
valid (same keys, same tables, same validation) — but none of it is required
anymore. The leftover `api` guard and trait are harmless; remove them at leisure.
Run `php artisan migrate` once after upgrading so the addon's user_id column
conversion applies (it also fixes the latent bigint-column crash for imported
UUID-keyed Eloquent users).

## Seeing and disconnecting connections

The **MCP Access** utility (Tools → Utilities) shows one row per connected
user + client pair, derived live from Passport's tables: client name (from
dynamic client registration), user, first connected, last token refresh, and
whether the connection is still usable — a live refresh token counts, since
the connector can come back without re-consent. Users see and disconnect
their own connections; supers see everyone's.

**Disconnect** revokes the pair's access tokens *and* their refresh tokens.
The connector gets a 401 on its next request and must re-run the OAuth flow
(login + consent) to reconnect. Passport does not track per-request usage,
so "last refreshed" reflects token issuance, not the last MCP call.

Dead rows accumulate as tokens expire and rotate — that is Passport's
housekeeping, not the addon's: schedule [`passport:purge`](https://laravel.com/docs/passport#purging-tokens).
