# OAuth Connections UI Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Show OAuth connector connections (who, which client, since when, still live?) on the existing CP utility page, with a per-connection Disconnect action.

**Architecture:** A `ConnectionRepository` derives connections — `(user, client)` pairs — live from Passport's tables (nothing stored by the addon). A one-action `McpConnectionsController` handles disconnect (revokes access **and** refresh tokens). The existing `mcp_tokens` utility (handle unchanged) gains a Connections panel rendered only in OAuth mode, and is retitled "MCP Access". Spec: `docs/superpowers/specs/2026-07-12-oauth-connections-ui-design.md`.

**Tech Stack:** Statamic 6 addon, laravel/passport (suggested dep — installed ephemerally for dev/CI leg, NEVER committed to composer.json), Pest, PHPStan level 5, Pint.

**Critical constraints (read before Task 1):**

- `laravel/passport` must stay OUT of `composer.json` — several existing tests assert `class_exists(Passport::class) === false`. You install it locally for TDD (Task 1) and remove it at the end (Task 9). NEVER commit a composer.json containing passport.
- `composer-integrity.lock` is machine-local (soak-time plugin) — never commit it. `composer.lock` is untracked.
- Never run `pest --parallel` (shared dev-null sandbox). Never run bare `vendor/bin/testbench`.
- Run `composer format` (Pint) before every commit.
- The user's global rule: use `::query()` on Eloquent models — hence `$tokenModel::query()` via `Passport::tokenModel()` class-strings, not `Passport::token()->newQuery()`.

---

### Task 0: Branch and commit the pending UI polish

The working tree has uncommitted, complete changes (modal-based token issuance + help modal in the blade, matching CHANGELOG wording). Commit them as their own commit so feature commits stay clean.

**Files:**
- Modify: nothing — commit existing working-tree state of `resources/views/utilities/mcp-tokens.blade.php` and `CHANGELOG.md`

- [ ] **Step 1: Create the feature branch**

```bash
git checkout -b feat/oauth-connections-ui
```

- [ ] **Step 2: Verify the pending diff is only the two expected files**

Run: `git status --short`
Expected: exactly `M CHANGELOG.md` and `M resources/views/utilities/mcp-tokens.blade.php` (plus this plan file, untracked or modified — commit it too or leave it; committing it now is fine).

- [ ] **Step 3: Run the suite to confirm the pending changes are green**

Run: `vendor/bin/pest`
Expected: all pass.

- [ ] **Step 4: Commit**

```bash
git add CHANGELOG.md resources/views/utilities/mcp-tokens.blade.php docs/superpowers/plans/2026-07-12-oauth-connections-ui.md
git commit -m "feat: move token issuance and connect help into header modals

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 1: Install Passport locally (ephemeral) and make the passport-absence test symmetric

**Files:**
- Modify: `tests/Feature/OAuthMisconfigTest.php` (the `names Passport when only the package is missing` test)
- NOT committed: `composer.json` / `composer.lock` changes from the install

- [ ] **Step 1: Install Passport (local only)**

```bash
composer require laravel/passport --no-interaction
```

Expected: installs cleanly. If the soak-time integrity plugin blocks it, report to the user and stop — do not bypass it.

- [ ] **Step 2: Run the suite to see what flips**

Run: `vendor/bin/pest`
Expected: exactly one failure — `OAuthMisconfigTest` → `names Passport when only the package is missing` (its `expect(class_exists(Passport::class))->toBeFalse()` is now false; the request may also 500 since the passport auth driver has no registered provider in testbench).

If `UsesOAuthMode`-based tests (e.g. `McpRouteOAuthTest`) also fail, the cause is `Mcp::oauthRoutes()` now executing at boot with Passport present. Fix by wrapping the failing assertion's environment, not production code — report what you find in the commit message. Do not proceed with unexplained failures.

- [ ] **Step 3: Add the symmetric skip**

In `tests/Feature/OAuthMisconfigTest.php`, add to the `names Passport when only the package is missing` test (chain on the closing of the `it(...)` call):

```php
})->skip(fn () => class_exists(Passport::class), 'asserts Passport absence — skipped in the Passport CI leg');
```

And update the file-header comment (lines ~12–13) from "Passport is deliberately absent from require-dev, so class_exists() is genuinely false" to:

```php
// simulates a site working through the prerequisites one by one. Passport is
// deliberately absent from require-dev, so class_exists() is genuinely false in
// the main CI leg; the one test that asserts that absence skips itself when the
// Passport CI leg installs the package.
```

- [ ] **Step 4: Run the suite — green both ways**

Run: `vendor/bin/pest`
Expected: PASS with 1 skipped (`asserts Passport absence…`).

- [ ] **Step 5: Format and commit (test file ONLY)**

```bash
composer format
git add tests/Feature/OAuthMisconfigTest.php
git commit -m "test: skip the passport-absence assertion when Passport is installed

Prepares for the Passport CI leg: the main leg keeps asserting the honest
class_exists(false) branch; the leg with laravel/passport installed skips it.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
git status --short
```

Expected `git status`: `composer.json` modified (passport in require) — leave it uncommitted; it is removed in Task 9.

---

### Task 2: Test support — Passport table schema + seed helpers

**Files:**
- Create: `tests/Support/OAuthFixtures.php`

No test of its own — it is exercised by every test in Tasks 3–5. Committed with Task 3.

- [ ] **Step 1: Write the support class**

```php
<?php

namespace Danielgnh\StatamicMcp\Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

