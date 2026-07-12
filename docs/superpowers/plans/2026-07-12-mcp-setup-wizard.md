# MCP Setup Wizard (`mcp:setup`) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** One interactive command — `php please mcp:setup` — that onboards both auth modes: the token path issues a first token in seconds; the OAuth path checks, confirms, and applies every Passport prerequisite that today is a manual README walkthrough.

**Architecture:** The doctor's OAuth predicates move into a shared `Support/OAuthPrerequisites` class (single source of truth for `Doctor`, `AuthenticateOAuth`, and the new `Setup` command). Four stateless anchor-based file editors live in `src/Setup/`, each returning an `EditResult` enum (`Applied`/`Skipped`/`Bailed`) and exposing a manual `snippet()` fallback. The `Setup` command orchestrates: Laravel Prompts for interaction, the `Process` facade for every external command (composer AND artisan subprocesses — Passport installed mid-run isn't loaded in the current PHP process), and a final `mcp:doctor` subprocess as proof. The `.env` flip to `oauth` happens last so an aborted run never leaves a broken mode live.

**Tech Stack:** PHP 8.3, Statamic 6 addon, laravel/mcp, Laravel Prompts (`select`/`confirm`/`text`), `Illuminate\Support\Facades\Process`, Pest 4, Pint.

**Spec:** `docs/superpowers/specs/2026-07-12-mcp-setup-wizard-design.md`

**Conventions in this repo:**
- Commands use the `Statamic\Console\RunsInPlease` trait and the `statamic:mcp:*` signature prefix (invoked as `php please mcp:*`).
- Commands are registered in `ServiceProvider::$commands`.
- Tests are Pest, under `tests/`, using `$this->artisan(...)` with `expectsChoice`/`expectsConfirmation`/`expectsQuestion`, and `Danielgnh\StatamicMcp\Tests\Support\Fixtures::makeUser()` for users.
- After every code change run `composer format` (Pint) before committing.
- Passport is NOT in require-dev — never reference Passport classes without a `class_exists`/`interface_exists` guard, and never top-level `use`-and-call them unguarded outside of guarded code paths (`Doctor` already imports `Laravel\Passport\Passport` but only touches it behind guards; follow that pattern).

---

### Task 0: Branch

- [ ] **Step 1: Create the feature branch**

```bash
git checkout -b feat/mcp-setup-wizard
```

---

### Task 1: Extract `OAuthPrerequisites` (shared predicates)

The doctor, the OAuth middleware, and the new wizard must answer "is this prerequisite met?" identically. Extract the predicates; observable behavior (messages, remedies, status codes) must not change — the existing `DoctorTest`, `McpRouteOAuthTest`, and `OAuthMisconfigTest` suites are the regression net.

**Files:**
- Create: `src/Support/OAuthPrerequisites.php`
- Modify: `src/Console/Doctor.php`
- Modify: `src/Middleware/AuthenticateOAuth.php`
- Test: `tests/Support/OAuthPrerequisitesTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Support/OAuthPrerequisitesTest.php`:

```php
<?php

use Danielgnh\StatamicMcp\Support\OAuthPrerequisites;

it('resolves the users driver through the configured repository', function () {
    config(['statamic.users.repository' => 'custom']);
    config(['statamic.users.repositories.custom.driver' => 'file']);

    $prereqs = new OAuthPrerequisites;

    expect($prereqs->usersRepository())->toBe('custom')
        ->and($prereqs->usersDriver())->toBe('file')
        ->and($prereqs->usersAreEloquent())->toBeFalse();
});

it('recognizes eloquent users regardless of the repository name', function () {
    config(['statamic.users.repository' => 'anything']);
    config(['statamic.users.repositories.anything.driver' => 'eloquent']);

    expect((new OAuthPrerequisites)->usersAreEloquent())->toBeTrue();
});

it('returns null for the users driver when the repository is not defined', function () {
    config(['statamic.users.repository' => 'ghost']);
    config(['statamic.users.repositories' => []]);

    expect((new OAuthPrerequisites)->usersDriver())->toBeNull();
});

it('reports the api guard state', function () {
    config(['auth.guards.api' => null]);

    $prereqs = new OAuthPrerequisites;

    expect($prereqs->apiGuardDefined())->toBeFalse()
        ->and($prereqs->apiGuardIsPassport())->toBeFalse();

    config(['auth.guards.api' => ['driver' => 'session', 'provider' => 'users']]);

    expect($prereqs->apiGuardDefined())->toBeTrue()
        ->and($prereqs->apiGuardDriver())->toBe('session')
        ->and($prereqs->apiGuardIsPassport())->toBeFalse();

    config(['auth.guards.api' => ['driver' => 'passport', 'provider' => 'users']]);

    expect((new OAuthPrerequisites)->apiGuardIsPassport())->toBeTrue();
});

it('resolves the user model from the api guard provider', function () {
    config(['auth.guards.api' => ['driver' => 'passport', 'provider' => 'special']]);
    config(['auth.providers.special.model' => 'App\Models\SpecialUser']);

    expect((new OAuthPrerequisites)->userModel())->toBe('App\Models\SpecialUser');
});

it('falls back to the users provider when the api guard names none', function () {
    config(['auth.guards.api' => null]);
    config(['auth.providers.users.model' => 'App\Models\User']);

    expect((new OAuthPrerequisites)->userModel())->toBe('App\Models\User');
});

// Passport is not in require-dev, so in this suite these are always false —
// which is exactly the branch a fresh host site exercises.
it('reports passport as absent in a suite without passport', function () {
    $prereqs = new OAuthPrerequisites;

    expect($prereqs->passportInstalled())->toBeFalse()
        ->and($prereqs->passportKeysExist())->toBeFalse()
        ->and($prereqs->userModelHasTrait())->toBeFalse();
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/pest tests/Support/OAuthPrerequisitesTest.php`
Expected: FAIL — `Class "Danielgnh\StatamicMcp\Support\OAuthPrerequisites" not found`.

- [ ] **Step 3: Create `src/Support/OAuthPrerequisites.php`**

```php
<?php

namespace Danielgnh\StatamicMcp\Support;

use Laravel\Passport\Passport;

/**
 * Single source of truth for every OAuth-mode prerequisite. Doctor (diagnosis),
 * AuthenticateOAuth (runtime preflight), and Setup (installer) all answer from
 * these predicates, so what gets checked, enforced, and fixed can never drift.
 */
class OAuthPrerequisites
{
    public function usersRepository(): string
    {
        return config('statamic.users.repository', 'file');
    }

    /**
     * The repository NAME is arbitrary — what matters is the driver it
     * resolves to (a 'custom' repository may still be file-driven).
     */
    public function usersDriver(): ?string
    {
        return config('statamic.users.repositories.'.$this->usersRepository().'.driver');
    }

    public function usersAreEloquent(): bool
    {
        return $this->usersDriver() === 'eloquent';
    }

    public function passportInstalled(): bool
    {
        return class_exists(Passport::class);
    }

    public function apiGuardDefined(): bool
    {
        return config('auth.guards.api') !== null;
    }

    public function apiGuardDriver(): ?string
    {
        return config('auth.guards.api.driver');
    }

    public function apiGuardIsPassport(): bool
    {
        return $this->apiGuardDriver() === 'passport';
    }

    public function userModel(): ?string
    {
        $provider = config('auth.guards.api.provider') ?? 'users';

        return config('auth.providers.'.$provider.'.model');
    }

    public function userModelHasTrait(): bool
    {
        $model = $this->userModel();

        return $model
            && class_exists($model)
            && in_array('Laravel\\Passport\\HasApiTokens', class_uses_recursive($model), true);
    }

    public function passportKeysExist(): bool
    {
        return $this->passportInstalled()
            && file_exists(Passport::keyPath('oauth-private.key'));
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/pest tests/Support/OAuthPrerequisitesTest.php`
Expected: PASS (7 tests).

- [ ] **Step 5: Refactor `Doctor` to consume the predicates**

In `src/Console/Doctor.php`:

1. Add the import: `use Danielgnh\StatamicMcp\Support\OAuthPrerequisites;`
2. Add a property and extend `handle()`'s injection:

