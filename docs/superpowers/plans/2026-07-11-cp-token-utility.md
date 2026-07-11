# MCP Tokens Utility Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A Statamic CP Utility (Tools → Utilities → MCP Tokens) where a permission-gated CP user issues and revokes their own token-mode MCP tokens, with a show-once secret, connection help, and super-admin audit view.

**Architecture:** A `Utility::register('mcp_tokens')` registration (Blade view rendered inside Statamic's Inertia utility shell) plus two custom utility routes (POST store, DELETE destroy) handled by one CP controller. Statamic auto-registers the `access mcp_tokens utility` permission and auto-applies `can:access mcp_tokens utility` middleware to the GET action and all custom routes. `TokenRepository` gains a `Cache::lock` around its read-modify-write so CLI and CP writers can't interleave.

**Tech Stack:** Statamic v6 addon (`Danielgnh\StatamicMcp`), Laravel 12/13, Pest v4 (`vendor/bin/pest` — never `--parallel`, per `tests/TestCase.php`), Pint (`composer format`).

**Spec:** `docs/superpowers/specs/2026-07-11-cp-token-utility-design.md`

---

## Verified codebase facts (read these, don't re-derive)

- Utilities: `Statamic\Facades\Utility::extend(fn () => Utility::register('mcp_tokens')->…)`. Handle `mcp_tokens` → slug `mcp-tokens`. Statamic registers the GET route named `utilities.mcp-tokens` with middleware `can:access mcp_tokens utility`, and wraps the `->routes()` closure in prefix `utilities/mcp-tokens`, name prefix `utilities.mcp-tokens.`, same `can:` middleware (`vendor/statamic/cms/src/CP/Utilities/UtilityRepository.php:74-95`). The permission `access mcp_tokens utility` is auto-registered by `CorePermissions` — do NOT register it yourself.
- A `->view()` utility is rendered by `UtilitiesController::show` as raw HTML inside the Inertia `utilities/Show` page. Plain Blade + HTML forms with full-page POST/redirect work. CP Tailwind CSS is available.
- Addon views auto-load from `resources/views/` under namespace `statamic-mcp` (package name part of `danielgnh/statamic-mcp`). So `resources/views/utilities/mcp-tokens.blade.php` = `statamic-mcp::utilities.mcp-tokens`.
- `cp_route('utilities.mcp-tokens')` / `cp_route('utilities.mcp-tokens.store')` / `cp_route('utilities.mcp-tokens.destroy', $tokenId)` resolve the three routes.
- CP icon `key` exists (`vendor/statamic/cms/resources/svg/icons/key.svg`).
- `TokenRepository` (`src/Tokens/TokenRepository.php`): `issue(User, ?string $name, ?int $expiresDays): PlainToken`, `all(): array<tokenId, record>`, `find(tokenId): ?array`, `revoke(tokenId): bool`. Records: `['user' => id, 'name' => ?, 'hash' => sha256, 'created_at' => iso, 'expires_at' => ?iso]`. Its docblock demands real locking before any web writer exists — Task 2 adds it.
- `Illuminate\Cache\ArrayStore` implements `LockProvider`, so `Cache::lock()` works in tests with `config(['cache.default' => 'array'])`.
- Test fixtures: `Fixtures::makeUser(...$permissions)` (always includes `access mcp`), `Fixtures::makeSuper()` (`tests/Support/Fixtures.php`). Task 1 adds a variant without `access mcp`.
- The addon kill switch (`statamic.mcp.enabled`) is read once in `bootAddon()`; tests flip it via the `DisablesMcp` trait (file-level `uses()`), never inside a test body.
- User's global instruction: run `composer format` after changes (before every commit below).

## File structure

| File | Responsibility |
|---|---|
| Create `src/CP/McpTokensUtility.php` | Utility registration + `viewData()` (list, banners, flash) |
| Create `src/Http/Controllers/McpTokensController.php` | POST store (issue), DELETE destroy (revoke) |
| Create `resources/views/utilities/mcp-tokens.blade.php` | The page: banners, show-once, issue form, token table, help panel |
| Modify `src/Tokens/TokenRepository.php` | `Cache::lock` around issue/revoke; docblock update |
| Modify `src/ServiceProvider.php` | Call `McpTokensUtility::register()` behind the kill switch |
| Modify `tests/Support/Fixtures.php` | Add `makeBareUser()` |
| Test `tests/Tokens/TokenRepositoryLockTest.php` | Lock behavior |
| Test `tests/Feature/McpTokensUtilityTest.php` | CP HTTP: gating, visibility, issue, revoke, banners |
| Modify `README.md`, `CHANGELOG.md` | Docs |

---

### Task 1: `Fixtures::makeBareUser()` (test support, no TDD)

**Files:**
- Modify: `tests/Support/Fixtures.php` (after `makeUser`, before `makeSuper`)

- [ ] **Step 1: Add the fixture**

```php
    /**
     * A user WITHOUT 'access mcp' — only the given permissions, via a
     * dedicated throwaway role. For testing warnings/denials that makeUser's
     * always-included 'access mcp' would mask.
     */
    public static function makeBareUser(string ...$permissions): UserContract
    {
        $handle = 'role_'.Str::lower(Str::random(8));

        $role = Role::make($handle)->title('Bare Test Role');

        foreach ($permissions as $permission) {
            $role->addPermission($permission);
        }

        $role->save();

        return tap(
            User::make()->email(Str::lower(Str::random(8)).'@site.test')->assignRole($handle)
        )->save();
    }
```

- [ ] **Step 2: Format and verify the suite still passes**

Run: `composer format && vendor/bin/pest tests/Console`
Expected: Pint clean, existing tests PASS.

- [ ] **Step 3: Commit**

```bash
git add tests/Support/Fixtures.php
git commit -m "test: add makeBareUser fixture (user without 'access mcp')"
```

---

### Task 2: TokenRepository locking

**Files:**
- Modify: `src/Tokens/TokenRepository.php`
- Test: `tests/Tokens/TokenRepositoryLockTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Tokens/TokenRepositoryLockTest.php`:

```php
<?php

use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tokens\TokenRepository;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    config(['cache.default' => 'array']);
    File::delete(storage_path('statamic/mcp/tokens.yaml'));
});

it('refuses to issue while another writer holds the token-store lock', function () {
    $user = Fixtures::makeUser();

    expect(Cache::lock('statamic-mcp-token-store', 10)->get())->toBeTrue();

    $repository = new TokenRepository(lockWaitSeconds: 0);

    expect(fn () => $repository->issue($user))->toThrow(LockTimeoutException::class)
        ->and($repository->all())->toBeEmpty(); // fail closed: nothing written
});

it('refuses to revoke while another writer holds the token-store lock', function () {
    $user = Fixtures::makeUser();

    $plain = app(TokenRepository::class)->issue($user);

    expect(Cache::lock('statamic-mcp-token-store', 10)->get())->toBeTrue();

    $repository = new TokenRepository(lockWaitSeconds: 0);

    expect(fn () => $repository->revoke($plain->tokenId))->toThrow(LockTimeoutException::class)
        ->and($repository->all())->toHaveCount(1); // fail closed: token survives
});

it('issues and revokes normally when the lock is free', function () {
    $user = Fixtures::makeUser();

    $repository = app(TokenRepository::class);

    $plain = $repository->issue($user, 'lock test');

    expect($repository->all())->toHaveCount(1)
        ->and($repository->revoke($plain->tokenId))->toBeTrue()
        ->and($repository->all())->toBeEmpty();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/pest tests/Tokens/TokenRepositoryLockTest.php`
Expected: FAIL — first two tests error with "Unknown named parameter $lockWaitSeconds" (constructor doesn't exist yet).

- [ ] **Step 3: Add the lock to the repository**

In `src/Tokens/TokenRepository.php`:

Replace the class docblock (lines 11-19) with:

```php
/**
 * Every mutation serializes the FULL read-modify-write behind Cache::lock —
 * CLI commands and the CP utility can write concurrently, and interleaved
 * revoke() + issue() must never write back pre-revoke state and resurrect a
 * token. Lock acquisition fails closed (LockTimeoutException, nothing
 * written); the torn-write failure mode also fails closed: corrupt YAML
 * breaks authentication, it never opens it up.
 */
```

Add imports:

```php
use Illuminate\Support\Facades\Cache;
```

Add a constructor and helper, and wrap the two mutators:

```php
    public function __construct(protected int $lockWaitSeconds = 5) {}
```

Wrap the existing `issue()` body (everything from `$tokenId = …` through `return new PlainToken(…);`) so the method becomes:

```php
    public function issue(User $user, ?string $name = null, ?int $expiresDays = null): PlainToken
    {
        return $this->withLock(function () use ($user, $name, $expiresDays) {
            $tokenId = Str::lower(Str::random(12));
            $secret = Str::random(40);

            $expiresAt = $expiresDays ? Carbon::now()->addDays($expiresDays) : null;

            $tokens = $this->read();

            while (isset($tokens[$tokenId])) {
                $tokenId = Str::lower(Str::random(12));
            }

            $tokens[$tokenId] = [
                'user' => (string) $user->id(),
                'name' => $name,
                'hash' => hash('sha256', $secret),
                'created_at' => Carbon::now()->toIso8601String(),
                'expires_at' => $expiresAt?->toIso8601String(),
            ];

            $this->write($tokens);

            return new PlainToken(
                tokenId: $tokenId,
                token: "mcp_{$tokenId}_{$secret}",
                userId: (string) $user->id(),
                name: $name,
                expiresAt: $expiresAt,
            );
        });
    }
```

Wrap `revoke()` the same way:

```php
    public function revoke(string $tokenId): bool
    {
        return $this->withLock(function () use ($tokenId) {
            $tokens = $this->read();

            if (! array_key_exists($tokenId, $tokens)) {
                return false;
            }

            unset($tokens[$tokenId]);

            $this->write($tokens);

            return true;
        });
    }
```

Add the helper above `path()`:

```php
    /**
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     *
     * @throws \Illuminate\Contracts\Cache\LockTimeoutException fail closed — no partial write
     */
    protected function withLock(callable $operation): mixed
    {
        return Cache::lock('statamic-mcp-token-store', 10)->block($this->lockWaitSeconds, $operation);
    }
```

- [ ] **Step 4: Run the new tests and the existing token suites**

Run: `vendor/bin/pest tests/Tokens tests/Console tests/Middleware/AuthenticateMcpTokenTest.php`
Expected: ALL PASS (constructor default keeps `app(TokenRepository::class)` working).

- [ ] **Step 5: Format and commit**

```bash
composer format
git add src/Tokens/TokenRepository.php tests/Tokens/TokenRepositoryLockTest.php
git commit -m "feat: serialize token-store writes behind Cache::lock"
```

---

### Task 3: Utility registration, view, and the GET page

**Files:**
- Create: `src/CP/McpTokensUtility.php`
- Create: `resources/views/utilities/mcp-tokens.blade.php`
- Modify: `src/ServiceProvider.php`
- Test: `tests/Feature/McpTokensUtilityTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/McpTokensUtilityTest.php`. Notes baked in: roles/permissions need Statamic Pro; assertions use `assertSee(..., false)` because the Blade HTML is JSON-encoded into the Inertia page payload; token names/emails are alphanumeric so encoding doesn't mangle them.

```php
<?php

use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tokens\TokenRepository;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    config(['statamic.editions.pro' => true, 'cache.default' => 'array']);
    File::delete(storage_path('statamic/mcp/tokens.yaml'));
});

it('403s users without the utility permission', function () {
    $user = Fixtures::makeUser(); // has 'access mcp' but NOT the utility permission

    $this->actingAs($user)
        ->get(cp_route('utilities.mcp-tokens'))
        ->assertForbidden();
});

it('shows a permitted user only their own tokens', function () {
    $user = Fixtures::makeUser('access mcp_tokens utility');
    $other = Fixtures::makeUser();

    $repository = app(TokenRepository::class);
    $repository->issue($user, 'mine-alpha');
    $repository->issue($other, 'theirs-beta');

    $this->actingAs($user)
        ->get(cp_route('utilities.mcp-tokens'))
        ->assertOk()
        ->assertSee('mine-alpha', false)
        ->assertDontSee('theirs-beta', false);
});

it('shows a super admin all tokens with their owners', function () {
    $super = Fixtures::makeSuper();
    $other = Fixtures::makeUser();

    app(TokenRepository::class)->issue($other, 'theirs-beta');

    $this->actingAs($super)
        ->get(cp_route('utilities.mcp-tokens'))
        ->assertOk()
        ->assertSee('theirs-beta', false)
        ->assertSee($other->email(), false);
});

it('marks expired tokens as expired', function () {
    $user = Fixtures::makeUser('access mcp_tokens utility');

    $this->travelTo(now()->subDays(60), function () use ($user) {
        app(TokenRepository::class)->issue($user, 'old-token', 30);
    });

    $this->actingAs($user)
        ->get(cp_route('utilities.mcp-tokens'))
        ->assertOk()
        ->assertSee('old-token', false)
        ->assertSee('Expired', false);
});

it('warns when the user lacks the access mcp permission', function () {
    $bare = Fixtures::makeBareUser('access mcp_tokens utility');

    $this->actingAs($bare)
        ->get(cp_route('utilities.mcp-tokens'))
        ->assertOk()
        ->assertSee('does not have the', false); // access-mcp warning banner
});

it('does not warn when the user has the access mcp permission', function () {
    $user = Fixtures::makeUser('access mcp_tokens utility');

    $this->actingAs($user)
        ->get(cp_route('utilities.mcp-tokens'))
        ->assertOk()
        ->assertDontSee('does not have the', false);
});

it('shows the oauth-mode notice only when auth mode is oauth', function () {
    config(['statamic.mcp.auth' => 'oauth']);

    $user = Fixtures::makeUser('access mcp_tokens utility');

    $this->actingAs($user)
        ->get(cp_route('utilities.mcp-tokens'))
        ->assertOk()
        ->assertSee('OAuth mode', false);

    config(['statamic.mcp.auth' => 'token']);

    $this->actingAs($user)
        ->get(cp_route('utilities.mcp-tokens'))
        ->assertDontSee('OAuth mode', false);
});

it('warns about plain http and shows the endpoint in the help panel', function () {
    $user = Fixtures::makeUser('access mcp_tokens utility');

    // Test requests hit http://localhost, so the insecure warning must show
    // and the endpoint must be printed for the help panel.
    $this->actingAs($user)
        ->get(cp_route('utilities.mcp-tokens'))
        ->assertOk()
        ->assertSee('unencrypted', false)
        ->assertSee('http://localhost/mcp/statamic', false);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/McpTokensUtilityTest.php`
Expected: FAIL — `cp_route('utilities.mcp-tokens')` throws `RouteNotFoundException` (utility not registered yet).

- [ ] **Step 3: Create the utility class**

Create `src/CP/McpTokensUtility.php`:

```php
<?php

namespace Danielgnh\StatamicMcp\CP;

use Danielgnh\StatamicMcp\Http\Controllers\McpTokensController;
use Danielgnh\StatamicMcp\Tokens\TokenRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Statamic\Facades\User;
use Statamic\Facades\Utility;

/**
 * Registers the "MCP Tokens" CP utility. Statamic supplies the permission
 * ('access mcp_tokens utility', auto-registered) and applies it as `can:`
 * middleware to the GET action and every custom route — no bespoke gate here.
 */
class McpTokensUtility
{
    public static function register(): void
    {
        Utility::extend(function () {
            Utility::register('mcp_tokens')
                ->title(__('MCP Tokens'))
                ->icon('key')
                ->description(__('Issue and revoke your own MCP access tokens.'))
                ->view('statamic-mcp::utilities.mcp-tokens', fn (Request $request) => static::viewData($request))
                ->routes(function ($router) {
                    $router->post('/', [McpTokensController::class, 'store'])->name('store');
                    $router->delete('{tokenId}', [McpTokensController::class, 'destroy'])->name('destroy');
                });
        });
    }

    public static function viewData(Request $request): array
    {
        $user = User::current();
        $isSuper = $user->isSuper();
        $endpoint = url(config('statamic.mcp.route'));

        $tokens = collect(app(TokenRepository::class)->all())
            ->when(! $isSuper, fn ($tokens) => $tokens->filter(
                fn ($record) => $record['user'] === (string) $user->id()
            ))
            ->map(function ($record, $tokenId) {
                $expiresAt = $record['expires_at'] ? Carbon::parse($record['expires_at']) : null;

                return [
                    'id' => $tokenId,
                    'name' => $record['name'],
                    'email' => User::find($record['user'])?->email() ?? $record['user'],
                    'created_at' => Carbon::parse($record['created_at']),
                    'expires_at' => $expiresAt,
                    'expired' => $expiresAt?->isPast() ?? false,
                ];
            })
            ->sortByDesc('created_at')
            ->values();

        return [
            'tokens' => $tokens,
            'isSuper' => $isSuper,
            'lacksAccessMcp' => ! $isSuper && ! $user->hasPermission('access mcp'),
            'oauthMode' => config('statamic.mcp.auth') === 'oauth',
            'insecureUrl' => ! Str::startsWith($endpoint, 'https://'),
            'endpoint' => $endpoint,
            'plainToken' => session('statamic-mcp.plain_token'),
        ];
    }
}
```

- [ ] **Step 4: Create the Blade view**

Create `resources/views/utilities/mcp-tokens.blade.php`. (The store/destroy forms reference routes created in this task's utility registration; the controller they point at arrives in Task 4 — that's fine, the GET page only builds URLs.)

```blade
<div class="max-w-3xl space-y-6">

    @if (session('error'))
        <div class="rounded border border-red-500 bg-red-100 p-3 text-red-800">{{ session('error') }}</div>
    @endif

    @if (session('success'))
        <div class="rounded border border-green-500 bg-green-100 p-3 text-green-800">{{ session('success') }}</div>
    @endif

    @if ($lacksAccessMcp)
        <div class="rounded border border-yellow-500 bg-yellow-100 p-3 text-yellow-800">
            {{ __('Your account does not have the "Access MCP" permission — tokens you issue will authenticate but every request will be denied until an administrator grants it to one of your roles.') }}
        </div>
    @endif

    @if ($oauthMode)
        <div class="rounded border border-blue-500 bg-blue-100 p-3 text-blue-800">
            {{ __('This site is in OAuth mode — bearer tokens are not accepted until the auth mode is switched back to token. You can still manage tokens here.') }}
        </div>
    @endif

    @if ($insecureUrl)
        <div class="rounded border border-yellow-500 bg-yellow-100 p-3 text-yellow-800">
            {{ __('The MCP endpoint is not HTTPS — bearer tokens travel unencrypted. Set APP_URL to your real https:// site URL.') }}
        </div>
    @endif

    @if ($plainToken)
        <div class="rounded border border-green-600 bg-green-50 p-4 space-y-3">
            <h2 class="font-bold">{{ __('Token created — this is the ONLY time it will be displayed. Copy it now.') }}</h2>
            <input type="text" readonly value="{{ $plainToken['token'] }}" class="input-text w-full font-mono" onclick="this.select()">
            <p class="text-sm">{{ __('Expires') }}: {{ $plainToken['expiresAt'] ?? __('never') }}</p>
            <h3 class="font-bold text-sm">{{ __('Claude Code') }}</h3>
            <pre class="overflow-x-auto rounded bg-gray-900 p-2 text-xs text-white">claude mcp add --transport http statamic {{ $endpoint }} --header "Authorization: Bearer {{ $plainToken['token'] }}"</pre>
            <h3 class="font-bold text-sm">{{ __('Cursor (.cursor/mcp.json)') }}</h3>
            <pre class="overflow-x-auto rounded bg-gray-900 p-2 text-xs text-white">{{ json_encode(['mcpServers' => ['statamic' => ['url' => $endpoint, 'headers' => ['Authorization' => 'Bearer '.$plainToken['token']]]]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
        </div>
    @endif

    <div class="card p-4 space-y-3">
        <h2 class="font-bold">{{ __('Issue a new token') }}</h2>
        <form method="POST" action="{{ cp_route('utilities.mcp-tokens.store') }}" class="flex items-end gap-2">
            @csrf
            <div class="flex-1">
                <label class="block text-sm font-medium" for="mcp-token-name">{{ __('Name (optional)') }}</label>
                <input type="text" name="name" id="mcp-token-name" maxlength="100" placeholder="{{ __('e.g. claude-code laptop') }}" class="input-text w-full" value="{{ old('name') }}">
                @error('name') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium" for="mcp-token-expiry">{{ __('Expires') }}</label>
                <select name="expiry" id="mcp-token-expiry" class="select-input">
                    <option value="never">{{ __('Never') }}</option>
                    <option value="30">{{ __('30 days') }}</option>
                    <option value="90">{{ __('90 days') }}</option>
                    <option value="365">{{ __('365 days') }}</option>
                </select>
                @error('expiry') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <button type="submit" class="btn-primary">{{ __('Create token') }}</button>
        </form>
    </div>

    <div class="card p-4">
        <h2 class="font-bold mb-2">{{ $isSuper ? __('All tokens') : __('Your tokens') }}</h2>
        @if ($tokens->isEmpty())
            <p class="text-sm text-gray-600">{{ __('No tokens yet.') }}</p>
        @else
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left">
                        <th class="pb-1">{{ __('Name') }}</th>
                        @if ($isSuper)<th class="pb-1">{{ __('User') }}</th>@endif
                        <th class="pb-1">{{ __('Created') }}</th>
                        <th class="pb-1">{{ __('Expires') }}</th>
                        <th class="pb-1">{{ __('Status') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($tokens as $token)
                        <tr>
                            <td class="py-1">{{ $token['name'] ?? __('(unnamed)') }} <span class="text-gray-500 font-mono text-xs">{{ $token['id'] }}</span></td>
                            @if ($isSuper)<td class="py-1">{{ $token['email'] }}</td>@endif
                            <td class="py-1">{{ $token['created_at']->toFormattedDateString() }}</td>
                            <td class="py-1">{{ $token['expires_at']?->toFormattedDateString() ?? __('never') }}</td>
                            <td class="py-1">{{ $token['expired'] ? __('Expired') : __('Active') }}</td>
                            <td class="py-1 text-right">
                                <form method="POST" action="{{ cp_route('utilities.mcp-tokens.destroy', $token['id']) }}" onsubmit="return confirm('{{ __('Revoke this token?') }}')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-red-600">{{ __('Revoke') }}</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="card p-4 space-y-2">
        <h2 class="font-bold">{{ __('Connecting a client') }}</h2>
        <p class="text-sm">{{ __('MCP endpoint') }}: <code>{{ $endpoint }}</code></p>
        <p class="text-sm">{{ __('Works with Claude Code, Cursor, and any MCP client that can send a static Authorization header. Individual claude.ai and Claude Desktop connectors need OAuth mode instead — see the README client-compatibility matrix.') }}</p>
        <pre class="overflow-x-auto rounded bg-gray-900 p-2 text-xs text-white">claude mcp add --transport http statamic {{ $endpoint }} --header "Authorization: Bearer &lt;token&gt;"</pre>
    </div>

</div>
```

- [ ] **Step 5: Register the utility in the ServiceProvider**

In `src/ServiceProvider.php`, add the import:

```php
use Danielgnh\StatamicMcp\CP\McpTokensUtility;
```

Then in `bootAddon()`, directly after the kill-switch `return` (so a disabled addon shows no utility) and before the `try` block:

```php
        if (! config('statamic.mcp.enabled')) {
            return;
        }

        McpTokensUtility::register();
```

- [ ] **Step 6: Create a stub controller so the routes closure resolves**

The utility's `routes()` closure references `McpTokensController`. Create `src/Http/Controllers/McpTokensController.php` as a minimal stub (Task 4 fills it in):

```php
<?php

namespace Danielgnh\StatamicMcp\Http\Controllers;

use Statamic\Http\Controllers\CP\CpController;

class McpTokensController extends CpController
{
    //
}
```

- [ ] **Step 7: Run the tests**

Run: `vendor/bin/pest tests/Feature/McpTokensUtilityTest.php`
Expected: ALL PASS.

If `assertForbidden` gets a 302 instead: the CP auth redirect fired before the `can:` check — ensure the test user was persisted (`Fixtures` does `->save()`) and `actingAs` is called on the request chain as shown.

- [ ] **Step 8: Run the full suite**

Run: `vendor/bin/pest`
Expected: ALL PASS (utility registration must not break MCP route tests or `DisablesMcp` suites).

- [ ] **Step 9: Format and commit**

```bash
composer format
git add src/CP/McpTokensUtility.php src/Http/Controllers/McpTokensController.php resources/views/utilities/mcp-tokens.blade.php src/ServiceProvider.php tests/Feature/McpTokensUtilityTest.php
git commit -m "feat: MCP Tokens CP utility — permission-gated token list"
```

---

### Task 4: Issuing tokens from the CP (store)

**Files:**
- Modify: `src/Http/Controllers/McpTokensController.php`
- Test: `tests/Feature/McpTokensUtilityTest.php` (append)

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/McpTokensUtilityTest.php`:

```php
it('issues a token for the current user and shows the secret exactly once', function () {
    $user = Fixtures::makeUser('access mcp_tokens utility');

    $response = $this->actingAs($user)->post(cp_route('utilities.mcp-tokens.store'), [
        'name' => 'cp-issued',
        'expiry' => '30',
    ]);

    $response->assertRedirect(cp_route('utilities.mcp-tokens'));

    $records = app(TokenRepository::class)->all();

    expect($records)->toHaveCount(1);

    $record = array_values($records)[0];

    expect($record['user'])->toBe((string) $user->id())
        ->and($record['name'])->toBe('cp-issued')
        ->and($record['expires_at'])->not->toBeNull();

    // First GET after the redirect: the flashed secret is visible.
    $this->get(cp_route('utilities.mcp-tokens'))
        ->assertOk()
        ->assertSee('mcp_'.array_keys($records)[0].'_', false)
        ->assertSee('ONLY time', false);

    // Second GET: the flash is gone — the secret never appears again.
    $this->get(cp_route('utilities.mcp-tokens'))
        ->assertOk()
        ->assertDontSee('mcp_'.array_keys($records)[0].'_', false);
});

it('rejects an expiry outside the presets', function () {
    $user = Fixtures::makeUser('access mcp_tokens utility');

    $this->actingAs($user)
        ->from(cp_route('utilities.mcp-tokens'))
        ->post(cp_route('utilities.mcp-tokens.store'), ['expiry' => '7'])
        ->assertSessionHasErrors('expiry');

    expect(app(TokenRepository::class)->all())->toBeEmpty();
});

it('rejects a name over 100 characters', function () {
    $user = Fixtures::makeUser('access mcp_tokens utility');

    $this->actingAs($user)
        ->from(cp_route('utilities.mcp-tokens'))
        ->post(cp_route('utilities.mcp-tokens.store'), ['name' => str_repeat('x', 101), 'expiry' => 'never'])
        ->assertSessionHasErrors('name');

    expect(app(TokenRepository::class)->all())->toBeEmpty();
});

it('403s issuance without the utility permission', function () {
    $user = Fixtures::makeUser(); // no utility permission

    $this->actingAs($user)
        ->post(cp_route('utilities.mcp-tokens.store'), ['expiry' => 'never'])
        ->assertForbidden();

    expect(app(TokenRepository::class)->all())->toBeEmpty();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/McpTokensUtilityTest.php`
Expected: the four new tests FAIL (POST hits the stub controller — Laravel throws "Method store does not exist" as a 500); the Task 3 tests still PASS.

- [ ] **Step 3: Implement store**

Replace `src/Http/Controllers/McpTokensController.php` with:

```php
<?php

namespace Danielgnh\StatamicMcp\Http\Controllers;

use Danielgnh\StatamicMcp\Tokens\TokenRepository;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Statamic\Facades\User;
use Statamic\Http\Controllers\CP\CpController;

/**
 * Route-level authorization is Statamic's 'can:access mcp_tokens utility'
 * middleware (applied by the utility registration). store() always issues for
 * the CURRENT user — issuing for someone else stays a console operation.
 */
class McpTokensController extends CpController
{
    public function store(Request $request, TokenRepository $tokens): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:100'],
            'expiry' => ['required', 'in:never,30,90,365'],
        ]);

        $days = $validated['expiry'] === 'never' ? null : (int) $validated['expiry'];

        try {
            $plain = $tokens->issue(User::current(), $validated['name'] ?? null, $days);
        } catch (LockTimeoutException) {
            return redirect(cp_route('utilities.mcp-tokens'))
                ->with('error', __('The token store is busy — please try again.'));
        }

        return redirect(cp_route('utilities.mcp-tokens'))->with('statamic-mcp.plain_token', [
            'token' => $plain->token,
            'tokenId' => $plain->tokenId,
            'name' => $plain->name,
            'expiresAt' => $plain->expiresAt?->toFormattedDateString(),
        ]);
    }
}
```

- [ ] **Step 4: Run the tests**

Run: `vendor/bin/pest tests/Feature/McpTokensUtilityTest.php`
Expected: ALL PASS.

- [ ] **Step 5: Format and commit**

```bash
composer format
git add src/Http/Controllers/McpTokensController.php tests/Feature/McpTokensUtilityTest.php
git commit -m "feat: issue MCP tokens from the CP with show-once secret"
```

---

### Task 5: Revoking tokens from the CP (destroy)

**Files:**
- Modify: `src/Http/Controllers/McpTokensController.php`
- Test: `tests/Feature/McpTokensUtilityTest.php` (append)

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/McpTokensUtilityTest.php`:

```php
it('lets a user revoke their own token', function () {
    $user = Fixtures::makeUser('access mcp_tokens utility');

    $plain = app(TokenRepository::class)->issue($user, 'to-revoke');

    $this->actingAs($user)
        ->delete(cp_route('utilities.mcp-tokens.destroy', $plain->tokenId))
        ->assertRedirect(cp_route('utilities.mcp-tokens'));

    expect(app(TokenRepository::class)->all())->toBeEmpty();
});

it("403s revoking another user's token and leaves it intact", function () {
    $user = Fixtures::makeUser('access mcp_tokens utility');
    $other = Fixtures::makeUser();

    $plain = app(TokenRepository::class)->issue($other, 'not-yours');

    $this->actingAs($user)
        ->delete(cp_route('utilities.mcp-tokens.destroy', $plain->tokenId))
        ->assertForbidden();

    expect(app(TokenRepository::class)->all())->toHaveCount(1);
});

it("lets a super admin revoke anyone's token", function () {
    $super = Fixtures::makeSuper();
    $other = Fixtures::makeUser();

    $plain = app(TokenRepository::class)->issue($other, 'audit-revoke');

    $this->actingAs($super)
        ->delete(cp_route('utilities.mcp-tokens.destroy', $plain->tokenId))
        ->assertRedirect(cp_route('utilities.mcp-tokens'));

    expect(app(TokenRepository::class)->all())->toBeEmpty();
});

it('404s revoking an unknown token id', function () {
    $user = Fixtures::makeUser('access mcp_tokens utility');

    $this->actingAs($user)
        ->delete(cp_route('utilities.mcp-tokens.destroy', 'nosuchtoken'))
        ->assertNotFound();
});

it('403s revocation without the utility permission, even for own tokens', function () {
    $user = Fixtures::makeUser(); // no utility permission

    $plain = app(TokenRepository::class)->issue($user, 'own-but-ungated');

    $this->actingAs($user)
        ->delete(cp_route('utilities.mcp-tokens.destroy', $plain->tokenId))
        ->assertForbidden();

    expect(app(TokenRepository::class)->all())->toHaveCount(1);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/McpTokensUtilityTest.php`
Expected: the four new tests FAIL (destroy method missing → 500); everything else PASSES.

- [ ] **Step 3: Implement destroy**

Add to `McpTokensController` (after `store`):

```php
    public function destroy(Request $request, TokenRepository $tokens, string $tokenId): RedirectResponse
    {
        $record = $tokens->find($tokenId);

        abort_if($record === null, 404);

        $user = User::current();

        // Owner-or-super, enforced server-side — the view hiding other users'
        // rows is cosmetic, this is the actual gate.
        abort_unless($user->isSuper() || $record['user'] === (string) $user->id(), 403);

        try {
            $tokens->revoke($tokenId);
        } catch (LockTimeoutException) {
            return redirect(cp_route('utilities.mcp-tokens'))
                ->with('error', __('The token store is busy — please try again.'));
        }

        return redirect(cp_route('utilities.mcp-tokens'))->with('success', __('Token revoked.'));
    }
```

- [ ] **Step 4: Run the tests**

Run: `vendor/bin/pest tests/Feature/McpTokensUtilityTest.php`
Expected: ALL PASS.

- [ ] **Step 5: Format and commit**

```bash
composer format
git add src/Http/Controllers/McpTokensController.php tests/Feature/McpTokensUtilityTest.php
git commit -m "feat: revoke MCP tokens from the CP (owner-or-super)"
```

---

### Task 6: Docs and final verification

**Files:**
- Modify: `README.md` (the "Auth mode 1: token" section, around line 107)
- Modify: `CHANGELOG.md`

- [ ] **Step 1: Update the README token-mode section**

In the "Auth mode 1: `token` (default)" section, after the console command block, add:

```markdown
### Self-service from the Control Panel

Users can issue and revoke their own tokens at **Tools → Utilities → MCP Tokens**
— no console access needed. Grant the **Access MCP Tokens utility** permission to
a role to enable it. Super admins additionally see (and can revoke) everyone's
tokens. Issuing a token *for another user* remains a console operation
(`php please mcp:token their@email.com`).
```

The README's intro (around line 15) currently says "…no database tables, no CP UI." — that claim is now false. Reword that sentence to acknowledge the tokens utility, e.g. "…no database tables; the only CP surface is the optional MCP Tokens utility."

- [ ] **Step 2: Add a CHANGELOG entry**

Follow the existing format in `CHANGELOG.md` (check its current heading style), adding under an Unreleased/next-version heading:

```markdown
- Added: MCP Tokens utility — issue and revoke your own tokens from the Control Panel (Tools → Utilities), gated by the "Access MCP Tokens utility" permission. Super admins see all tokens.
- Changed: token-store writes are serialized behind an atomic lock, making concurrent CLI + CP issuance/revocation safe.
```

- [ ] **Step 3: Full verification**

Run: `composer format && vendor/bin/pest && vendor/bin/phpstan analyse`
Expected: Pint clean, full suite PASS, PHPStan no errors.

- [ ] **Step 4: Commit**

```bash
git add README.md CHANGELOG.md
git commit -m "docs: document CP self-service token management"
```