/**
 * Schema + seed helpers for Passport-leg tests. The tables mirror Passport's
 * schema for every column its models or ConnectionRepository touch; user_id
 * is a string (Passport uses foreignId) so file-user fixture ids work under
 * sqlite — production OAuth requires Eloquent users, but these tests exercise
 * grouping and revocation, not authentication.
 */
class OAuthFixtures
{
    public static function migratePassport(): void
    {
        Schema::create('oauth_clients', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('owner_type')->nullable();
            $table->string('owner_id')->nullable();
            $table->string('name');
            $table->string('secret')->nullable();
            $table->string('provider')->nullable();
            $table->text('redirect_uris')->nullable();
            $table->text('grant_types')->nullable();
            $table->boolean('revoked')->default(false);
            $table->timestamps();
        });

        Schema::create('oauth_access_tokens', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('user_id')->nullable()->index();
            $table->string('client_id');
            $table->string('name')->nullable();
            $table->text('scopes')->nullable();
            $table->boolean('revoked');
            $table->timestamps();
            $table->dateTime('expires_at')->nullable();
        });

        Schema::create('oauth_refresh_tokens', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('access_token_id')->index();
            $table->boolean('revoked');
            $table->dateTime('expires_at')->nullable();
        });
    }

    /** Config that makes ConnectionRepository::ready() true (with the tables migrated). */
    public static function oauthReadyConfig(): void
    {
        config([
            'statamic.mcp.auth' => 'oauth',
            'statamic.users.repository' => 'eloquent',
            'auth.guards.api' => ['driver' => 'passport', 'provider' => 'users'],
        ]);
    }

    public static function client(string $name = 'Claude'): string
    {
        $model = Passport::clientModel();

        $attributes = [
            'id' => (string) Str::uuid(),
            'name' => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        (new $model)->forceFill($attributes)->save();

        return $attributes['id'];
    }

    public static function accessToken(string $userId, string $clientId, array $overrides = []): string
    {
        $model = Passport::tokenModel();

        $attributes = array_merge([
            'id' => Str::random(40),
            'user_id' => $userId,
            'client_id' => $clientId,
            'revoked' => false,
            'created_at' => now(),
            'updated_at' => now(),
            'expires_at' => now()->addDay(),
        ], $overrides);

        (new $model)->forceFill($attributes)->save();

        return $attributes['id'];
    }

    public static function refreshToken(string $accessTokenId, array $overrides = []): string
    {
        $model = Passport::refreshTokenModel();

        $attributes = array_merge([
            'id' => Str::random(40),
            'access_token_id' => $accessTokenId,
            'revoked' => false,
            'expires_at' => now()->addDays(30),
        ], $overrides);

        (new $model)->forceFill($attributes)->save();

        return $attributes['id'];
    }
}
```

- [ ] **Step 2: Sanity-check it compiles**

Run: `php -l tests/Support/OAuthFixtures.php`
Expected: `No syntax errors detected`

Note: if `(new $model)->forceFill(...)->save()` trips over a Passport model cast or default (e.g. Client secret hashing), fall back to `DB::table('oauth_...')->insert($attributes)` inside the same helpers — the helpers' contracts don't change. Serialize `scopes`/`redirect_uris` as JSON strings if you do.

---

### Task 3: ConnectionRepository (TDD)

**Files:**
- Create: `tests/OAuth/ConnectionRepositoryTest.php`
- Create: `src/OAuth/ConnectionRepository.php`
- Modify: `phpstan.neon` (scoped ignore for Passport symbols)

- [ ] **Step 1: Write the failing tests**

`tests/OAuth/ConnectionRepositoryTest.php`:

```php
<?php

use Danielgnh\StatamicMcp\OAuth\ConnectionRepository;
use Danielgnh\StatamicMcp\Tests\Support\OAuthFixtures;
use Laravel\Passport\Passport;

// Every test here needs laravel/passport — the main CI leg (where the package
// is deliberately absent) skips them; the Passport CI leg runs them.
$requiresPassport = fn () => ! class_exists(Passport::class);

beforeEach(function () {
    if (class_exists(Passport::class)) {
        OAuthFixtures::migratePassport();
        OAuthFixtures::oauthReadyConfig();
    }
});

it('is not ready without the oauth prerequisites', function () {
    config(['auth.guards.api' => ['driver' => 'session', 'provider' => 'users']]);

    $repository = new ConnectionRepository;

    expect($repository->ready())->toBeFalse()
        ->and($repository->all())->toBeEmpty()
        ->and($repository->disconnect('u1', 'c1'))->toBeFalse();
})->skip($requiresPassport, 'requires laravel/passport (Passport CI leg)');

it('is ready when users are eloquent, the api guard is passport, and the tables exist', function () {
    expect((new ConnectionRepository)->ready())->toBeTrue();
})->skip($requiresPassport, 'requires laravel/passport (Passport CI leg)');

it('groups tokens into one connection per user and client with honest timestamps', function () {
    $claude = OAuthFixtures::client('Claude');
    $chatgpt = OAuthFixtures::client('ChatGPT');

    OAuthFixtures::accessToken('user-1', $claude, ['created_at' => now()->subDays(10)]);
    OAuthFixtures::accessToken('user-1', $claude, ['created_at' => now()->subHour()]);
    OAuthFixtures::accessToken('user-1', $chatgpt);
    OAuthFixtures::accessToken('user-2', $claude);

    $connections = (new ConnectionRepository)->all();

    expect($connections)->toHaveCount(3);

    $pair = $connections->first(fn ($c) => $c['user_id'] === 'user-1' && $c['client_name'] === 'Claude');

    expect($pair['connected_at']->toDateString())->toBe(now()->subDays(10)->toDateString())
        ->and($pair['last_refreshed_at']->toDateString())->toBe(now()->toDateString())
        ->and($pair['active'])->toBeTrue();
})->skip($requiresPassport, 'requires laravel/passport (Passport CI leg)');