```php
    protected OAuthPrerequisites $prereqs;

    public function handle(TokenRepository $tokens, OAuthPrerequisites $prereqs): int
    {
        $this->prereqs = $prereqs;
        // ... rest of handle() unchanged
```

3. Replace the body of `checkOAuth()`:

```php
    protected function checkOAuth(): void
    {
        $passportInstalled = $this->prereqs->passportInstalled();

        if ($passportInstalled) {
            $this->info('[ OK ] Laravel Passport is installed.');
        } else {
            $this->problem("Laravel Passport is not installed. Run 'composer require laravel/passport' and follow the OAuth setup in the statamic-mcp README, or switch to token mode ('auth' => 'token').");
        }

        $this->checkOAuthUsers($passportInstalled);
        $this->checkApiGuard();
    }
```

4. Replace the config reads at the top of `checkOAuthUsers()` (the messages stay byte-identical):

```php
    protected function checkOAuthUsers(bool $passportInstalled): void
    {
        $repository = $this->prereqs->usersRepository();
        $driver = $this->prereqs->usersDriver() ?? '(none)';
```

(The rest of the method — the `file` / non-`eloquent` / OK branches and the `checkUserModelTrait()` call — is unchanged.)

5. Replace the first two lines of `checkUserModelTrait()`:

```php
    protected function checkUserModelTrait(): void
    {
        $model = $this->prereqs->userModel();

        if ($this->prereqs->userModelHasTrait()) {
            $this->info('[ OK ] User model '.$model.' uses the HasApiTokens trait.');
        } else {
            $provider = config('auth.guards.api.provider') ?? 'users';

            $this->problem('User model '.($model ?: "(none configured in auth.providers.{$provider}.model)").' is missing the Laravel\\Passport\\HasApiTokens trait — add it per the README OAuth guide.');
        }
    }
```

6. Replace the guard reads in `checkApiGuard()` (messages unchanged):

```php
    protected function checkApiGuard(): void
    {
        if (! $this->prereqs->apiGuardDefined()) {
            $this->problem("No 'api' guard is defined — Laravel 12 and 13 ship none. In config/auth.php add 'api' => ['driver' => 'passport', 'provider' => 'users'] under 'guards'.");

            return;
        }

        $driver = $this->prereqs->apiGuardDriver() ?? '(none)';

        if ($driver !== 'passport') {
            // Wrong driver is worse than none: OAuth discovery and token
            // issuance complete, then every request 401-loops on tokens the
            // guard ignores — misconfiguration presenting as credential failure.
            $this->problem("The 'api' guard uses the '{$driver}' driver, not 'passport' — OAuth discovery and token issuance would complete, then every request 401-loops on tokens the guard ignores. In config/auth.php set 'api' => ['driver' => 'passport', 'provider' => 'users'] under 'guards'.");

            return;
        }

        $this->info("[ OK ] The 'api' guard uses the passport driver.");
    }
```

The now-unused `use Laravel\Passport\Passport;` import in `Doctor.php` should be removed.

- [ ] **Step 6: Refactor `AuthenticateOAuth` to consume the predicates**

In `src/Middleware/AuthenticateOAuth.php`, replace `preflightFailure()` (remedy strings byte-identical; the check ORDER must stay users → guard → passport, per the class docblock about honest reachability without Passport installed):

```php
    protected function preflightFailure(): ?Response
    {
        $prereqs = app(OAuthPrerequisites::class);

        // The requirement is Eloquent users, so OAuthPrerequisites tests the
        // RESOLVED driver, not the repository name (mcp:doctor applies the
        // same predicate).
        if (! $prereqs->usersAreEloquent()) {
            return $this->unavailable(
                "OAuth mode requires database (Eloquent) users — a Passport constraint, not ours. Run 'php please auth:migration' then 'php please eloquent:import-users', or switch to token mode ('auth' => 'token')."
            );
        }

        // Driver, not just presence: a pre-existing session/token/sanctum
        // 'api' guard would let OAuth discovery and token issuance complete,
        // then 401-loop on tokens the guard ignores — misconfiguration
        // presenting as credential failure.
        if (! $prereqs->apiGuardIsPassport()) {
            return $this->unavailable(
                "OAuth mode requires an 'api' guard. In config/auth.php add 'api' => ['driver' => 'passport', 'provider' => 'users'] under 'guards'."
            );
        }

        if (! $prereqs->passportInstalled()) {
            return $this->unavailable(
                "OAuth mode requires Laravel Passport. Run 'composer require laravel/passport' and follow the OAuth setup in the statamic-mcp README, or switch to token mode ('auth' => 'token')."
            );
        }

        return null;
    }
```

Add `use Danielgnh\StatamicMcp\Support\OAuthPrerequisites;` and remove the now-unused `use Laravel\Passport\Passport;` import.

- [ ] **Step 7: Run the full suite to verify no behavior changed**

Run: `composer test`
Expected: PASS — in particular `tests/Console/DoctorTest.php`, `tests/Feature/OAuthMisconfigTest.php`, `tests/Feature/McpRouteOAuthTest.php` all green.

- [ ] **Step 8: Format and commit**

```bash
composer format
git add src/Support/OAuthPrerequisites.php src/Console/Doctor.php src/Middleware/AuthenticateOAuth.php tests/Support/OAuthPrerequisitesTest.php
git commit -m "refactor: extract OAuth prerequisites into a shared predicate class"
```

---

### Task 2: `EditResult` enum + `EnvWriter`

**Files:**
- Create: `src/Setup/EditResult.php`
- Create: `src/Setup/EnvWriter.php`
- Test: `tests/Setup/EnvWriterTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Setup/EnvWriterTest.php`:

```php
<?php

use Danielgnh\StatamicMcp\Setup\EditResult;
use Danielgnh\StatamicMcp\Setup\EnvWriter;

beforeEach(function () {
    $this->path = tempnam(sys_get_temp_dir(), 'mcp-env-');
});

afterEach(function () {
    @unlink($this->path);
});

it('appends the key when absent', function () {
    file_put_contents($this->path, "APP_NAME=Statamic\n");

    $result = (new EnvWriter)->apply($this->path, 'STATAMIC_MCP_AUTH', 'oauth');

    expect($result)->toBe(EditResult::Applied)
        ->and(file_get_contents($this->path))->toBe("APP_NAME=Statamic\nSTATAMIC_MCP_AUTH=oauth\n");
});

it('replaces an existing value in place without touching other lines', function () {
    file_put_contents($this->path, "STATAMIC_MCP_AUTH=token\nAPP_NAME=Statamic\n");

    $result = (new EnvWriter)->apply($this->path, 'STATAMIC_MCP_AUTH', 'oauth');

    expect($result)->toBe(EditResult::Applied)
        ->and(file_get_contents($this->path))->toBe("STATAMIC_MCP_AUTH=oauth\nAPP_NAME=Statamic\n");
});

it('does not mistake a suffixed key for the key', function () {
    file_put_contents($this->path, "NOT_STATAMIC_MCP_AUTH=x\n");

    (new EnvWriter)->apply($this->path, 'STATAMIC_MCP_AUTH', 'oauth');

    expect(file_get_contents($this->path))->toBe("NOT_STATAMIC_MCP_AUTH=x\nSTATAMIC_MCP_AUTH=oauth\n");
});

it('skips when the value is already set', function () {
    file_put_contents($this->path, "STATAMIC_MCP_AUTH=oauth\n");

    expect((new EnvWriter)->apply($this->path, 'STATAMIC_MCP_AUTH', 'oauth'))->toBe(EditResult::Skipped)
        ->and(file_get_contents($this->path))->toBe("STATAMIC_MCP_AUTH=oauth\n");
});

it('bails when the file does not exist', function () {
    expect((new EnvWriter)->apply('/nonexistent/.env', 'K', 'v'))->toBe(EditResult::Bailed);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/pest tests/Setup/EnvWriterTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Create `src/Setup/EditResult.php`**

```php
<?php

namespace Danielgnh\StatamicMcp\Setup;

