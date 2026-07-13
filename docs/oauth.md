# OAuth mode

OAuth mode is for connector clients that can't send static headers — individual-plan
claude.ai and Claude Desktop connectors, and ChatGPT. It delegates everything to
`laravel/mcp` + Laravel Passport (dynamic client registration, PKCE, metadata
discovery, consent screen). This addon ships **zero** OAuth code — just this setup path.

> **The trade-off, plainly:** OAuth mode requires database (Eloquent) users because
> Passport requires an Eloquent model — a Passport constraint, not ours. File-based
> user installs must migrate first (step 1). What matters is the **driver** your
> configured user repository resolves to, not its name — `mcp:doctor` checks exactly
> that.

## The wizard (recommended)

```bash
php please mcp:setup
```

One interactive command checks, confirms, and applies every prerequisite below —
migrating users to the database, installing Passport, preparing the user model,
adding the `api` guard, and flipping `STATAMIC_MCP_AUTH` (deliberately last, so an
aborted run never leaves a broken mode live). It never edits a file without showing
the change and asking first; a file it doesn't recognize gets the exact manual
snippet instead. Re-running is safe — satisfied steps are skipped. It finishes by
running `mcp:doctor` as proof.

The manual steps below are exactly what the wizard does (and what it prints when
it bails on a non-standard file).

### Unattended (for scripts and AI agents)

```bash
php please mcp:setup --oauth --yes
```

`--yes` applies every change without confirming — each edit is still printed. The one
thing `--yes` refuses to do on its own is migrate file users to the database: that is
a data migration, so it only runs when you also pass `--migrate-users` (back up
first). Token mode is scriptable too: `php please mcp:setup --token --user=you@site.com --yes`.

If the project uses [Laravel Boost](https://laravel.com/docs/boost), this addon ships
AI guidelines and a `statamic-mcp-setup` skill that teach coding agents exactly this
flow — `boost:install` / `boost:update` picks them up automatically, so "set up OAuth
for the MCP server" becomes a one-sentence request with the user migration as the
only human decision.

## Manual setup

**Step 1 — Migrate users to the database** (skip if already on Eloquent users).

> **The UUID prerequisite — read this first.** Statamic file users are keyed by UUID,
> and `eloquent:import-users` preserves those ids: it requires the `HasUuids` trait on
> your user model, and the ids can only land in a UUID `users.id` column. Laravel's
> stock users table has a **bigint** auto-increment id — and Statamic ships no
> migration converting it. Before anything else: add
> `Illuminate\Database\Eloquent\Concerns\HasUuids` to `App\Models\User`, write a
> migration converting `users.id` to `$table->uuid('id')->primary()` (plus every
> column referencing it, e.g. `sessions.user_id`), and run `php artisan migrate`.
> The wizard checks all of this and prints these exact steps when they're missing;
> it also patches the generated Statamic auth migration's `user_id` foreign keys to
> `foreignUuid` for you.

```bash
php please auth:migration        # generates the users migration
php artisan migrate
php please eloquent:import-users # imports your file users
```

Set `'repository' => 'eloquent'` in `config/statamic/users.php` per the
[Statamic guide](https://statamic.dev/tips/storing-users-in-a-database) — but note
the importer must run with the eloquent repository configured, and **it exits 0 even
when it refuses to import**. Verify rows actually landed in the users table before
walking away: an eloquent repository over an empty table locks everyone out of the
control panel (the wizard verifies this and reverts the flip automatically).

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