it('falls back to an unknown-client label when the client row is gone', function () {
    OAuthFixtures::accessToken('user-1', 'deleted-client-id');

    expect((new ConnectionRepository)->all()->first()['client_name'])->toBe('Unknown client');
})->skip($requiresPassport, 'requires laravel/passport (Passport CI leg)');

it('marks a pair active when an expired access token still has a live refresh token', function () {
    $client = OAuthFixtures::client();

    $tokenId = OAuthFixtures::accessToken('user-1', $client, ['expires_at' => now()->subHour()]);
    OAuthFixtures::refreshToken($tokenId);

    expect((new ConnectionRepository)->all()->first()['active'])->toBeTrue();
})->skip($requiresPassport, 'requires laravel/passport (Passport CI leg)');

it('marks a pair inactive when the access token is revoked, even with a live refresh row', function () {
    // Passport's refresh grant checks BOTH rows: a revoked access token kills
    // its refresh token's usability. Status must agree, or the page would
    // show Active for a connector that can no longer get in.
    $client = OAuthFixtures::client();

    $tokenId = OAuthFixtures::accessToken('user-1', $client, ['revoked' => true]);
    OAuthFixtures::refreshToken($tokenId);

    expect((new ConnectionRepository)->all()->first()['active'])->toBeFalse();
})->skip($requiresPassport, 'requires laravel/passport (Passport CI leg)');

it('marks a pair inactive when every token is expired with no live refresh', function () {
    $client = OAuthFixtures::client();

    $tokenId = OAuthFixtures::accessToken('user-1', $client, ['expires_at' => now()->subHour()]);
    OAuthFixtures::refreshToken($tokenId, ['revoked' => true]);

    expect((new ConnectionRepository)->all()->first()['active'])->toBeFalse();
})->skip($requiresPassport, 'requires laravel/passport (Passport CI leg)');

it('disconnects a pair by revoking its access AND refresh tokens', function () {
    $client = OAuthFixtures::client();
    $other = OAuthFixtures::client('ChatGPT');

    $mine = OAuthFixtures::accessToken('user-1', $client);
    $myRefresh = OAuthFixtures::refreshToken($mine);
    $unrelated = OAuthFixtures::accessToken('user-1', $other);

    $repository = new ConnectionRepository;

    expect($repository->disconnect('user-1', $client))->toBeTrue();

    $tokenModel = Passport::tokenModel();
    $refreshModel = Passport::refreshTokenModel();

    expect($tokenModel::query()->find($mine)->revoked)->toBeTrue()
        ->and($refreshModel::query()->find($myRefresh)->revoked)->toBeTrue()
        ->and($tokenModel::query()->find($unrelated)->revoked)->toBeFalse();
})->skip($requiresPassport, 'requires laravel/passport (Passport CI leg)');

it('returns false disconnecting a pair that has no tokens at all', function () {
    expect((new ConnectionRepository)->disconnect('nobody', 'no-client'))->toBeFalse();
})->skip($requiresPassport, 'requires laravel/passport (Passport CI leg)');

it('treats disconnecting an already-dead pair as a successful no-op', function () {
    $client = OAuthFixtures::client();
    OAuthFixtures::accessToken('user-1', $client, ['revoked' => true]);

    expect((new ConnectionRepository)->disconnect('user-1', $client))->toBeTrue();
})->skip($requiresPassport, 'requires laravel/passport (Passport CI leg)');
```

- [ ] **Step 2: Run to verify they fail**

Run: `vendor/bin/pest tests/OAuth/ConnectionRepositoryTest.php`
Expected: FAIL — `Class "Danielgnh\StatamicMcp\OAuth\ConnectionRepository" not found` (not skips — Passport is installed locally from Task 1).

- [ ] **Step 3: Implement the repository**

`src/OAuth/ConnectionRepository.php`:

```php
<?php

namespace Danielgnh\StatamicMcp\OAuth;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Laravel\Passport\Passport;

/**
 * Derives connector "connections" — (user, client) pairs — live from
 * Passport's tables; the addon stores nothing. Every public method degrades
 * to an empty result when the OAuth prerequisites are missing, so the CP
 * utility page renders (with a remedy alert) on a half-configured site
 * instead of 500ing. Model classes come from Passport's accessors, so apps
 * that customize them stay supported.
 */
class ConnectionRepository
{
    /**
     * The same prerequisites AuthenticateOAuth preflights and mcp:doctor
     * checks, plus the migrated table — oauth mode switched on before
     * `php artisan migrate` must not break the page.
     */
    public function ready(): bool
    {
        $repository = config('statamic.users.repository', 'file');

        return config('statamic.users.repositories.'.$repository.'.driver') === 'eloquent'
            && config('auth.guards.api.driver') === 'passport'
            && class_exists(Passport::class)
            && Schema::hasTable('oauth_access_tokens');
    }