enum EditResult
{
    case Applied;   // the file was changed
    case Skipped;   // the desired state was already present
    case Bailed;    // the file didn't match the expected shape — the caller prints the manual snippet
}
```

- [ ] **Step 4: Create `src/Setup/EnvWriter.php`**

```php
<?php

namespace Danielgnh\StatamicMcp\Setup;

/**
 * Sets KEY=value in a dotenv file: replaces the existing assignment when the
 * key is present, appends otherwise. Never touches any other line.
 */
class EnvWriter
{
    public function apply(string $path, string $key, string $value): EditResult
    {
        if (! is_file($path) || ! is_writable($path)) {
            return EditResult::Bailed;
        }

        $contents = file_get_contents($path);
        $pattern = '/^'.preg_quote($key, '/').'=(.*)$/m';

        if (preg_match($pattern, $contents, $matches)) {
            if (trim($matches[1]) === $value) {
                return EditResult::Skipped;
            }

            file_put_contents($path, preg_replace($pattern, $key.'='.$value, $contents, 1));

            return EditResult::Applied;
        }

        file_put_contents($path, rtrim($contents, "\n")."\n".$key.'='.$value."\n");

        return EditResult::Applied;
    }

    public function snippet(string $key, string $value): string
    {
        return $key.'='.$value;
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `vendor/bin/pest tests/Setup/EnvWriterTest.php`
Expected: PASS (5 tests).

- [ ] **Step 6: Format and commit**

```bash
composer format
git add src/Setup tests/Setup
git commit -m "feat: EditResult enum and EnvWriter for the setup wizard"
```

---

### Task 3: `UsersRepositoryEditor`

**Files:**
- Create: `src/Setup/UsersRepositoryEditor.php`
- Test: `tests/Setup/UsersRepositoryEditorTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Setup/UsersRepositoryEditorTest.php`:

```php
<?php

use Danielgnh\StatamicMcp\Setup\EditResult;
use Danielgnh\StatamicMcp\Setup\UsersRepositoryEditor;

beforeEach(function () {
    $this->path = tempnam(sys_get_temp_dir(), 'mcp-users-');
});

afterEach(function () {
    @unlink($this->path);
});

it('flips the repository to eloquent', function () {
    file_put_contents($this->path, <<<'PHP'
<?php

return [

    'repository' => 'file',

    'repositories' => [
        'file' => [
            'driver' => 'file',
        ],
    ],

];
PHP);

    $result = (new UsersRepositoryEditor)->apply($this->path);

    expect($result)->toBe(EditResult::Applied)
        ->and(file_get_contents($this->path))->toContain("'repository' => 'eloquent'")
        // Only the top-level assignment changes — the repositories map keeps its keys:
        ->and(file_get_contents($this->path))->toContain("'file' => [");
});

it('skips when already eloquent', function () {
    file_put_contents($this->path, "<?php\n\nreturn [\n    'repository' => 'eloquent',\n];\n");

    expect((new UsersRepositoryEditor)->apply($this->path))->toBe(EditResult::Skipped);
});

it('bails on a file without the expected anchor, leaving it untouched', function () {
    $weird = "<?php\n\nreturn [\n    'repository' => env('USERS_REPO'),\n];\n";
    file_put_contents($this->path, $weird);

    expect((new UsersRepositoryEditor)->apply($this->path))->toBe(EditResult::Bailed)
        ->and(file_get_contents($this->path))->toBe($weird);
});

it('bails when the file does not exist', function () {
    expect((new UsersRepositoryEditor)->apply('/nonexistent/users.php'))->toBe(EditResult::Bailed);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/pest tests/Setup/UsersRepositoryEditorTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Create `src/Setup/UsersRepositoryEditor.php`**

```php
<?php

namespace Danielgnh\StatamicMcp\Setup;

/**
 * Flips 'repository' => '...' to 'eloquent' in config/statamic/users.php.
 * Anchor-based: only the first quoted 'repository' assignment is touched;
 * anything else (env() calls, missing key) bails to the manual snippet.
 */
class UsersRepositoryEditor
{
    public function apply(string $path): EditResult
    {
        if (! is_file($path) || ! is_writable($path)) {
            return EditResult::Bailed;
        }

        $contents = file_get_contents($path);

        if (! preg_match("/'repository'\s*=>\s*'([^']+)'/", $contents, $matches)) {
            return EditResult::Bailed;
        }

        if ($matches[1] === 'eloquent') {
            return EditResult::Skipped;
        }

        file_put_contents($path, preg_replace(
            "/'repository'\s*=>\s*'[^']+'/",
            "'repository' => 'eloquent'",
            $contents,
            1
        ));

        return EditResult::Applied;
    }

    public function snippet(): string
    {
        return "'repository' => 'eloquent',";
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/pest tests/Setup/UsersRepositoryEditorTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Format and commit**

```bash
composer format
git add src/Setup/UsersRepositoryEditor.php tests/Setup/UsersRepositoryEditorTest.php
git commit -m "feat: UsersRepositoryEditor flips the statamic users repository to eloquent"
```

---

### Task 4: `AuthGuardEditor`

**Files:**
- Create: `src/Setup/AuthGuardEditor.php`
- Test: `tests/Setup/AuthGuardEditorTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Setup/AuthGuardEditorTest.php`:

```php
<?php

use Danielgnh\StatamicMcp\Setup\AuthGuardEditor;
use Danielgnh\StatamicMcp\Setup\EditResult;

beforeEach(function () {
    $this->path = tempnam(sys_get_temp_dir(), 'mcp-auth-');
});

afterEach(function () {
    @unlink($this->path);
});

function standardAuthConfig(): string
{
    return <<<'PHP'
<?php

return [

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
    ],

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => App\Models\User::class,
        ],
    ],

];
PHP;
}

it('inserts a passport api guard into a standard auth config', function () {
    file_put_contents($this->path, standardAuthConfig());

    $result = (new AuthGuardEditor)->apply($this->path);
    $contents = file_get_contents($this->path);

    expect($result)->toBe(EditResult::Applied)
        ->and($contents)->toContain("'api' => [")
        ->and($contents)->toContain("'driver' => 'passport'")
        // The web guard is untouched:
        ->and($contents)->toContain("'driver' => 'session'");

    // The edited file must still be valid PHP returning the expected shape.
    $config = eval('?>'.$contents);

    expect($config['guards']['api'])->toBe(['driver' => 'passport', 'provider' => 'users'])
        ->and($config['guards']['web']['driver'])->toBe('session');
});

it('rewrites the driver of an existing non-passport api guard', function () {
    $withApiGuard = str_replace(
        "'guards' => [\n",
        "'guards' => [\n        'api' => [\n            'driver' => 'token',\n            'provider' => 'users',\n        ],\n",
        standardAuthConfig()
    );
    file_put_contents($this->path, $withApiGuard);

    $result = (new AuthGuardEditor)->apply($this->path);
    $config = eval('?>'.file_get_contents($this->path));

    expect($result)->toBe(EditResult::Applied)
        ->and($config['guards']['api']['driver'])->toBe('passport')
        ->and($config['guards']['web']['driver'])->toBe('session');
});

it('skips when the api guard already uses passport', function () {
    $ready = str_replace(
        "'guards' => [\n",
        "'guards' => [\n        'api' => [\n            'driver' => 'passport',\n            'provider' => 'users',\n        ],\n",
        standardAuthConfig()
    );
    file_put_contents($this->path, $ready);

    expect((new AuthGuardEditor)->apply($this->path))->toBe(EditResult::Skipped)
        ->and(file_get_contents($this->path))->toBe($ready);
});

it('bails on a file without a guards anchor, leaving it untouched', function () {
    $weird = "<?php\n\nreturn array_merge(\$base, ['guards' => \$guards]);\n";
    file_put_contents($this->path, $weird);

    expect((new AuthGuardEditor)->apply($this->path))->toBe(EditResult::Bailed)
        ->and(file_get_contents($this->path))->toBe($weird);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/pest tests/Setup/AuthGuardEditorTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Create `src/Setup/AuthGuardEditor.php`**

```php
<?php

namespace Danielgnh\StatamicMcp\Setup;

/**
 * Ensures config/auth.php has 'api' => ['driver' => 'passport', ...] under
 * 'guards': inserts the guard after the 'guards' => [ anchor, or rewrites the
 * driver of an existing 'api' guard in place. Anything unexpected bails so
 * the caller prints the manual snippet instead of guessing.
 */
class AuthGuardEditor
{
    public function apply(string $path): EditResult
    {
        if (! is_file($path) || ! is_writable($path)) {
            return EditResult::Bailed;
        }

        $contents = file_get_contents($path);

        // The api guard holds only scalar entries, so the block reliably ends
        // at the first ']' — no balancing needed.
        if (preg_match("/'api'\s*=>\s*\[[^\]]*\]/s", $contents, $matches)) {
            return $this->rewriteExistingGuard($path, $contents, $matches[0]);
        }

        return $this->insertGuard($path, $contents);
    }

    protected function rewriteExistingGuard(string $path, string $contents, string $block): EditResult
    {
        if (preg_match("/'driver'\s*=>\s*'passport'/", $block)) {
            return EditResult::Skipped;
        }

        if (! preg_match("/'driver'\s*=>\s*'[^']*'/", $block)) {
            return EditResult::Bailed;
        }

        $rewritten = preg_replace("/'driver'\s*=>\s*'[^']*'/", "'driver' => 'passport'", $block, 1);

        file_put_contents($path, str_replace($block, $rewritten, $contents));

        return EditResult::Applied;
    }

    protected function insertGuard(string $path, string $contents): EditResult
    {
        if (! preg_match("/'guards'\s*=>\s*\[\n/", $contents, $matches)) {
            return EditResult::Bailed;
        }

        $guard = "        'api' => [\n            'driver' => 'passport',\n            'provider' => 'users',\n        ],\n\n";

        file_put_contents($path, str_replace($matches[0], $matches[0].$guard, $contents));

        return EditResult::Applied;
    }

    public function snippet(): string
    {
        return <<<'PHP'
'guards' => [
    // ...
    'api' => [
        'driver' => 'passport',
        'provider' => 'users',
    ],
],
PHP;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/pest tests/Setup/AuthGuardEditorTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Format and commit**

```bash
composer format
git add src/Setup/AuthGuardEditor.php tests/Setup/AuthGuardEditorTest.php
git commit -m "feat: AuthGuardEditor inserts or repairs the passport api guard"
```

---

### Task 5: `UserModelEditor`

**Files:**
- Create: `src/Setup/UserModelEditor.php`
- Test: `tests/Setup/UserModelEditorTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Setup/UserModelEditorTest.php`:

```php
<?php

use Danielgnh\StatamicMcp\Setup\EditResult;
use Danielgnh\StatamicMcp\Setup\UserModelEditor;

beforeEach(function () {
    $this->path = tempnam(sys_get_temp_dir(), 'mcp-model-');
});

afterEach(function () {
    @unlink($this->path);
});

function standardUserModel(): string
{
    return <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use Notifiable;

    protected $fillable = ['name', 'email', 'password'];
}
PHP;
}

it('adds the trait and interface to a standard user model', function () {
    file_put_contents($this->path, standardUserModel());

    $result = (new UserModelEditor)->apply($this->path, 'Laravel\Passport\Contracts\OAuthenticatable');
    $contents = file_get_contents($this->path);

    expect($result)->toBe(EditResult::Applied)
        ->and($contents)->toContain('use Laravel\Passport\HasApiTokens;')
        ->and($contents)->toContain('use Laravel\Passport\Contracts\OAuthenticatable;')
        ->and($contents)->toContain('class User extends Authenticatable implements OAuthenticatable')
        ->and($contents)->toContain("{\n    use HasApiTokens;")
        // Existing members survive:
        ->and($contents)->toContain('use Notifiable;')
        ->and($contents)->toContain("protected \$fillable");
});

it('adds only the trait when no interface is available', function () {
    file_put_contents($this->path, standardUserModel());

    $result = (new UserModelEditor)->apply($this->path, null);
    $contents = file_get_contents($this->path);

    expect($result)->toBe(EditResult::Applied)
        ->and($contents)->toContain('use Laravel\Passport\HasApiTokens;')
        ->and($contents)->toContain("class User extends Authenticatable\n")
        ->and($contents)->not->toContain('implements');
});

it('extends an existing implements clause instead of adding a second one', function () {
    file_put_contents($this->path, str_replace(
        'class User extends Authenticatable',
        'class User extends Authenticatable implements MustVerifyEmail',
        standardUserModel()
    ));

    (new UserModelEditor)->apply($this->path, 'Laravel\Passport\Contracts\OAuthenticatable');

    expect(file_get_contents($this->path))
        ->toContain('class User extends Authenticatable implements MustVerifyEmail, OAuthenticatable');
});

it('skips when the passport trait is already present', function () {
    file_put_contents($this->path, str_replace(
        'use Illuminate\Notifications\Notifiable;',
        "use Illuminate\Notifications\Notifiable;\nuse Laravel\Passport\HasApiTokens;",
        standardUserModel()
    ));

    expect((new UserModelEditor)->apply($this->path, null))->toBe(EditResult::Skipped);
});

it('bails when a different HasApiTokens (e.g. Sanctum) is in play', function () {
    $sanctum = str_replace(
        'use Illuminate\Notifications\Notifiable;',
        "use Illuminate\Notifications\Notifiable;\nuse Laravel\Sanctum\HasApiTokens;",
        standardUserModel()
    );
    file_put_contents($this->path, $sanctum);

    expect((new UserModelEditor)->apply($this->path, null))->toBe(EditResult::Bailed)
        ->and(file_get_contents($this->path))->toBe($sanctum);
});

it('bails on a model without a recognizable class declaration', function () {
    $weird = "<?php\n\nnamespace App\Models;\n\nclass User extends Authenticatable implements\n    MustVerifyEmail\n{\n}\n";
    file_put_contents($this->path, $weird);

    expect((new UserModelEditor)->apply($this->path, null))->toBe(EditResult::Bailed)
        ->and(file_get_contents($this->path))->toBe($weird);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/pest tests/Setup/UserModelEditorTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Create `src/Setup/UserModelEditor.php`**

```php
<?php

namespace Danielgnh\StatamicMcp\Setup;

/**
 * Adds the Laravel\Passport\HasApiTokens trait — and, when the installed
 * Passport version ships it, the OAuthenticatable contract — to the user
 * model. Pure string insertion anchored on a single-line class declaration;
 * any model that doesn't match the expected shape (multi-line declaration,
 * a competing HasApiTokens like Sanctum's) bails to the manual snippet.
 */
class UserModelEditor
{
    protected const TRAIT = 'Laravel\Passport\HasApiTokens';

    public function apply(string $path, ?string $interface): EditResult
    {
        if (! is_file($path) || ! is_writable($path)) {
            return EditResult::Bailed;
        }

        $contents = file_get_contents($path);

        if (str_contains($contents, self::TRAIT)) {
            return EditResult::Skipped;
        }

        // A different HasApiTokens (Sanctum's) would collide with ours on the
        // unqualified name — a human must resolve that, not a regex.
        if (str_contains($contents, 'HasApiTokens')) {
            return EditResult::Bailed;
        }

        // Anchor: a single-line class declaration, e.g.
        // "class User extends Authenticatable" or "... implements MustVerifyEmail".
        if (! preg_match('/^(class\s+\w+[^\n{]*)$/m', $contents, $declaration)) {
            return EditResult::Bailed;
        }

        $updated = $contents;

        if ($interface !== null && ! str_contains($contents, class_basename($interface))) {
            $line = $declaration[1];

            $newLine = str_contains($line, 'implements')
                ? rtrim($line).', '.class_basename($interface)
                : rtrim($line).' implements '.class_basename($interface);

            $updated = str_replace($line, $newLine, $updated);
        }

        // Insert "use HasApiTokens;" as the first statement in the class body.
        if (! preg_match('/^class\s+\w+[^{]*\{\n/m', $updated, $body)) {
            return EditResult::Bailed;
        }

        $updated = str_replace($body[0], $body[0]."    use HasApiTokens;\n", $updated);

        $updated = $this->addImports($updated, $interface);

        if ($updated === null) {
            return EditResult::Bailed;
        }

        file_put_contents($path, $updated);

        return EditResult::Applied;
    }

    protected function addImports(string $contents, ?string $interface): ?string
    {
        $imports = 'use '.self::TRAIT.";\n";

        if ($interface !== null && ! str_contains($contents, 'use '.$interface.';')) {
            $imports .= 'use '.$interface.";\n";
        }

        if (! preg_match('/^namespace\s+[^;]+;\n\n/m', $contents, $matches)) {
            return null;
        }

        return str_replace($matches[0], $matches[0].$imports, $contents);
    }

    public function snippet(?string $interface): string
    {
        $implements = $interface ? ' implements \\'.$interface : '';

        return <<<PHP
use Laravel\Passport\HasApiTokens;

class User extends Authenticatable{$implements}
{
    use HasApiTokens;
    // ...
}
PHP;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/pest tests/Setup/UserModelEditorTest.php`
Expected: PASS (6 tests).

Note on the last test case: the multi-line `implements` declaration must bail because the class-declaration regex requires the whole declaration on one line — verify that's why it bails (not a coincidental earlier bail).

- [ ] **Step 5: Format and commit**

```bash
composer format
git add src/Setup/UserModelEditor.php tests/Setup/UserModelEditorTest.php
git commit -m "feat: UserModelEditor adds HasApiTokens and OAuthenticatable to the user model"
```

---

### Task 6: `Setup` command — skeleton + token path

**Files:**
- Create: `src/Console/Setup.php`
- Modify: `src/ServiceProvider.php` (register the command)
- Test: `tests/Console/SetupTokenPathTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Console/SetupTokenPathTest.php`:

```php
<?php

use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tokens\TokenRepository;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    File::delete(storage_path('statamic/mcp/tokens.yaml'));
});

it('issues a first token via the token path', function () {
    $user = Fixtures::makeUser();

    $this->artisan('statamic:mcp:setup')
        ->expectsChoice(
            'How will AI clients connect to this site?',
            'token',
            [
                'token' => 'Token — Claude Code, Cursor, MCP Inspector',
                'oauth' => 'OAuth — claude.ai, Claude Desktop, ChatGPT connectors',
            ]
        )
        ->expectsQuestion('Which Statamic user should the first token act as?', $user->email())
        ->assertExitCode(0);

    expect(app(TokenRepository::class)->all())->toHaveCount(1);
});

it('fails cleanly when the email matches no user', function () {
    $this->artisan('statamic:mcp:setup')
        ->expectsChoice(
            'How will AI clients connect to this site?',
            'token',
            [
                'token' => 'Token — Claude Code, Cursor, MCP Inspector',
                'oauth' => 'OAuth — claude.ai, Claude Desktop, ChatGPT connectors',
            ]
        )
        ->expectsQuestion('Which Statamic user should the first token act as?', 'ghost@nowhere.test')
        ->assertExitCode(1);

    expect(app(TokenRepository::class)->all())->toHaveCount(0);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/pest tests/Console/SetupTokenPathTest.php`
Expected: FAIL — command `statamic:mcp:setup` not found.

- [ ] **Step 3: Create `src/Console/Setup.php` (token path complete, OAuth path stubbed)**

```php
<?php

namespace Danielgnh\StatamicMcp\Console;

use Closure;
use Danielgnh\StatamicMcp\Setup\EditResult;
use Danielgnh\StatamicMcp\Setup\EnvWriter;
use Illuminate\Console\Command;
use Statamic\Console\RunsInPlease;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

class Setup extends Command
{
    use RunsInPlease;

    protected $signature = 'statamic:mcp:setup';

    protected $description = 'Interactive setup wizard for the MCP server — token or OAuth mode.';

    public function handle(EnvWriter $env): int
    {
        $this->components->info('Statamic MCP setup');

        $mode = select(
            label: 'How will AI clients connect to this site?',
            options: [
                'token' => 'Token — Claude Code, Cursor, MCP Inspector',
                'oauth' => 'OAuth — claude.ai, Claude Desktop, ChatGPT connectors',
            ],
            default: config('statamic.mcp.auth', 'token'),
            hint: 'OAuth requires database (Eloquent) users — a Passport constraint.',
        );

        return $mode === 'oauth'
            ? $this->setupOAuth()
            : $this->setupToken($env);
    }

    protected function setupToken(EnvWriter $env): int
    {
        if (config('statamic.mcp.auth') === 'oauth') {
            $this->applyEdit(
                'Set STATAMIC_MCP_AUTH=token in .env',
                base_path('.env'),
                fn () => $env->apply(base_path('.env'), 'STATAMIC_MCP_AUTH', 'token'),
                fn () => $env->snippet('STATAMIC_MCP_AUTH', 'token'),
            );
        }

        $email = text(
            label: 'Which Statamic user should the first token act as?',
            placeholder: 'you@site.com',
            required: true,
        );

        // mcp:token owns issuance, output, and the permission/APP_URL warnings.
        return $this->call('statamic:mcp:token', ['email' => $email]);
    }

    protected function setupOAuth(): int
    {
        $this->components->error('OAuth setup is not implemented yet.');

        return self::FAILURE;
    }

    /**
     * The one rhythm every file edit follows: announce the change and the
     * file, confirm, apply — and on decline or bail, print the manual snippet
     * and carry on. The wizard never edits silently and never mangles a file
     * it doesn't recognize.
     */
    protected function applyEdit(string $description, string $path, Closure $apply, Closure $snippet): void
    {
        $this->line('');
        $this->components->info($description);
        $this->line('  File: '.$path);
        $this->line('');
        $this->line('  '.str_replace("\n", "\n  ", $snippet()));
        $this->line('');

        if (! confirm('Apply this change to '.$path.'?')) {
            $this->components->warn('Skipped — apply the snippet above manually before connecting a client.');

            return;
        }

        match ($apply()) {
            EditResult::Applied => $this->components->twoColumnDetail($description, 'applied'),
            EditResult::Skipped => $this->components->twoColumnDetail($description, 'skipped — already in place'),
            EditResult::Bailed => $this->components->warn($path." doesn't look like the file this wizard expects — apply the snippet above manually."),
        };
    }
}
```

- [ ] **Step 4: Register the command in `src/ServiceProvider.php`**

Add the import and the class to the existing `$commands` array:

```php
use Danielgnh\StatamicMcp\Console\Setup;
```

```php
    protected $commands = [
        IssueToken::class,
        ListTokens::class,
        RevokeToken::class,
        Doctor::class,
        Setup::class,
    ];
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `vendor/bin/pest tests/Console/SetupTokenPathTest.php`
Expected: PASS (2 tests).

- [ ] **Step 6: Format and commit**

```bash
composer format
git add src/Console/Setup.php src/ServiceProvider.php tests/Console/SetupTokenPathTest.php
git commit -m "feat: mcp:setup wizard skeleton with the token onboarding path"
```

---

### Task 7: `Setup` command — OAuth path + finale

**Files:**
- Modify: `src/Console/Setup.php`
- Test: `tests/Console/SetupOAuthPathTest.php`

- [ ] **Step 1: Write the failing test (happy path, fresh install)**

Create `tests/Console/SetupOAuthPathTest.php`. The editors and prerequisites are container-resolved, so the orchestration test binds recording fakes — the editors' real file behavior is already covered by their own unit tests (Tasks 2–5).

```php
<?php

use Danielgnh\StatamicMcp\Setup\AuthGuardEditor;
use Danielgnh\StatamicMcp\Setup\EditResult;
use Danielgnh\StatamicMcp\Setup\EnvWriter;
use Danielgnh\StatamicMcp\Setup\UserModelEditor;
use Danielgnh\StatamicMcp\Setup\UsersRepositoryEditor;
use Danielgnh\StatamicMcp\Support\OAuthPrerequisites;
use Illuminate\Support\Facades\Process;

// A real class so ReflectionClass can resolve a file path for the model step.
class SetupWizardTestUser {}

function fakeEditors(): array
{
    $fakes = [
        UsersRepositoryEditor::class => new class extends UsersRepositoryEditor
        {
            public array $applied = [];

            public function apply(string $path): EditResult
            {
                $this->applied[] = $path;

                return EditResult::Applied;
            }
        },
        AuthGuardEditor::class => new class extends AuthGuardEditor
        {
            public array $applied = [];

            public function apply(string $path): EditResult
            {
                $this->applied[] = $path;

                return EditResult::Applied;
            }
        },
        UserModelEditor::class => new class extends UserModelEditor
        {
            public array $applied = [];

            public function apply(string $path, ?string $interface): EditResult
            {
                $this->applied[] = [$path, $interface];

                return EditResult::Applied;
            }
        },
        EnvWriter::class => new class extends EnvWriter
        {
            public array $writes = [];

            public function apply(string $path, string $key, string $value): EditResult
            {
                $this->writes[] = [$key, $value];

                return EditResult::Applied;
            }
        },
    ];

    foreach ($fakes as $abstract => $fake) {
        app()->instance($abstract, $fake);
    }

    return $fakes;
}

function freshInstallPrereqs(): void
{
    app()->instance(OAuthPrerequisites::class, new class extends OAuthPrerequisites
    {
        public function usersAreEloquent(): bool
        {
            return false;
        }

        public function passportInstalled(): bool
        {
            return false;
        }

        public function apiGuardIsPassport(): bool
        {
            return false;
        }

        public function passportKeysExist(): bool
        {
            return false;
        }

        public function userModel(): ?string
        {
            return SetupWizardTestUser::class;
        }

        public function userModelHasTrait(): bool
        {
            return false;
        }
    });
}

const MODE_OPTIONS = [
    'token' => 'Token — Claude Code, Cursor, MCP Inspector',
    'oauth' => 'OAuth — claude.ai, Claude Desktop, ChatGPT connectors',
];

it('walks a fresh install through every oauth step', function () {
    Process::fake();
    $fakes = fakeEditors();
    freshInstallPrereqs();

    $modelPath = (new ReflectionClass(SetupWizardTestUser::class))->getFileName();

    $this->artisan('statamic:mcp:setup')
        ->expectsChoice('How will AI clients connect to this site?', 'oauth', MODE_OPTIONS)
        // Step 1: users → database
        ->expectsConfirmation('Migrate users to the database now?', 'yes')
        ->expectsConfirmation('Apply this change to '.config_path('statamic/users.php').'?', 'yes')
        // Step 2: passport
        ->expectsConfirmation('Install laravel/passport via composer now?', 'yes')
        // Step 3: plumbing
        ->expectsConfirmation('Publish Passport migrations, run them, and generate encryption keys?', 'yes')
        // Step 4: user model
        ->expectsConfirmation('Apply this change to '.$modelPath.'?', 'yes')
        // Step 5: api guard
        ->expectsConfirmation('Apply this change to '.config_path('auth.php').'?', 'yes')
        // Step 6: consent views (optional — decline)
        ->expectsConfirmation('Publish the OAuth consent screen views (customizable Blade)?', 'no')
        // Step 7: env flip
        ->expectsConfirmation('Apply this change to '.base_path('.env').'?', 'yes')
        ->assertExitCode(0);

    Process::assertRan('php please auth:migration');
    Process::assertRan('php artisan migrate');
    Process::assertRan('php please eloquent:import-users');
    Process::assertRan('composer require laravel/passport');
    Process::assertRan('php artisan vendor:publish --tag=passport-migrations');
    Process::assertRan('php artisan passport:keys');
    Process::assertRan('php please mcp:doctor');

    expect($fakes[UsersRepositoryEditor::class]->applied)->toBe([config_path('statamic/users.php')])
        ->and($fakes[AuthGuardEditor::class]->applied)->toBe([config_path('auth.php')])
        ->and($fakes[UserModelEditor::class]->applied)->toBe([[$modelPath, null]])
        ->and($fakes[EnvWriter::class]->writes)->toBe([['STATAMIC_MCP_AUTH', 'oauth']]);
});
```

Note the `[[$modelPath, null]]` expectation: Passport is absent from this suite and no vendor path exists in the Testbench skeleton, so the resolved `OAuthenticatable` interface is `null` — that's the assertion that the FQCN is resolved from the environment, not hardcoded.

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/pest tests/Console/SetupOAuthPathTest.php`
Expected: FAIL — output contains "OAuth setup is not implemented yet." and exit code 1.

- [ ] **Step 3: Implement the OAuth path in `src/Console/Setup.php`**

Replace the entire file with:

```php
<?php

namespace Danielgnh\StatamicMcp\Console;

use Closure;
use Danielgnh\StatamicMcp\Setup\AuthGuardEditor;
use Danielgnh\StatamicMcp\Setup\EditResult;
use Danielgnh\StatamicMcp\Setup\EnvWriter;
use Danielgnh\StatamicMcp\Setup\UserModelEditor;
use Danielgnh\StatamicMcp\Setup\UsersRepositoryEditor;
use Danielgnh\StatamicMcp\Support\OAuthPrerequisites;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use ReflectionClass;
use Statamic\Console\RunsInPlease;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

class Setup extends Command
{
    use RunsInPlease;

    protected $signature = 'statamic:mcp:setup';

    protected $description = 'Interactive setup wizard for the MCP server — token or OAuth mode.';

    protected OAuthPrerequisites $prereqs;

    public function handle(OAuthPrerequisites $prereqs, EnvWriter $env): int
    {
        $this->prereqs = $prereqs;

        $this->components->info('Statamic MCP setup');

        $mode = select(
            label: 'How will AI clients connect to this site?',
            options: [
                'token' => 'Token — Claude Code, Cursor, MCP Inspector',
                'oauth' => 'OAuth — claude.ai, Claude Desktop, ChatGPT connectors',
            ],
            default: config('statamic.mcp.auth', 'token'),
            hint: 'OAuth requires database (Eloquent) users — a Passport constraint.',
        );

        return $mode === 'oauth'
            ? $this->setupOAuth($env)
            : $this->setupToken($env);
    }

    protected function setupToken(EnvWriter $env): int
    {
        if (config('statamic.mcp.auth') === 'oauth') {
            $this->applyEdit(
                'Set STATAMIC_MCP_AUTH=token in .env',
                base_path('.env'),
                fn () => $env->apply(base_path('.env'), 'STATAMIC_MCP_AUTH', 'token'),
                fn () => $env->snippet('STATAMIC_MCP_AUTH', 'token'),
            );
        }

        $email = text(
            label: 'Which Statamic user should the first token act as?',
            placeholder: 'you@site.com',
            required: true,
        );

        // mcp:token owns issuance, output, and the permission/APP_URL warnings.
        return $this->call('statamic:mcp:token', ['email' => $email]);
    }

    /**
     * Every step follows the same rhythm: already satisfied? report and move
     * on — otherwise confirm and apply. The .env flip runs LAST so an aborted
     * run never leaves a broken oauth mode live; token mode keeps working
     * until everything else is in place.
     */
    protected function setupOAuth(EnvWriter $env): int
    {
        $steps = [
            fn (): bool => $this->ensureEloquentUsers(),
            fn (): bool => $this->ensurePassportInstalled(),
            fn (): bool => $this->ensurePassportPlumbing(),
            fn (): bool => $this->ensureUserModelPrepared(),
            fn (): bool => $this->ensureApiGuard(),
            fn (): bool => $this->offerConsentViews(),
            fn (): bool => $this->flipAuthMode($env),
        ];

        foreach ($steps as $step) {
            if (! $step()) {
                $this->components->error('Setup stopped. Fix the problem above and re-run `php please mcp:setup` — completed steps will be skipped.');

                return self::FAILURE;
            }
        }

        return $this->finale();
    }

    protected function ensureEloquentUsers(): bool
    {
        if ($this->prereqs->usersAreEloquent()) {
            $this->components->twoColumnDetail('Database (Eloquent) users', 'skipped — already configured');

            return true;
        }

        $this->components->warn('OAuth mode requires database users (a Passport constraint). This migrates your user data — back up first if in doubt.');

        if (! confirm('Migrate users to the database now?')) {
            $this->printManual('Migrate users per https://statamic.dev/tips/storing-users-in-a-database, then re-run this wizard.');

            return false; // everything after this depends on Eloquent users
        }

        if (! $this->runProcess('php please auth:migration')
            || ! $this->runProcess('php artisan migrate')
            || ! $this->runProcess('php please eloquent:import-users')) {
            return false;
        }

        $editor = app(UsersRepositoryEditor::class);

        $this->applyEdit(
            "Set 'repository' => 'eloquent' in config/statamic/users.php",
            config_path('statamic/users.php'),
            fn () => $editor->apply(config_path('statamic/users.php')),
            fn () => $editor->snippet(),
        );

        return true;
    }

    protected function ensurePassportInstalled(): bool
    {
        if ($this->prereqs->passportInstalled()) {
            $this->components->twoColumnDetail('Laravel Passport', 'skipped — already installed');

            return true;
        }

        if (! confirm('Install laravel/passport via composer now?')) {
            $this->printManual('composer require laravel/passport');

            return false; // every remaining step needs the package
        }

        return $this->runProcess('composer require laravel/passport');
    }

    protected function ensurePassportPlumbing(): bool
    {
        if ($this->prereqs->passportKeysExist()) {
            $this->components->twoColumnDetail('Passport migrations & keys', 'skipped — keys already exist');

            return true;
        }

        if (! confirm('Publish Passport migrations, run them, and generate encryption keys?')) {
            $this->printManual("php artisan vendor:publish --tag=passport-migrations\nphp artisan migrate\nphp artisan passport:keys");

            return false;
        }

        // Subprocesses on purpose: when Passport was installed moments ago by
        // this very wizard, its commands are not registered in THIS process.
        return $this->runProcess('php artisan vendor:publish --tag=passport-migrations')
            && $this->runProcess('php artisan migrate')
            && $this->runProcess('php artisan passport:keys');
    }

    protected function ensureUserModelPrepared(): bool
    {
        $model = $this->prereqs->userModel();

        if (! $model || ! class_exists($model)) {
            $this->printManual('No user model resolved from auth.providers — add the Laravel\Passport\HasApiTokens trait to your user model manually (see the README OAuth guide).');

            return true; // not fatal for the remaining steps
        }

        if ($this->prereqs->userModelHasTrait()) {
            $this->components->twoColumnDetail('HasApiTokens trait on '.$model, 'skipped — already present');

            return true;
        }

        $interface = $this->oauthenticatableInterface();
        $path = (new ReflectionClass($model))->getFileName();
        $editor = app(UserModelEditor::class);

        $this->applyEdit(
            'Add HasApiTokens'.($interface ? ' + OAuthenticatable' : '').' to '.$model,
            $path,
            fn () => $editor->apply($path, $interface),
            fn () => $editor->snippet($interface),
        );

        return true;
    }

    /**
     * The FQCN is resolved from the environment, never hardcoded blind:
     * interface_exists() covers Passport already loaded; the vendor-path probe
     * covers Passport installed by this very wizard in a subprocess, where the
     * running autoloader can't see the new package yet.
     */
    protected function oauthenticatableInterface(): ?string
    {
        $interface = 'Laravel\Passport\Contracts\OAuthenticatable';

        if (interface_exists($interface)
            || is_file(base_path('vendor/laravel/passport/src/Contracts/OAuthenticatable.php'))) {
            return $interface;
        }

        return null;
    }

    protected function ensureApiGuard(): bool
    {
        if ($this->prereqs->apiGuardIsPassport()) {
            $this->components->twoColumnDetail("'api' guard (passport driver)", 'skipped — already configured');

            return true;
        }

        $editor = app(AuthGuardEditor::class);

        $this->applyEdit(
            "Add the 'api' guard (passport driver) to config/auth.php",
            config_path('auth.php'),
            fn () => $editor->apply(config_path('auth.php')),
            fn () => $editor->snippet(),
        );

        return true;
    }

    protected function offerConsentViews(): bool
    {
        if (! confirm('Publish the OAuth consent screen views (customizable Blade)?', default: false)) {
            return true;
        }

        return $this->runProcess('php artisan vendor:publish --tag=mcp-views');
    }

    protected function flipAuthMode(EnvWriter $env): bool
    {
        if (config('statamic.mcp.auth') === 'oauth') {
            $this->components->twoColumnDetail('STATAMIC_MCP_AUTH=oauth', 'skipped — already set');
        } else {
            $this->applyEdit(
                'Set STATAMIC_MCP_AUTH=oauth in .env',
                base_path('.env'),
                fn () => $env->apply(base_path('.env'), 'STATAMIC_MCP_AUTH', 'oauth'),
                fn () => $env->snippet('STATAMIC_MCP_AUTH', 'oauth'),
            );
        }

        if (app()->configurationIsCached()) {
            return $this->runProcess('php artisan config:clear');
        }

        return true;
    }

    protected function finale(): int
    {
        $this->line('');
        $this->components->info('Verifying with mcp:doctor…');

        // Subprocess on purpose: the doctor must see the files this wizard
        // just edited, not this process's stale in-memory config.
        $healthy = $this->runProcess('php please mcp:doctor');

        $url = url(config('statamic.mcp.route', 'mcp/statamic'));

        if (! $healthy) {
            $this->components->error('The doctor found problems — fix the [FAIL] items above and re-run `php please mcp:setup`.');

            return self::FAILURE;
        }

        $this->components->info('Done. Add this connector URL to claude.ai or ChatGPT: '.$url);
        $this->line('Connectors need the site reachable over HTTPS from the internet.');

        return self::SUCCESS;
    }

    protected function runProcess(string $command): bool
    {
        $this->line('  → '.$command);

        $result = Process::forever()->run($command, function (string $type, string $output) {
            $this->output->write($output);
        });

        if ($result->failed()) {
            $this->components->error("'{$command}' failed (exit {$result->exitCode()}).");
        }

        return $result->successful();
    }

    /**
     * The one rhythm every file edit follows: announce the change and the
     * file, confirm, apply — and on decline or bail, print the manual snippet
     * and carry on. The wizard never edits silently and never mangles a file
     * it doesn't recognize.
     */
    protected function applyEdit(string $description, string $path, Closure $apply, Closure $snippet): void
    {
        $this->line('');
        $this->components->info($description);
        $this->line('  File: '.$path);
        $this->line('');
        $this->line('  '.str_replace("\n", "\n  ", $snippet()));
        $this->line('');

        if (! confirm('Apply this change to '.$path.'?')) {
            $this->components->warn('Skipped — apply the snippet above manually before connecting a client.');

            return;
        }

        match ($apply()) {
            EditResult::Applied => $this->components->twoColumnDetail($description, 'applied'),
            EditResult::Skipped => $this->components->twoColumnDetail($description, 'skipped — already in place'),
            EditResult::Bailed => $this->components->warn($path." doesn't look like the file this wizard expects — apply the snippet above manually."),
        };
    }

    protected function printManual(string $instructions): void
    {
        $this->components->warn('Manual step required:');
        $this->line('  '.str_replace("\n", "\n  ", $instructions));
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/pest tests/Console/SetupOAuthPathTest.php tests/Console/SetupTokenPathTest.php`
Expected: PASS.

- [ ] **Step 5: Format and commit**

```bash
composer format
git add src/Console/Setup.php tests/Console/SetupOAuthPathTest.php
git commit -m "feat: mcp:setup oauth path — check, confirm, apply every passport prerequisite"
```

---

### Task 8: Idempotency and failure-handling tests

**Files:**
- Modify: `tests/Console/SetupOAuthPathTest.php` (append tests)

- [ ] **Step 1: Append the idempotency test**

Add to `tests/Console/SetupOAuthPathTest.php`:

```php
it('skips every oauth step on an already-configured install', function () {
    Process::fake();
    $fakes = fakeEditors();

    app()->instance(OAuthPrerequisites::class, new class extends OAuthPrerequisites
    {
        public function usersAreEloquent(): bool
        {
            return true;
        }

        public function passportInstalled(): bool
        {
            return true;
        }

        public function apiGuardIsPassport(): bool
        {
            return true;
        }

        public function passportKeysExist(): bool
        {
            return true;
        }

        public function userModel(): ?string
        {
            return SetupWizardTestUser::class;
        }

        public function userModelHasTrait(): bool
        {
            return true;
        }
    });

    config(['statamic.mcp.auth' => 'oauth']);

    $this->artisan('statamic:mcp:setup')
        ->expectsChoice('How will AI clients connect to this site?', 'oauth', MODE_OPTIONS)
        // The ONLY prompt left on a satisfied install is the optional views publish:
        ->expectsConfirmation('Publish the OAuth consent screen views (customizable Blade)?', 'no')
        ->expectsOutputToContain('skipped')
        ->assertExitCode(0);

    // Nothing was installed, migrated, or edited — only the doctor ran.
    Process::assertDidntRun('composer require laravel/passport');
    Process::assertDidntRun('php artisan migrate');
    Process::assertRan('php please mcp:doctor');

    expect($fakes[UsersRepositoryEditor::class]->applied)->toBe([])
        ->and($fakes[AuthGuardEditor::class]->applied)->toBe([])
        ->and($fakes[UserModelEditor::class]->applied)->toBe([])
        ->and($fakes[EnvWriter::class]->writes)->toBe([]);
});
```

- [ ] **Step 2: Append the abort-on-failure tests**

```php
it('stops before composer when the user declines the users migration', function () {
    Process::fake();
    fakeEditors();
    freshInstallPrereqs();

    $this->artisan('statamic:mcp:setup')
        ->expectsChoice('How will AI clients connect to this site?', 'oauth', MODE_OPTIONS)
        ->expectsConfirmation('Migrate users to the database now?', 'no')
        ->expectsOutputToContain('Setup stopped.')
        ->assertExitCode(1);

    Process::assertDidntRun('composer require laravel/passport');
    Process::assertDidntRun('php please mcp:doctor');
});

it('stops when an external command fails, before any later step runs', function () {
    Process::fake([
        'php please auth:migration' => Process::result(exitCode: 1, errorOutput: 'boom'),
        '*' => Process::result(),
    ]);
    fakeEditors();
    freshInstallPrereqs();

    $this->artisan('statamic:mcp:setup')
        ->expectsChoice('How will AI clients connect to this site?', 'oauth', MODE_OPTIONS)
        ->expectsConfirmation('Migrate users to the database now?', 'yes')
        ->expectsOutputToContain("'php please auth:migration' failed (exit 1).")
        ->expectsOutputToContain('Setup stopped.')
        ->assertExitCode(1);

    Process::assertDidntRun('composer require laravel/passport');
});

it('exits non-zero when the final doctor run finds problems', function () {
    Process::fake([
        'php please mcp:doctor' => Process::result(exitCode: 1),
        '*' => Process::result(),
    ]);
    fakeEditors();
    freshInstallPrereqs();

    $modelPath = (new ReflectionClass(SetupWizardTestUser::class))->getFileName();

    $this->artisan('statamic:mcp:setup')
        ->expectsChoice('How will AI clients connect to this site?', 'oauth', MODE_OPTIONS)
        ->expectsConfirmation('Migrate users to the database now?', 'yes')
        ->expectsConfirmation('Apply this change to '.config_path('statamic/users.php').'?', 'yes')
        ->expectsConfirmation('Install laravel/passport via composer now?', 'yes')
        ->expectsConfirmation('Publish Passport migrations, run them, and generate encryption keys?', 'yes')
        ->expectsConfirmation('Apply this change to '.$modelPath.'?', 'yes')
        ->expectsConfirmation('Apply this change to '.config_path('auth.php').'?', 'yes')
        ->expectsConfirmation('Publish the OAuth consent screen views (customizable Blade)?', 'no')
        ->expectsConfirmation('Apply this change to '.base_path('.env').'?', 'yes')
        ->expectsOutputToContain('The doctor found problems')
        ->assertExitCode(1);
});
```

- [ ] **Step 3: Run the tests**

Run: `vendor/bin/pest tests/Console/SetupOAuthPathTest.php`
Expected: PASS (5 tests total in the file).

- [ ] **Step 4: Run the full suite**

Run: `composer test`
Expected: PASS — everything, including all pre-existing tests.

- [ ] **Step 5: Format and commit**

```bash
composer format
git add tests/Console/SetupOAuthPathTest.php
git commit -m "test: mcp:setup idempotency and failure handling"
```

---

### Task 9: Docs

**Files:**
- Modify: `README.md`
- Modify: `CHANGELOG.md`

**Note:** `CHANGELOG.md` and `resources/views/utilities/mcp-tokens.blade.php` have pre-existing uncommitted user changes — do NOT revert or commit those hunks. Stage only the hunks this task adds (`git add -p`, or append cleanly and stage the file only if the pre-existing changes were already committed by then).

- [ ] **Step 1: Update the README**

Directly under the `## Auth` heading area (before "Auth mode 1"), add a short section:

```markdown
## Quick start: the setup wizard

```bash
php please mcp:setup
```

One interactive command for either mode: pick **token** and it issues your first
token with ready-to-paste client snippets; pick **OAuth** and it checks, confirms,
and applies every Passport prerequisite below — migrating users to the database,
installing Passport, preparing the user model, adding the `api` guard, and flipping
`STATAMIC_MCP_AUTH` (deliberately last, so an aborted run never leaves a broken
mode live). It never edits a file without showing the change and asking first; a
file it doesn't recognize gets the exact manual snippet instead. Re-running is
safe — satisfied steps are skipped. It finishes by running `mcp:doctor` as proof.

The manual steps below remain as the reference path — they are exactly what the
wizard does (and what it prints when it bails on a non-standard file).
```

In the "Auth mode 2" intro, add one sentence after "just this setup path:":
`Prefer the wizard: php please mcp:setup automates all four steps below.`

- [ ] **Step 2: Update the CHANGELOG**

Append under the `## Unreleased` section (create it at the top if absent):

```markdown
### Added

- `php please mcp:setup` — interactive onboarding wizard for both auth modes. The
  OAuth path checks, confirms, and applies every Passport prerequisite (users →
  database, Passport install, user model trait/contract, `api` guard, `.env` flip —
  last, so aborted runs never leave a broken mode live) and verifies with
  `mcp:doctor`. File edits are anchor-based with a printed manual fallback; the
  wizard is idempotent.
```

- [ ] **Step 3: Verify, format, and commit**

Run: `composer test` (docs shouldn't break anything — belt and suspenders).

```bash
composer format
git add README.md
git add -p CHANGELOG.md   # stage ONLY the wizard hunk, not pre-existing user edits
git commit -m "docs: lead auth onboarding with the mcp:setup wizard"
```

---

## Final verification (after all tasks)

- [ ] `composer test` — full suite green.
- [ ] `composer format` — no diff.
- [ ] `vendor/bin/phpstan analyse` — no new errors (larastan is in require-dev; config in `phpstan.neon`).
- [ ] Re-read the spec (`docs/superpowers/specs/2026-07-12-mcp-setup-wizard-design.md`) — every section maps to a landed task.

## Self-review notes (already applied)

- Spec coverage: mode select + token path (Task 6), all seven OAuth steps + finale (Task 7), editors with bail/skip/apply semantics (Tasks 2–5), predicate extraction with unchanged behavior (Task 1), idempotency + failure handling (Task 8), README/CHANGELOG (Task 9).
- The spec's "offer to rewrite a wrong-driver api guard" is implemented inside `AuthGuardEditor::rewriteExistingGuard` — the offer is the standard `applyEdit` confirm.
- The spec's "FQCN read at runtime" is `oauthenticatableInterface()` — `interface_exists` plus a vendor-path probe (needed because a composer install in a subprocess is invisible to the running autoloader), asserted by the `[[$modelPath, null]]` expectation in the happy-path test.
- Type consistency: every editor's `apply()` returns `EditResult`; `UserModelEditor::apply(string $path, ?string $interface)` and `snippet(?string $interface)` match between Task 5 and Task 7; `EnvWriter::apply(string $path, string $key, string $value)` matches between Tasks 2, 6, and 7.
```