    /**
     * One row per (user, client) pair, newest activity first. 'active'
     * answers "can this connector still get in?" — a live refresh token
     * counts even when every access token has expired, because the refresh
     * grant needs no re-consent; but a revoked access token kills its
     * refresh token too (Passport checks both rows), so revoked never
     * counts.
     *
     * @return Collection<int, array{user_id: string, client_id: string, client_name: string, connected_at: \Illuminate\Support\Carbon, last_refreshed_at: \Illuminate\Support\Carbon, active: bool}>
     */
    public function all(): Collection
    {
        if (! $this->ready()) {
            return collect();
        }

        $tokenModel = Passport::tokenModel();
        $tokens = $tokenModel::query()->get();

        if ($tokens->isEmpty()) {
            return collect();
        }

        $clientModel = Passport::clientModel();
        $clients = $clientModel::query()
            ->whereIn('id', $tokens->pluck('client_id')->unique())
            ->get()
            ->keyBy(fn ($client) => (string) $client->getKey());

        $refreshModel = Passport::refreshTokenModel();
        $refreshable = $refreshModel::query()
            ->whereIn('access_token_id', $tokens->pluck('id'))
            ->where('revoked', false)
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->pluck('access_token_id')
            ->flip();

        return $tokens
            ->groupBy(fn ($token) => $token->user_id.'|'.$token->client_id)
            ->map(function (Collection $group) use ($clients, $refreshable) {
                $first = $group->first();

                return [
                    'user_id' => (string) $first->user_id,
                    'client_id' => (string) $first->client_id,
                    'client_name' => $clients->get((string) $first->client_id)?->name ?? __('Unknown client'),
                    'connected_at' => $group->min('created_at'),
                    'last_refreshed_at' => $group->max('created_at'),
                    'active' => $group->contains(fn ($token) => $this->usable($token, $refreshable)),
                ];
            })
            ->sortByDesc('last_refreshed_at')
            ->values();
    }

    /**
     * Revokes every access token for the pair AND each token's refresh
     * tokens — revoking only access tokens would leave a silent way back in
     * via the refresh grant. Returns false when the pair has no tokens at
     * all (the controller 404s); an already-dead pair is a successful no-op.
     */
    public function disconnect(string $userId, string $clientId): bool
    {
        if (! $this->ready()) {
            return false;
        }

        $tokenModel = Passport::tokenModel();

        $ids = $tokenModel::query()
            ->where('user_id', $userId)
            ->where('client_id', $clientId)
            ->pluck('id');

        if ($ids->isEmpty()) {
            return false;
        }

        $refreshModel = Passport::refreshTokenModel();
        $refreshModel::query()->whereIn('access_token_id', $ids)->update(['revoked' => true]);

        $tokenModel::query()->whereIn('id', $ids)->update(['revoked' => true]);

        return true;
    }

    protected function usable(object $token, Collection $refreshable): bool
    {
        if ($token->revoked) {
            return false;
        }

        $expired = $token->expires_at !== null && $token->expires_at->isPast();

        return ! $expired || $refreshable->has((string) $token->getKey());
    }
}
```

- [ ] **Step 4: Run the tests**

Run: `vendor/bin/pest tests/OAuth/ConnectionRepositoryTest.php`
Expected: PASS (10 tests).

- [ ] **Step 5: Add the scoped PHPStan ignore and verify analysis**

Append to the `ignoreErrors` list in `phpstan.neon`:

```yaml
        # laravel/passport is a suggested dependency, deliberately absent from
        # require-dev (OAuthMisconfigTest asserts class_exists honestly). Every
        # Passport symbol use in this file sits behind ready()'s class_exists
        # guard; the Passport CI leg analyses and runs it with the real package.
        -
            message: '#Laravel\\Passport#'
            path: src/OAuth/ConnectionRepository.php
```

Run: `vendor/bin/phpstan analyse --no-progress --memory-limit=1G`
Expected: no errors. **Caveat:** Passport is currently installed locally, so PHPStan may resolve the symbols and report the ignore as "unmatched". If so, add `reportUnmatchedIgnoredErrors: false`? NO — instead verify the ignore after Task 9 removes Passport, and here just confirm zero *errors*. If unmatched-ignore fails the run while Passport is installed, temporarily note it and re-verify in Task 9 (the CI phpstan job runs without Passport, which is the configuration that counts).

- [ ] **Step 6: Format and commit**

```bash
composer format
git add tests/Support/OAuthFixtures.php tests/OAuth/ConnectionRepositoryTest.php src/OAuth/ConnectionRepository.php phpstan.neon
git commit -m "feat: ConnectionRepository derives OAuth connections from Passport's tables

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 4: McpConnectionsController + route (TDD)

**Files:**
- Create: `tests/Feature/McpConnectionsTest.php`
- Create: `src/Http/Controllers/McpConnectionsController.php`
- Modify: `src/CP/McpTokensUtility.php` (routes closure)

- [ ] **Step 1: Write the failing tests**

`tests/Feature/McpConnectionsTest.php`:

```php
<?php

use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tests\Support\OAuthFixtures;
use Laravel\Passport\Passport;

$requiresPassport = fn () => ! class_exists(Passport::class);

beforeEach(function () {
    config(['statamic.editions.pro' => true, 'cache.default' => 'array']);

    if (class_exists(Passport::class)) {
        OAuthFixtures::migratePassport();
        OAuthFixtures::oauthReadyConfig();
    }
});

// ── Route + gate behavior that must hold even WITHOUT Passport (main leg) ──

it('404s disconnecting when oauth is not ready', function () {
    // No Passport / no tables / wrong config: disconnect() reports nothing
    // matched, and the route answers 404 — never a 500.
    config(['auth.guards.api' => ['driver' => 'session', 'provider' => 'users']]);

    $user = Fixtures::makeUser('access cp', 'access mcp_tokens utility');

    $this->actingAs($user)
        ->deleteJson(cp_route('utilities.mcp-tokens.connections.destroy', ['client-x', (string) $user->id()]))
        ->assertNotFound();
});

it('403s disconnecting without the utility permission', function () {
    $user = Fixtures::makeUser('access cp'); // no utility permission

    $this->actingAs($user)
        ->deleteJson(cp_route('utilities.mcp-tokens.connections.destroy', ['client-x', (string) $user->id()]))
        ->assertForbidden();
});

it("403s disconnecting another user's connection before revealing whether it exists", function () {
    $user = Fixtures::makeUser('access cp', 'access mcp_tokens utility');

    $this->actingAs($user)
        ->deleteJson(cp_route('utilities.mcp-tokens.connections.destroy', ['client-x', 'someone-else']))
        ->assertForbidden();
});

// ── Real disconnect behavior (Passport CI leg) ──

it('lets a user disconnect their own connection, revoking access and refresh tokens', function () {
    $user = Fixtures::makeUser('access cp', 'access mcp_tokens utility');

    $client = OAuthFixtures::client();
    $tokenId = OAuthFixtures::accessToken((string) $user->id(), $client);
    $refreshId = OAuthFixtures::refreshToken($tokenId);

    $this->actingAs($user)
        ->delete(cp_route('utilities.mcp-tokens.connections.destroy', [$client, (string) $user->id()]))
        ->assertRedirect(cp_route('utilities.mcp-tokens'));

    $tokenModel = Passport::tokenModel();
    $refreshModel = Passport::refreshTokenModel();

    expect($tokenModel::query()->find($tokenId)->revoked)->toBeTrue()
        ->and($refreshModel::query()->find($refreshId)->revoked)->toBeTrue();
})->skip($requiresPassport, 'requires laravel/passport (Passport CI leg)');

it("lets a super admin disconnect anyone's connection", function () {
    $super = Fixtures::makeSuper();
    $other = Fixtures::makeUser();

    $client = OAuthFixtures::client();
    $tokenId = OAuthFixtures::accessToken((string) $other->id(), $client);

    $this->actingAs($super)
        ->delete(cp_route('utilities.mcp-tokens.connections.destroy', [$client, (string) $other->id()]))
        ->assertRedirect(cp_route('utilities.mcp-tokens'));

    $tokenModel = Passport::tokenModel();

    expect($tokenModel::query()->find($tokenId)->revoked)->toBeTrue();
})->skip($requiresPassport, 'requires laravel/passport (Passport CI leg)');

it("leaves another user's tokens intact when a non-super is 403d", function () {
    $user = Fixtures::makeUser('access cp', 'access mcp_tokens utility');
    $other = Fixtures::makeUser();

    $client = OAuthFixtures::client();
    $tokenId = OAuthFixtures::accessToken((string) $other->id(), $client);

    $this->actingAs($user)
        ->deleteJson(cp_route('utilities.mcp-tokens.connections.destroy', [$client, (string) $other->id()]))
        ->assertForbidden();

    $tokenModel = Passport::tokenModel();

    expect($tokenModel::query()->find($tokenId)->revoked)->toBeFalse();
})->skip($requiresPassport, 'requires laravel/passport (Passport CI leg)');

it('404s disconnecting a pair with no tokens', function () {
    $user = Fixtures::makeUser('access cp', 'access mcp_tokens utility');

    $this->actingAs($user)
        ->deleteJson(cp_route('utilities.mcp-tokens.connections.destroy', ['no-such-client', (string) $user->id()]))
        ->assertNotFound();
})->skip($requiresPassport, 'requires laravel/passport (Passport CI leg)');
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/pest tests/Feature/McpConnectionsTest.php`
Expected: FAIL — route `utilities.mcp-tokens.connections.destroy` not defined.

- [ ] **Step 3: Implement the controller**

`src/Http/Controllers/McpConnectionsController.php`:

```php
<?php

namespace Danielgnh\StatamicMcp\Http\Controllers;

use Danielgnh\StatamicMcp\OAuth\ConnectionRepository;
use Illuminate\Http\RedirectResponse;
use Statamic\Facades\User;
use Statamic\Http\Controllers\CP\CpController;

/**
 * Route-level authorization is Statamic's 'can:access mcp_tokens utility'
 * middleware, same as McpTokensController. destroy() is the only action —
 * connections are created solely by the OAuth consent flow itself.
 */
class McpConnectionsController extends CpController
{
    public function destroy(ConnectionRepository $connections, string $clientId, string $userId): RedirectResponse
    {
        $user = User::current();

        // Ownership comes from the URL and is checked before existence, so a
        // non-super probing other users' pairs learns nothing (403 either way).
        abort_unless($user->isSuper() || $userId === (string) $user->id(), 403);

        abort_unless($connections->disconnect($userId, $clientId), 404);

        return redirect(cp_route('utilities.mcp-tokens'))->with('success', __('Connection disconnected.'));
    }
}
```

- [ ] **Step 4: Register the route**

In `src/CP/McpTokensUtility.php`, add the import and the route (before the `{tokenId}` route, keeping specific-before-parametric order):

```php
use Danielgnh\StatamicMcp\Http\Controllers\McpConnectionsController;
```

```php
                ->routes(function ($router) {
                    $router->post('/', [McpTokensController::class, 'store'])->name('store');
                    $router->delete('connections/{clientId}/{userId}', [McpConnectionsController::class, 'destroy'])->name('connections.destroy');
                    $router->delete('{tokenId}', [McpTokensController::class, 'destroy'])->name('destroy');
                });
```

- [ ] **Step 5: Run the tests**

Run: `vendor/bin/pest tests/Feature/McpConnectionsTest.php`
Expected: PASS (7 tests).

- [ ] **Step 6: Full suite, format, commit**

```bash
vendor/bin/pest
composer format
git add tests/Feature/McpConnectionsTest.php src/Http/Controllers/McpConnectionsController.php src/CP/McpTokensUtility.php
git commit -m "feat: disconnect action for OAuth connections in the CP utility

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 5: Connections panel in the utility view (TDD)

**Files:**
- Modify: `tests/Feature/McpConnectionsTest.php` (append view tests)
- Modify: `src/CP/McpTokensUtility.php` (viewData + presentConnections + retitle)
- Modify: `resources/views/utilities/mcp-tokens.blade.php`

- [ ] **Step 1: Append the failing view tests**

Append to `tests/Feature/McpConnectionsTest.php`:

```php
// ── Panel rendering ──

it('hides the connections panel entirely in token mode', function () {
    config(['statamic.mcp.auth' => 'token']);

    $user = Fixtures::makeUser('access cp', 'access mcp_tokens utility');

    $this->actingAs($user)
        ->get(cp_route('utilities.mcp-tokens'))
        ->assertOk()
        ->assertDontSee('Your connections', false);
});

it('shows a doctor remedy instead of the table when oauth mode is not ready', function () {
    // oauth mode on, prerequisites absent (main leg has no Passport; here we
    // also break the guard config so the test holds in the Passport leg too).
    config([
        'statamic.mcp.auth' => 'oauth',
        'auth.guards.api' => ['driver' => 'session', 'provider' => 'users'],
    ]);

    $user = Fixtures::makeUser('access cp', 'access mcp_tokens utility');

    $this->actingAs($user)
        ->get(cp_route('utilities.mcp-tokens'))
        ->assertOk()
        ->assertSee('mcp:doctor', false);
});

it('shows a permitted user only their own connections', function () {
    $user = Fixtures::makeUser('access cp', 'access mcp_tokens utility');
    $other = Fixtures::makeUser();

    $client = OAuthFixtures::client('Claude');
    OAuthFixtures::accessToken((string) $user->id(), $client);
    OAuthFixtures::accessToken((string) $other->id(), OAuthFixtures::client('ChatGPT'));

    $this->actingAs($user)
        ->get(cp_route('utilities.mcp-tokens'))
        ->assertOk()
        ->assertSee('Claude', false)
        ->assertSee('Disconnect', false)
        ->assertDontSee('ChatGPT', false);
})->skip($requiresPassport, 'requires laravel/passport (Passport CI leg)');

it("shows a super admin everyone's connections with their emails", function () {
    $super = Fixtures::makeSuper();
    $other = Fixtures::makeUser();

    OAuthFixtures::accessToken((string) $other->id(), OAuthFixtures::client('ChatGPT'));

    $this->actingAs($super)
        ->get(cp_route('utilities.mcp-tokens'))
        ->assertOk()
        ->assertSee('ChatGPT', false)
        ->assertSee($other->email(), false);
})->skip($requiresPassport, 'requires laravel/passport (Passport CI leg)');

it('marks dead connections as expired', function () {
    $user = Fixtures::makeUser('access cp', 'access mcp_tokens utility');

    OAuthFixtures::accessToken((string) $user->id(), OAuthFixtures::client(), [
        'expires_at' => now()->subHour(),
    ]);

    $this->actingAs($user)
        ->get(cp_route('utilities.mcp-tokens'))
        ->assertOk()
        ->assertSee('Expired', false);
})->skip($requiresPassport, 'requires laravel/passport (Passport CI leg)');

it('renders DCR-supplied client names inertly for the vue runtime compiler', function () {
    // Client names arrive from dynamic client registration — attacker-
    // controlled input rendered in supers' sessions. Same v-pre contract as
    // token names (see McpTokensUtilityTest).
    $user = Fixtures::makeUser('access cp', 'access mcp_tokens utility');

    OAuthFixtures::accessToken((string) $user->id(), OAuthFixtures::client('{{ 7*7 }}'));

    $this->actingAs($user)
        ->get(cp_route('utilities.mcp-tokens'))
        ->assertOk()
        ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->component('utilities/Show')
            ->where('html', fn ($html) => str_contains($html, '<span v-pre>{{ 7*7 }}</span>')));
})->skip($requiresPassport, 'requires laravel/passport (Passport CI leg)');

it('shows an empty state when oauth is ready but nothing has connected', function () {
    $user = Fixtures::makeUser('access cp', 'access mcp_tokens utility');

    $this->actingAs($user)
        ->get(cp_route('utilities.mcp-tokens'))
        ->assertOk()
        ->assertSee('No connections yet', false);
})->skip($requiresPassport, 'requires laravel/passport (Passport CI leg)');
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/pest tests/Feature/McpConnectionsTest.php`
Expected: new tests FAIL (`connections` variable undefined / missing strings). The `hides the connections panel` test may pass trivially — fine.

- [ ] **Step 3: Extend viewData and retitle**

In `src/CP/McpTokensUtility.php`:

Add import:

```php
use Danielgnh\StatamicMcp\OAuth\ConnectionRepository;
```

Retitle the registration (handle unchanged — existing role grants keep working):

```php
            Utility::register('mcp_tokens')
                ->title(__('MCP Access'))
                ->icon('key')
                ->description(__('Manage MCP access tokens and OAuth connector connections.'))
```

Replace `viewData()` with:

```php
    public static function viewData(Request $request): array
    {
        $user = User::current();
        $isSuper = $user->isSuper();
        $endpoint = url(config('statamic.mcp.route'));
        $oauthMode = config('statamic.mcp.auth') === 'oauth';
        $connections = app(ConnectionRepository::class);

        return [
            'tokens' => static::presentTokens(
                app(TokenRepository::class)->all(),
                $isSuper ? null : (string) $user->id()
            ),
            'connections' => $oauthMode
                ? static::presentConnections($connections->all(), $isSuper ? null : (string) $user->id())
                : collect(),
            'oauthReady' => $oauthMode && $connections->ready(),
            'isSuper' => $isSuper,
            'lacksAccessMcp' => ! $isSuper && ! $user->hasPermission('access mcp'),
            'oauthMode' => $oauthMode,
            'insecureUrl' => ! Str::startsWith($endpoint, 'https://'),
            'endpoint' => $endpoint,
            'plainToken' => session('statamic-mcp.plain_token'),
        ];
    }
```

Add below `presentTokens()`:

```php
    /**
     * Rows arrive shaped and sorted from the repository — this only filters
     * visibility (own-only unless super) and attaches the display email,
     * mirroring presentTokens.
     */
    protected static function presentConnections(Collection $connections, ?string $onlyUserId): Collection
    {
        return $connections
            ->filter(fn ($connection) => $onlyUserId === null || $connection['user_id'] === $onlyUserId)
            ->map(fn ($connection) => array_merge($connection, [
                'email' => User::find($connection['user_id'])?->email() ?? $connection['user_id'],
            ]))
            ->values();
    }
```

- [ ] **Step 4: Add the panel to the blade view**

In `resources/views/utilities/mcp-tokens.blade.php`:

Change the header title:

```blade
    <ui-header title="{{ __('MCP Access') }}" icon="key">
```

Insert this panel immediately BEFORE the `<ui-panel heading="{{ $isSuper ? __('All tokens') : __('Your tokens') }}">` block (in OAuth mode connections are the live surface, tokens the dormant one):

```blade
    @if ($oauthMode)
        <ui-panel heading="{{ $isSuper ? __('All connections') : __('Your connections') }}">
            @unless ($oauthReady)
                <ui-card>
                    <ui-alert variant="warning" text="{{ __('OAuth mode is enabled but not fully configured — run php please mcp:doctor for the exact remedy.') }}"></ui-alert>
                </ui-card>
            @elseif ($connections->isEmpty())
                <ui-card>
                    <ui-empty-state-item icon="link" heading="{{ __('No connections yet') }}" description="{{ __('Connections appear here when a connector (claude.ai, ChatGPT) adds this site and a user completes the consent flow.') }}"></ui-empty-state-item>
                </ui-card>
            @else
                <ui-card inset class="overflow-x-auto">
                    <table class="data-table data-table--contained" data-table>
                        <thead>
                            <tr>
                                <th>{{ __('Client') }}</th>
                                @if ($isSuper)<th>{{ __('User') }}</th>@endif
                                <th>{{ __('Connected') }}</th>
                                <th>{{ __('Last refreshed') }}</th>
                                <th>{{ __('Status') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($connections as $connection)
                                <tr>
                                    <td><span v-pre>{{ $connection['client_name'] }}</span></td>
                                    @if ($isSuper)<td><span v-pre>{{ $connection['email'] }}</span></td>@endif
                                    <td>{{ $connection['connected_at']->toFormattedDateString() }}</td>
                                    <td>{{ $connection['last_refreshed_at']->diffForHumans() }}</td>
                                    <td>
                                        @if ($connection['active'])
                                            <ui-badge color="green" pill>{{ __('Active') }}</ui-badge>
                                        @else
                                            <ui-badge color="red" pill>{{ __('Expired') }}</ui-badge>
                                        @endif
                                    </td>
                                    <td class="text-right">
                                        <form method="POST" action="{{ cp_route('utilities.mcp-tokens.connections.destroy', [$connection['client_id'], $connection['user_id']]) }}" onsubmit="return confirm({{ \Illuminate\Support\Js::from(__('Disconnect this client? It will have to re-authorize before it can reconnect.')) }})">
                                            @csrf
                                            @method('DELETE')
                                            <ui-button type="submit" size="sm" variant="danger" text="{{ __('Disconnect') }}"></ui-button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </ui-card>
            @endif
        </ui-panel>
    @endif
```

- [ ] **Step 5: Run the file, then the full suite**

Run: `vendor/bin/pest tests/Feature/McpConnectionsTest.php`
Expected: PASS.

Run: `vendor/bin/pest`
Expected: PASS. Watch for `McpTokensUtilityTest` breakage from the retitle — no test asserts the "MCP Tokens" title today, but if one fails, update its expectation to "MCP Access".

- [ ] **Step 6: Format and commit**

```bash
composer format
git add src/CP/McpTokensUtility.php resources/views/utilities/mcp-tokens.blade.php tests/Feature/McpConnectionsTest.php
git commit -m "feat: OAuth connections panel in the MCP Access utility

One row per (user, client) pair with connected/last-refreshed timestamps and
an Active/Expired pill; disconnect per row. Utility retitled MCP Access —
handle and permission unchanged.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 6: Passport CI leg

**Files:**
- Modify: `.github/workflows/tests.yml`

- [ ] **Step 1: Add the job and update the earmark comment**

Replace the trailing comment block (lines 82–87) with a real job plus a narrowed earmark:

```yaml
  # Runs the whole suite with laravel/passport installed: the Passport-skipped
  # tests (ConnectionRepository, connections panel/controller) go live, and the
  # one passport-absence test skips instead. composer.json is modified only
  # inside the runner — passport must never land in require-dev (the main legs
  # assert class_exists honestly).
  passport:
    runs-on: ubuntu-latest
    name: Passport leg (OAuth connections)

    steps:
      - name: Checkout code
        uses: actions/checkout@v7

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
          extensions: bcmath, ctype, curl, dom, exif, gd, iconv, intl, libxml, mbstring, openssl, pdo, tokenizer, xml, zip
          coverage: none

      - name: Install dependencies (with Passport)
        run: |
          composer require laravel/passport --no-interaction --no-update
          composer update --prefer-dist --no-interaction

      - name: Run tests
        run: vendor/bin/pest --ci

  # Still earmarked for v1.1: the FULL OAuth integration leg (real Bearer 200s,
  # token-lifecycle 401s, WWW-Authenticate/RFC 9728 metadata, DCR, scope-
  # enforcement decision, Eloquent user normalization, wrong-driver 503,
  # statelessness). The 9-item checklist lives in
  # docs/superpowers/plans/EXECUTION-NOTES.md under "Task 27/28 (Passport CI leg)".
```

- [ ] **Step 2: Validate YAML**

Run: `php -r "echo json_encode((bool) yaml_parse_file('.github/workflows/tests.yml'));" 2>/dev/null || python3 -c "import yaml; yaml.safe_load(open('.github/workflows/tests.yml')); print('ok')"`
Expected: `ok` (or `true`).

- [ ] **Step 3: Commit**

```bash
composer format
git add .github/workflows/tests.yml
git commit -m "ci: Passport leg — run the suite with laravel/passport installed

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 7: README + CHANGELOG

**Files:**
- Modify: `README.md`
- Modify: `CHANGELOG.md`

- [ ] **Step 1: README**

1. Line ~15 says "the only CP surface is the optional MCP Tokens utility" — update to "the only CP surface is the optional MCP Access utility (tokens + OAuth connections)". Grep for other "MCP Tokens" mentions (`grep -n "MCP Tokens" README.md`) and update each to "MCP Access" where it names the utility.
2. At the end of the "Auth mode 2: `oauth`" section (after the 503-remedy paragraph, before "## Configuration"), add:

```markdown
### Seeing and disconnecting connections

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
```

- [ ] **Step 2: CHANGELOG**

Read `CHANGELOG.md` first. Under the unreleased `### Added` section (create it above `### Fixed` if absent), append:

```markdown
- OAuth connections panel in the CP utility (retitled **MCP Access**; handle
  and permission unchanged): one row per connected user + client pair from
  Passport's tables — client name, connected/last-refreshed dates, an
  Active/Expired status that honestly counts live refresh tokens — plus a
  Disconnect action that revokes the pair's access *and* refresh tokens.
  Owner-or-super gated, same as tokens.
- Passport CI leg: the test suite now also runs with `laravel/passport`
  installed, activating the OAuth-connection tests that skip in the main leg.
```

- [ ] **Step 3: Commit**

```bash
composer format
git add README.md CHANGELOG.md
git commit -m "docs: document the OAuth connections panel and disconnect semantics

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 8: Remove Passport locally and verify the main-leg reality

**Files:**
- Restore: `composer.json` (drop laravel/passport)

- [ ] **Step 1: Remove Passport**

```bash
composer remove laravel/passport --no-interaction
git diff composer.json
```

Expected: `git diff composer.json` is EMPTY (back to the committed state, passport only in `suggest`).

- [ ] **Step 2: Full suite without Passport — the main CI leg's reality**

Run: `vendor/bin/pest`
Expected: PASS, with the Passport-leg tests showing as skipped (`requires laravel/passport (Passport CI leg)`) and the passport-absence test running again.

- [ ] **Step 3: Static analysis + style without Passport**

Run: `vendor/bin/phpstan analyse --no-progress --memory-limit=1G`
Expected: no errors (the scoped ignore now matches). If PHPStan instead reports the ignore as unmatched or new Passport symbol errors surface, adjust the ignore entry's `message` regex until clean — but never widen it beyond `path: src/OAuth/ConnectionRepository.php`.

Run: `composer format && git status --short`
Expected: no modifications from Pint.

- [ ] **Step 4: Final commit if anything moved, then hand off**

If Step 3 changed `phpstan.neon`, commit it:

```bash
git add phpstan.neon
git commit -m "chore: pin the Passport ignore to the passport-less analysis run

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

Then use the superpowers:finishing-a-development-branch skill (branch: `feat/oauth-connections-ui`, repo convention is PR to `main`).

---

## Self-review notes

- **Spec coverage:** §2 concept/status → Task 3; §3 disconnect semantics + gates → Tasks 3–4; §4.1 repository → Task 3; §4.2 controller/route → Task 4; §4.3 utility/viewData/retitle → Task 5; §4.4 view/empty state/remedy alert/v-pre → Task 5; §5 CI leg + skips → Tasks 1, 6; §6 out-of-scope → README wording only (Task 7). Covered.
- **Known risks flagged inline:** Passport model `forceFill` vs casts (Task 2 fallback), `Mcp::oauthRoutes()` boot behavior with Passport installed (Task 1 Step 2), PHPStan unmatched-ignore while Passport is locally installed (Task 3 Step 5 / Task 8 Step 3), possible title assertion breakage (Task 5 Step 5).
- **Type consistency:** `ConnectionRepository::ready/all/disconnect` signatures match usage in controller (Task 4) and viewData (Task 5); connection array keys (`user_id`, `client_id`, `client_name`, `connected_at`, `last_refreshed_at`, `active`, plus `email` added by presentConnections) match the blade.
