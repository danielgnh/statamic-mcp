# danielgnh/statamic-mcp Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build `danielgnh/statamic-mcp` — an open-source Statamic v6 addon exposing a remote (streamable-HTTP) MCP server with CRUD for entries, taxonomy terms, and globals, plus read-only discovery, authenticated as real Statamic users under native Statamic permissions.

**Architecture:** A thin addon on `laravel/mcp ^0.8`: one Server class, 14 small verb-family Tool classes calling Statamic facades only, a file-backed token guard (default) with opt-in Passport OAuth mode, and a single one-screen config file. No database tables, no CP UI, no JS.

**Tech Stack:** PHP 8.3, `statamic/cms ^6.0`, `laravel/mcp ^0.8`, Pest 4 on `Statamic\Testing\AddonTestCase`, Pint, PHPStan/Larastan, GitHub Actions.

**Companion documents (read before starting):**
- Spec (the contract): `docs/superpowers/specs/2026-07-08-statamic-mcp-design.md`
- Contracts appendix (shared code every task copies from): `docs/superpowers/plans/2026-07-09-statamic-mcp-contracts.md`

**Conventions for every task:** run `composer format` before each commit; commit messages are conventional (`feat:`/`test:`/`chore:`) and end with `Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>`.

---

### Task 1: Repository scaffold (composer.json, Pint, PHPUnit, gitignore, directory skeleton)

**Files:**
- Create: `composer.json`
- Create: `pint.json`
- Create: `phpunit.xml`
- Create: `.gitignore`
- Create: `tests/__fixtures__/dev-null/.gitkeep`

All commands in every task run from the repository root.

- [ ] **Step 1: Initialize the repository and directory skeleton**

  ```bash
  git init -b main
  mkdir -p src/Tools src/Middleware src/Tokens src/Console config tests/Feature tests/Support tests/__fixtures__/dev-null
  touch tests/__fixtures__/dev-null/.gitkeep
  ```

  Why `tests/__fixtures__/dev-null/.gitkeep` is load-bearing (verified against statamic/cms 6.x `Statamic\Testing\AddonTestCase` source): when a test case uses the `PreventsSavingStacheItemsToDisk` trait, `AddonTestCase::setUp()` resolves `$this->fakeStacheDirectory` to `<addon>/tests/__fixtures__/dev-null` (relative to the ServiceProvider file) and `tearDown()` deletes and recreates that directory with a `.gitkeep`. The directory (with keep file) must exist and be committed.

  Git does not track empty directories — the `src/*` subdirectories will materialize in git as later tasks add files; creating them now just mirrors the spec's package layout.

- [ ] **Step 2: Write `composer.json` (verbatim from the shared contracts file — do not restyle)**

  ```json
  {
      "name": "danielgnh/statamic-mcp",
      "description": "MCP server for Statamic — manage entries, terms, and globals from AI clients over streamable HTTP.",
      "license": "MIT",
      "authors": [
          {
              "name": "Daniel Goncharov"
          }
      ],
      "require": {
          "php": "^8.3",
          "statamic/cms": "^6.0",
          "laravel/mcp": "^0.8"
      },
      "require-dev": {
          "orchestra/testbench": "^10.0 || ^11.0",
          "pestphp/pest": "^4.0",
          "laravel/pint": "^1.13",
          "phpstan/phpstan": "^2.0"
      },
      "suggest": {
          "laravel/passport": "Required for OAuth mode (claude.ai / ChatGPT connectors)."
      },
      "autoload": {
          "psr-4": {
              "Danielgnh\\StatamicMcp\\": "src/"
          }
      },
      "autoload-dev": {
          "psr-4": {
              "Danielgnh\\StatamicMcp\\Tests\\": "tests/"
          }
      },
      "scripts": {
          "format": "pint",
          "test": "pest"
      },
      "config": {
          "allow-plugins": {
              "pestphp/pest-plugin": true
          },
          "sort-packages": true
      },
      "extra": {
          "statamic": {
              "name": "Statamic MCP",
              "description": "MCP server for Statamic content"
          },
          "laravel": {
              "providers": [
                  "Danielgnh\\StatamicMcp\\ServiceProvider"
              ]
          }
      },
      "minimum-stability": "stable",
      "prefer-stable": true
  }
  ```

  Two entries are load-bearing for the test harness (verified against `AddonTestCase::getEnvironmentSetUp()` in statamic/cms 6.x): `autoload.psr-4."Danielgnh\\StatamicMcp\\"` (the test case reads it to fake the addon Manifest) and `extra.statamic` (without it the Manifest entry has no statamic metadata). `config.allow-plugins."pestphp/pest-plugin": true` prevents the interactive plugin prompt during `composer install`.

- [ ] **Step 3: Write `pint.json`, `phpunit.xml`, and `.gitignore`**

  `pint.json`:

  ```json
  {
      "preset": "laravel",
      "exclude": [
          "tests/__fixtures__"
      ]
  }
  ```

  `phpunit.xml` (Pest 4 runs on PHPUnit 12; the static `APP_KEY` keeps encryption-dependent Laravel internals working in testbench — it is a test-only key, not a secret):

  ```xml
  <?xml version="1.0" encoding="UTF-8"?>
  <phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
           xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
           bootstrap="vendor/autoload.php"
           colors="true"
           cacheDirectory=".phpunit.cache">
      <testsuites>
          <testsuite name="Package">
              <directory>tests</directory>
          </testsuite>
      </testsuites>
      <php>
          <env name="APP_ENV" value="testing"/>
          <env name="APP_KEY" value="base64:x2eZQBpDzUYbmAOZYBy1JZBPMuXCsyvNlwPPYlpxKh8="/>
      </php>
  </phpunit>
  ```

  `.gitignore` (composer.lock is ignored — library convention, the CI matrix tests lowest/highest dependency sets; dev-null contents are ignored but the keep file is force-tracked):

  ```gitignore
  /vendor
  /composer.lock
  /.phpunit.cache
  .phpunit.result.cache
  /tests/__fixtures__/dev-null/*
  !/tests/__fixtures__/dev-null/.gitkeep
  /.idea
  .DS_Store
  ```

- [ ] **Step 4: Install dependencies and validate the manifest**

  ```bash
  composer validate
  composer install
  ```

  Expected `composer validate` output: `./composer.json is valid`.

  Expected `composer install` output (exact versions vary; exit code must be 0):

  ```
  No composer.lock file present. Updating dependencies to latest instead of installing from lock file.
  Loading composer repositories with package information
  Updating dependencies
  Lock file operations: ~115 installs, 0 updates, 0 removals
    ...
    - Installing statamic/cms (v6.x.x)
    - Installing laravel/mcp (v0.8.x)
    - Installing orchestra/testbench (...)
    - Installing pestphp/pest (v4.x.x)
    ...
  Generating optimized autoload files
  ```

  Verify the two runtime pins resolved to the right majors: the install log must show `statamic/cms (v6.` and `laravel/mcp (v0.8.`.

- [ ] **Step 5: Run the formatter**

  ```bash
  composer format
  ```

  Expected: exits 0. There are no PHP files yet, so Pint reports zero inspected files (e.g. `PASS ... 0 files`) — that is correct at this point; PHP files arrive in Task 2.

- [ ] **Step 6: Commit**

  ```bash
  git add -A
  git commit -m "chore: scaffold danielgnh/statamic-mcp package

  composer manifest (statamic/cms ^6.0, laravel/mcp ^0.8, pest 4),
  pint + phpunit config, gitignore, fixtures dev-null keep file.

  Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
  ```

### Task 2: Test infrastructure (AddonTestCase harness, fixtures, boot smoke test)

**Files:**
- Create: `src/ServiceProvider.php` (minimal boot shell — Task 3 replaces it wholesale with the full contract version)
- Create: `tests/Pest.php`
- Create: `tests/TestCase.php`
- Create: `tests/Support/Fixtures.php`
- Test: `tests/Feature/BootTest.php`
- Test: `tests/Feature/FixturesTest.php`

- [ ] **Step 1: Write the test harness and the failing boot smoke test**

  `tests/Pest.php` (verbatim from contracts):

  ```php
  <?php

  use Danielgnh\StatamicMcp\Tests\TestCase;

  uses(TestCase::class)->in(__DIR__);
  ```

  `tests/TestCase.php` (verbatim from contracts — the property name `$addonServiceProvider` and the trait FQCN `Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk` are both verified against statamic/cms 6.x source):

  ```php
  <?php

  namespace Danielgnh\StatamicMcp\Tests;

  use Danielgnh\StatamicMcp\ServiceProvider;
  use Statamic\Testing\AddonTestCase;
  use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

  abstract class TestCase extends AddonTestCase
  {
      use PreventsSavingStacheItemsToDisk;

      protected string $addonServiceProvider = ServiceProvider::class;
  }
  ```

  `tests/Feature/BootTest.php`:

  ```php
  <?php

  use Danielgnh\StatamicMcp\ServiceProvider;
  use Statamic\Facades\Addon;

  it('boots the addon service provider in testbench', function () {
      expect($this->app->providerIsLoaded(ServiceProvider::class))->toBeTrue();
  });

  it('registers the addon with statamic via the faked manifest', function () {
      expect(Addon::get('danielgnh/statamic-mcp'))->not->toBeNull();
  });
  ```

  Why the second test matters (verified against statamic/cms 6.x source): `AddonServiceProvider::boot()` defers everything into a `Statamic::booted()` callback that returns early when `$this->getAddon()` resolves to nothing. `AddonTestCase` fakes the addon Manifest from our `composer.json`, and `Addon::get('danielgnh/statamic-mcp')` proves that wiring works — it is the precondition for `bootAddon()` (Task 3's config merge and route registration) ever running.

- [ ] **Step 2: Run the smoke test — expect failure (ServiceProvider does not exist yet)**

  ```bash
  vendor/bin/pest tests/Feature/BootTest.php
  ```

  Expected output: both tests error during application boot, because `AddonTestCase::getPackageProviders()` registers `Danielgnh\StatamicMcp\ServiceProvider`, which does not exist yet:

  ```
   FAIL  Tests\Feature\BootTest
  ⨯ it boots the addon service provider in testbench
  ⨯ it registers the addon with statamic via the faked manifest

  Error: Class "Danielgnh\StatamicMcp\ServiceProvider" not found

  Tests:    2 failed
  ```

- [ ] **Step 3: Create the minimal `src/ServiceProvider.php`**

  ```php
  <?php

  namespace Danielgnh\StatamicMcp;

  use Statamic\Providers\AddonServiceProvider;

  class ServiceProvider extends AddonServiceProvider
  {
      protected $config = false;

      public function bootAddon()
      {
          //
      }
  }
  ```

  Two deliberate choices:
  - `protected $config = false;` — Statamic's `AddonServiceProvider` defaults `$config = true`, which boots slug-based config conventions (`config/statamic-mcp.php` merged under a `statamic-mcp` key). We opt out because Task 3 merges under `statamic.mcp` and publishes to `config/statamic/mcp.php` ourselves (the first-party `config/statamic/api.php` idiom).
  - This class is intentionally a shell. Task 3 replaces it wholesale with the full contract version (config merge + permission registration + MCP route). It exists now only so the test harness can boot — `AddonTestCase` cannot construct without a real provider class.

- [ ] **Step 4: Run the smoke test — expect pass**

  ```bash
  vendor/bin/pest tests/Feature/BootTest.php
  ```

  Expected output:

  ```
   PASS  Tests\Feature\BootTest
  ✓ it boots the addon service provider in testbench
  ✓ it registers the addon with statamic via the faked manifest

  Tests:    2 passed
  ```

- [ ] **Step 5: Write the failing fixtures test**

  `tests/Feature/FixturesTest.php`:

  ```php
  <?php

  use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
  use Statamic\Facades\Collection;
  use Statamic\Facades\GlobalSet;
  use Statamic\Facades\Taxonomy;

  it('builds content fixtures in the sandboxed stache', function () {
      Fixtures::site();
      Fixtures::tags();
      Fixtures::blog();
      Fixtures::settings();

      expect(Collection::handles()->all())->toContain('blog')
          ->and(Taxonomy::handles()->all())->toContain('tags')
          ->and(GlobalSet::findByHandle('settings'))->not->toBeNull();
  });

  it('creates users with access mcp plus the given permissions', function () {
      Fixtures::site();

      $user = Fixtures::makeUser('view blog entries');

      expect($user->hasPermission('access mcp'))->toBeTrue()
          ->and($user->hasPermission('view blog entries'))->toBeTrue()
          ->and($user->hasPermission('edit blog entries'))->toBeFalse()
          ->and($user->isSuper())->toBeFalse();
  });

  it('creates super users', function () {
      Fixtures::site();

      expect(Fixtures::makeSuper()->isSuper())->toBeTrue();
  });
  ```

  Note: `hasPermission()` on a file-repo user checks the raw permission strings stored on the user's roles — it does not require the permission to be registered via `Permission::extend()` (that registration, added in Task 3 and tested in Task 4, is what makes it appear in the CP roles UI).

- [ ] **Step 6: Run the fixtures test — expect failure (Fixtures class missing)**

  ```bash
  vendor/bin/pest tests/Feature/FixturesTest.php
  ```

  Expected output:

  ```
   FAIL  Tests\Feature\FixturesTest
  ⨯ it builds content fixtures in the sandboxed stache
  ⨯ it creates users with access mcp plus the given permissions
  ⨯ it creates super users

  Error: Class "Danielgnh\StatamicMcp\Tests\Support\Fixtures" not found

  Tests:    3 failed
  ```

- [ ] **Step 7: Create `tests/Support/Fixtures.php` (verbatim from contracts — do not restyle)**

  ```php
  <?php

  namespace Danielgnh\StatamicMcp\Tests\Support;

  use Illuminate\Support\Str;
  use Statamic\Contracts\Auth\User as UserContract;
  use Statamic\Facades\Blueprint;
  use Statamic\Facades\Collection;
  use Statamic\Facades\GlobalSet;
  use Statamic\Facades\Role;
  use Statamic\Facades\Site;
  use Statamic\Facades\Taxonomy;
  use Statamic\Facades\User;

  class Fixtures
  {
      public static function site(): void
      {
          Site::setSites([
              'en' => ['name' => 'English', 'url' => '/', 'locale' => 'en_US'],
          ]);
      }

      public static function multisite(): void
      {
          Site::setSites([
              'en' => ['name' => 'English', 'url' => '/', 'locale' => 'en_US'],
              'de' => ['name' => 'German', 'url' => '/de/', 'locale' => 'de_DE'],
          ]);

          config(['statamic.system.multisite' => true]); // enables 'access {site} site' permissions
      }

      // Call tags() before blog(): the article blueprint's 'topic' field targets the tags taxonomy.
      public static function blog(): void
      {
          tap(
              Collection::make('blog')
                  ->title('Blog')
                  ->sites(Site::all()->map->handle()->values()->all())
                  ->routes('/blog/{slug}')
          )->save();

          Blueprint::makeFromFields([
              'title' => ['type' => 'text', 'validate' => 'required'],
              'content' => ['type' => 'bard'],
              'hero_image' => ['type' => 'text'],
              'topic' => ['type' => 'terms', 'taxonomies' => ['tags'], 'max_items' => 1],
          ])->setHandle('article')->setNamespace('collections.blog')->save();
      }

      public static function tags(): void
      {
          tap(Taxonomy::make('tags')->title('Tags'))->save();

          Blueprint::makeFromFields([
              'title' => ['type' => 'text', 'validate' => 'required'],
          ])->setHandle('tag')->setNamespace('taxonomies.tags')->save();
      }

      public static function settings(): void
      {
          Blueprint::makeFromFields([
              'site_name' => ['type' => 'text'],
              'footer_text' => ['type' => 'text'],
          ])->setHandle('settings')->setNamespace('globals')->save();

          $set = GlobalSet::make('settings')->title('Settings');
          $set->save();

          $set->makeLocalization(Site::default()->handle())
              ->data(['site_name' => 'Acme'])
              ->save();
      }

      /**
       * A user with 'access mcp' plus the given Statamic permissions,
       * via a dedicated throwaway role (spec: restricted agent = restricted role).
       */
      public static function makeUser(string ...$permissions): UserContract
      {
          $handle = 'role_'.Str::lower(Str::random(8));

          $role = Role::make($handle)->title('Test Role')->addPermission('access mcp');

          foreach ($permissions as $permission) {
              $role->addPermission($permission);
          }

          $role->save();

          return tap(
              User::make()->email(Str::lower(Str::random(8)).'@site.test')->assignRole($handle)
          )->save();
      }

      public static function makeSuper(): UserContract
      {
          return tap(
              User::make()->email(Str::lower(Str::random(8)).'@site.test')->makeSuper()
          )->save();
      }
  }
  ```

  All `->save()` calls are disk-safe: `PreventsSavingStacheItemsToDisk` (wired in Task 2's TestCase) redirects every Stache write into `tests/__fixtures__/dev-null`, which is wiped between tests. Every API here is v6-verified: `Site::setSites()`, `Blueprint::makeFromFields()`, `Role::addPermission()`, `GlobalSet::makeLocalization()` (never `addLocalization()` — removed in v6).

- [ ] **Step 8: Run the whole suite — expect pass**

  ```bash
  vendor/bin/pest
  ```

  Expected output:

  ```
   PASS  Tests\Feature\BootTest
  ✓ it boots the addon service provider in testbench
  ✓ it registers the addon with statamic via the faked manifest

   PASS  Tests\Feature\FixturesTest
  ✓ it builds content fixtures in the sandboxed stache
  ✓ it creates users with access mcp plus the given permissions
  ✓ it creates super users

  Tests:    5 passed
  ```

- [ ] **Step 9: Format**

  ```bash
  composer format
  ```

  Expected: exits 0, `PASS` with the inspected file count (5 PHP files). If Pint rewrites anything, re-run `vendor/bin/pest` (must stay 5 passed) before committing.

- [ ] **Step 10: Commit**

  ```bash
  git add -A
  git commit -m "test: add addon test harness and content fixtures

  Pest 4 on Statamic AddonTestCase with PreventsSavingStacheItemsToDisk,
  boot smoke test, and reusable site/collection/taxonomy/global/user fixtures.

  Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
  ```

### Task 3: config/mcp.php + full ServiceProvider (route registration, config gates)

**Files:**
- Create: `config/mcp.php`
- Modify: `src/ServiceProvider.php` (replace the Task 2 shell wholesale with the contract version)
- Create: `tests/DisabledMcpTestCase.php`
- Test: `tests/Feature/McpRouteTest.php`
- Test: `tests/Feature/McpRouteDisabledTest.php`

- [ ] **Step 1: Write the failing route + config tests**

  `tests/Feature/McpRouteTest.php`:

  ```php
  <?php

  use Danielgnh\StatamicMcp\Middleware\AuthenticateMcpToken;
  use Danielgnh\StatamicMcp\Middleware\EnsureMcpPermission;
  use Illuminate\Support\Facades\Route;

  function mcpPostRoute(): ?\Illuminate\Routing\Route
  {
      return collect(Route::getRoutes()->getRoutes())
          ->first(fn ($route) => $route->uri() === 'mcp/statamic'
              && in_array('POST', $route->methods(), true));
  }

  it('registers the mcp route when enabled', function () {
      expect(mcpPostRoute())->not->toBeNull();
  });

  it('applies configured middleware, then token auth, then the permission gate', function () {
      $middleware = mcpPostRoute()->middleware();

      expect($middleware)->toContain('throttle:60,1')
          ->toContain(AuthenticateMcpToken::class)
          ->toContain(EnsureMcpPermission::class);

      // spec §5: configured middleware is PREPENDED to auth; 'access mcp' is checked AFTER auth.
      expect(array_search('throttle:60,1', $middleware, true))
          ->toBeLessThan(array_search(AuthenticateMcpToken::class, $middleware, true));

      expect(array_search(AuthenticateMcpToken::class, $middleware, true))
          ->toBeLessThan(array_search(EnsureMcpPermission::class, $middleware, true));
  });

  it('merges default config under statamic.mcp', function () {
      expect(config('statamic.mcp.enabled'))->toBeTrue()
          ->and(config('statamic.mcp.route'))->toBe('mcp/statamic')
          ->and(config('statamic.mcp.auth'))->toBe('token')
          ->and(config('statamic.mcp.read_only'))->toBeFalse()
          ->and(config('statamic.mcp.deletes'))->toBeFalse()
          ->and(config('statamic.mcp.resources.collections'))->toBeTrue()
          ->and(config('statamic.mcp.per_page'))->toBe(25);
  });
  ```

  Why the helper filters on the POST method (verified against laravel/mcp `Registrar::web()` source): `Mcp::web()` registers TWO routes at the same URI — a GET route that answers 405 (per the MCP streamable-HTTP spec) and the POST route that carries the server and our middleware. Grabbing "the first route with that URI" could return the bare GET route and make the middleware assertions flaky; always select the POST one.

  The `AuthenticateMcpToken` and `EnsureMcpPermission` imports refer to classes that do not exist yet — that is fine: `X::class` is resolved by PHP at compile time without autoloading, so these tests only ever compare strings.

- [ ] **Step 2: Run — expect failure (no config, no route yet)**

  ```bash
  vendor/bin/pest tests/Feature/McpRouteTest.php
  ```

  Expected output:

  ```
   FAIL  Tests\Feature\McpRouteTest
  ⨯ it registers the mcp route when enabled
      Failed asserting that null is not null.
  ⨯ it applies configured middleware, then token auth, then the permission gate
      Error: Call to a member function middleware() on null
  ⨯ it merges default config under statamic.mcp
      Failed asserting that null is true.

  Tests:    3 failed
  ```

- [ ] **Step 3: Create `config/mcp.php` (verbatim from contracts / spec §7 — do not restyle)**

  ```php
  <?php

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

- [ ] **Step 4: Replace `src/ServiceProvider.php` wholesale with the contract version (verbatim — do not restyle)**

  ```php
  <?php

  namespace Danielgnh\StatamicMcp;

  use Danielgnh\StatamicMcp\Console\Doctor;
  use Danielgnh\StatamicMcp\Console\IssueToken;
  use Danielgnh\StatamicMcp\Console\ListTokens;
  use Danielgnh\StatamicMcp\Console\RevokeToken;
  use Danielgnh\StatamicMcp\Middleware\AuthenticateMcpToken;
  use Danielgnh\StatamicMcp\Middleware\EnsureMcpPermission;
  use Danielgnh\StatamicMcp\Middleware\EnsureOAuthConfigured;
  use Laravel\Mcp\Facades\Mcp;
  use Statamic\Facades\Permission;
  use Statamic\Providers\AddonServiceProvider;
  use Throwable;

  class ServiceProvider extends AddonServiceProvider
  {
      protected $config = false;

      protected $commands = [
          IssueToken::class,
          ListTokens::class,
          RevokeToken::class,
          Doctor::class,
      ];

      public function bootAddon()
      {
          $this->mergeConfigFrom(__DIR__.'/../config/mcp.php', 'statamic.mcp');

          $this->publishes([
              __DIR__.'/../config/mcp.php' => config_path('statamic/mcp.php'),
          ], 'statamic-mcp-config');

          $this->registerPermission();

          if (! config('statamic.mcp.enabled')) {
              return;
          }

          try {
              $this->registerMcpRoutes();
          } catch (Throwable $e) {
              report($e); // misconfiguration must never brick the host site (spec §5)
          }
      }

      protected function registerPermission(): void
      {
          Permission::extend(function () {
              Permission::group('mcp', 'MCP', function () {
                  Permission::register('access mcp')->label('Access MCP');
              });
          });
      }

      protected function registerMcpRoutes(): void
      {
          $oauth = config('statamic.mcp.auth') === 'oauth';

          Mcp::web(config('statamic.mcp.route'), Server::class)->middleware([
              ...config('statamic.mcp.middleware', []),
              ...($oauth
                  // Preflight answers 503-with-remedy BEFORE auth:api can throw on a missing guard.
                  ? [EnsureOAuthConfigured::class, 'auth:api']
                  : [AuthenticateMcpToken::class]),
              EnsureMcpPermission::class, // 'access mcp', checked after auth in both modes (spec §5)
          ]);

          if ($oauth && class_exists(\Laravel\Passport\Passport::class)) {
              Mcp::oauthRoutes(); // hard-requires Passport — guarded so bootAddon never throws
          }
      }
  }
  ```

  Read this before assuming it is broken — the provider references classes that later tasks create, **by design**:

  - `Server::class` (tools section), `AuthenticateMcpToken::class` (token-auth section), `EnsureOAuthConfigured::class` (OAuth section), `EnsureMcpPermission::class` (Task 4), and the four `Console\*` commands (token-commands section). Every reference is either a `::class` compile-time constant or a middleware string in an array — PHP loads none of these classes until a request actually runs the middleware pipeline, the server is instantiated, or Artisan resolves commands. The Task 3 and Task 4 tests do none of those things.
  - **Constraint until the Console classes land:** do not run `php artisan ...` in the package or call `$this->artisan(...)` in tests — Statamic's `AddonServiceProvider::bootCommands()` registers `$commands` with `Artisan::starting`, and instantiating Artisan would then fatal on the missing command classes. The token-commands task removes this constraint. (Plain `vendor/bin/pest` never instantiates Artisan.)
  - The `Mcp` facade resolves without laravel/mcp's own service provider being registered (AddonTestCase only registers Statamic + Inertia + our provider): its facade accessor is the concrete `Laravel\Mcp\Server\Registrar` class, which the container auto-wires. Verified against laravel/mcp source.
  - `mergeConfigFrom()` gives precedence to values already set on the repository — that is what lets tests (and host apps) override `statamic.mcp.enabled` before boot, and what makes the disabled-route test below work.

- [ ] **Step 5: Run — expect pass**

  ```bash
  vendor/bin/pest tests/Feature/McpRouteTest.php
  ```

  Expected output:

  ```
   PASS  Tests\Feature\McpRouteTest
  ✓ it registers the mcp route when enabled
  ✓ it applies configured middleware, then token auth, then the permission gate
  ✓ it merges default config under statamic.mcp

  Tests:    3 passed
  ```

- [ ] **Step 6: Add the disabled-switch test (dedicated test case, config set before boot)**

  The kill switch is read once, in `bootAddon()` — flipping the config inside a test body is too late. Orchestra Testbench calls `getEnvironmentSetUp($app)` while resolving the application, BEFORE package providers register/boot (verified: `AddonTestCase` itself overrides that same hook and calls `parent::`), so a dedicated test case is the correct lever.

  `tests/DisabledMcpTestCase.php`:

  ```php
  <?php

  namespace Danielgnh\StatamicMcp\Tests;

  class DisabledMcpTestCase extends TestCase
  {
      protected function getEnvironmentSetUp($app)
      {
          parent::getEnvironmentSetUp($app);

          $app['config']->set('statamic.mcp.enabled', false);
      }
  }
  ```

  `tests/Feature/McpRouteDisabledTest.php` (the file-level `uses()` overrides the folder-level binding from `tests/Pest.php` for this file only):

  ```php
  <?php

  use Danielgnh\StatamicMcp\Tests\DisabledMcpTestCase;
  use Illuminate\Support\Facades\Route;

  uses(DisabledMcpTestCase::class);

  it('does not register the mcp route when disabled', function () {
      $route = collect(Route::getRoutes()->getRoutes())
          ->first(fn ($route) => $route->uri() === 'mcp/statamic');

      expect($route)->toBeNull();
  });
  ```

  No POST filter here on purpose: when disabled, NO route (GET or POST) may exist at that URI.

- [ ] **Step 7: Run the whole suite — expect pass**

  ```bash
  vendor/bin/pest
  ```

  Expected output ends with:

  ```
   PASS  Tests\Feature\McpRouteDisabledTest
  ✓ it does not register the mcp route when disabled

  Tests:    9 passed
  ```

  (2 boot + 3 fixtures + 3 route + 1 disabled.)

- [ ] **Step 8: Format**

  ```bash
  composer format
  ```

  Expected: exits 0. If Pint rewrites anything, re-run `vendor/bin/pest` (must stay 9 passed) before committing.

- [ ] **Step 9: Commit**

  ```bash
  git add -A
  git commit -m "feat: register mcp route with config-driven middleware and kill switch

  config/mcp.php merged under statamic.mcp, Mcp::web route mounted from
  config, middleware order: configured -> auth mode -> access mcp gate,
  enabled=false leaves no route registered.

  Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
  ```

### Task 4: 'access mcp' permission + EnsureMcpPermission middleware gate

**Files:**
- Create: `src/Middleware/EnsureMcpPermission.php`
- Test: `tests/Feature/AccessMcpPermissionTest.php`
- Test: `tests/Feature/EnsureMcpPermissionTest.php`

Spec §5: exactly ONE custom permission in the whole package — `access mcp` — registered via `Permission::extend()`, checked after auth in both modes by a route middleware. Everything else is Statamic's own permission strings. Task 3's verbatim provider already ships the registration; this task locks it in with tests and adds the middleware that enforces it.

- [ ] **Step 1: Write the permission-registration test (passes immediately — it covers code Task 3 shipped)**

  `tests/Feature/AccessMcpPermissionTest.php`:

  ```php
  <?php

  use Statamic\Facades\Permission;

  it('registers the access mcp permission in the mcp group', function () {
      // Registration is lazy: Permission::extend() only queues a callback.
      // boot() runs core permissions + all extensions — the CP triggers this
      // the same way before rendering the roles UI (verified 6.x source).
      Permission::boot();

      $permission = Permission::get('access mcp');

      expect($permission)->not->toBeNull()
          ->and($permission->label())->toBe('Access MCP');
  });
  ```

  Why `Permission::boot()` is required (verified against `Statamic\Auth\Permissions` 6.x source): `Permission::get()`/`all()` only see permissions that have been registered, and extension callbacks queued via `extend()` run inside `boot()`. Without the `boot()` call, `Permission::get('access mcp')` returns null even though the provider did everything right.

- [ ] **Step 2: Run — expect pass (this is coverage for Task 3's provider, not new code)**

  ```bash
  vendor/bin/pest tests/Feature/AccessMcpPermissionTest.php
  ```

  Expected output:

  ```
   PASS  Tests\Feature\AccessMcpPermissionTest
  ✓ it registers the access mcp permission in the mcp group

  Tests:    1 passed
  ```

  If this FAILS, the Task 3 ServiceProvider was not copied verbatim — check `registerPermission()` runs before the `enabled` early-return in `bootAddon()` (it must be registered even when the route is disabled, so admins can pre-configure roles).

- [ ] **Step 3: Write the failing middleware test**

  `tests/Feature/EnsureMcpPermissionTest.php`:

  ```php
  <?php

  use Danielgnh\StatamicMcp\Middleware\EnsureMcpPermission;
  use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
  use Illuminate\Http\Request;
  use Statamic\Facades\Role;
  use Statamic\Facades\User;

  function ensureMcpPermission(?\Statamic\Contracts\Auth\User $user)
  {
      $request = Request::create('/mcp/statamic', 'POST');
      $request->setUserResolver(fn () => $user);

      return (new EnsureMcpPermission)->handle($request, fn () => response()->json(['ok' => true]));
  }

  it('rejects requests with no authenticated user', function () {
      $response = ensureMcpPermission(null);

      expect($response->getStatusCode())->toBe(403)
          ->and($response->getData(true)['error'])
          ->toBe("requires 'access mcp' — grant it to a role of the connected user in the Control Panel");
  });

  it('rejects users whose roles lack access mcp', function () {
      Fixtures::site();

      Role::make('editor_without_mcp')->title('Editor')->addPermission('view blog entries')->save();

      $user = tap(User::make()->email('nomcp@site.test')->assignRole('editor_without_mcp'))->save();

      $response = ensureMcpPermission($user);

      expect($response->getStatusCode())->toBe(403)
          ->and($response->getData(true)['error'])
          ->toBe("requires 'access mcp' — grant it to a role of nomcp@site.test in the Control Panel");
  });

  it('passes users granted access mcp through to the server', function () {
      Fixtures::site();

      $user = Fixtures::makeUser(); // role with 'access mcp' only

      $response = ensureMcpPermission($user);

      expect($response->getStatusCode())->toBe(200)
          ->and($response->getData(true))->toBe(['ok' => true]);
  });

  it('passes super users without an explicit grant', function () {
      Fixtures::site();

      $response = ensureMcpPermission(Fixtures::makeSuper());

      expect($response->getStatusCode())->toBe(200);
  });
  ```

  Two deliberate choices:
  - The middleware is exercised directly (`handle($request, $next)`) rather than over HTTP, because in token mode the route's middleware pipeline starts with `AuthenticateMcpToken`, which does not exist until the token-auth task. Direct invocation tests exactly this middleware's behavior; the full request-level 401/403 pipeline is covered end-to-end in the token-auth section's tests.
  - Assertions use `$response->getData(true)` instead of `assertSee`-style string matching on `getContent()`, because Laravel's `response()->json()` hex-escapes apostrophes (`JSON_HEX_APOS`) — matching the raw body against `requires 'access mcp'` would fail even when the message is correct.

- [ ] **Step 4: Run — expect failure (middleware class missing)**

  ```bash
  vendor/bin/pest tests/Feature/EnsureMcpPermissionTest.php
  ```

  Expected output:

  ```
   FAIL  Tests\Feature\EnsureMcpPermissionTest
  ⨯ it rejects requests with no authenticated user
  ⨯ it rejects users whose roles lack access mcp
  ⨯ it passes users granted access mcp through to the server
  ⨯ it passes super users without an explicit grant

  Error: Class "Danielgnh\StatamicMcp\Middleware\EnsureMcpPermission" not found

  Tests:    4 failed
  ```

- [ ] **Step 5: Create `src/Middleware/EnsureMcpPermission.php` (verbatim from contracts — do not restyle)**

  ```php
  <?php

  namespace Danielgnh\StatamicMcp\Middleware;

  use Closure;
  use Illuminate\Http\Request;
  use Statamic\Facades\User;
  use Symfony\Component\HttpFoundation\Response;

  class EnsureMcpPermission
  {
      public function handle(Request $request, Closure $next): Response
      {
          $user = $request->user() ? User::fromUser($request->user()) : null;

          if (! $user || (! $user->isSuper() && ! $user->hasPermission('access mcp'))) {
              return response()->json([
                  'error' => sprintf(
                      "requires 'access mcp' — grant it to a role of %s in the Control Panel",
                      $user?->email() ?? 'the connected user',
                  ),
              ], 403);
          }

          return $next($request);
      }
  }
  ```

  `User::fromUser()` is the mode-agnostic normalization from spec §5: in token mode `$request->user()` is already a Statamic user (pass-through); in OAuth mode it is the Eloquent model, and `fromUser()` maps it to the Statamic user so `hasPermission()` — the canonical API for Statamic permission strings — works identically in both modes. Supers auto-pass (`isSuper()` short-circuit) per Statamic convention.

- [ ] **Step 6: Run the whole suite — expect pass**

  ```bash
  vendor/bin/pest
  ```

  Expected output ends with:

  ```
   PASS  Tests\Feature\EnsureMcpPermissionTest
  ✓ it rejects requests with no authenticated user
  ✓ it rejects users whose roles lack access mcp
  ✓ it passes users granted access mcp through to the server
  ✓ it passes super users without an explicit grant

  Tests:    14 passed
  ```

  (2 boot + 3 fixtures + 3 route + 1 disabled + 1 permission + 4 middleware.)

- [ ] **Step 7: Format**

  ```bash
  composer format
  ```

  Expected: exits 0. If Pint rewrites anything, re-run `vendor/bin/pest` (must stay 14 passed) before committing.

- [ ] **Step 8: Commit**

  ```bash
  git add -A
  git commit -m "feat: add access mcp permission and middleware gate

  The package's only custom permission, registered via Permission::extend
  in an MCP group; EnsureMcpPermission rejects unauthenticated requests and
  users lacking the grant with a remedy naming the exact permission, and
  lets supers pass. Runs after auth in both token and oauth modes.

  Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
  ```


### Task 5: TokenRepository — hashed YAML token store

*Depends on Tasks 1–4 (composer.json, ServiceProvider, config, `tests/TestCase.php`, `tests/Pest.php`, `tests/Support/Fixtures.php` all exist). This task builds the storage half of token auth: `storage/statamic/mcp/tokens.yaml` holding SHA-256 hashes only — the plaintext exists exactly once, inside the returned `PlainToken` value object (spec §5 Mode 1).*

**Files:**
- Create: `src/Tokens/PlainToken.php`
- Create: `src/Tokens/TokenRepository.php`
- Test: `tests/Tokens/TokenRepositoryTest.php`

- [ ] **Step 1: Write the failing TokenRepository test**

Create `tests/Tokens/TokenRepositoryTest.php`:

```php
<?php

use Danielgnh\StatamicMcp\Tokens\PlainToken;
use Danielgnh\StatamicMcp\Tokens\TokenRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Statamic\Facades\User;
use Statamic\Facades\YAML;

beforeEach(function () {
    // Testbench's storage dir survives between tests — start clean every time.
    File::delete(storage_path('statamic/mcp/tokens.yaml'));

    $this->repo = app(TokenRepository::class);
    $this->user = tap(User::make()->email('claude@site.test'))->save();
});

it('issues a token in the mcp_{tokenId}_{secret} format', function () {
    $plain = $this->repo->issue($this->user);

    expect($plain)->toBeInstanceOf(PlainToken::class);

    $parts = explode('_', $plain->token, 3);

    expect($parts)->toHaveCount(3)
        ->and($parts[0])->toBe('mcp')
        ->and($parts[1])->toBe($plain->tokenId)
        ->and(strlen($parts[2]))->toBe(40)
        ->and($plain->userId)->toBe((string) $this->user->id())
        ->and($plain->expiresAt)->toBeNull();
});

it('stores only the sha-256 hash in tokens.yaml — never the plaintext secret', function () {
    $plain = $this->repo->issue($this->user, 'laptop');

    $secret = explode('_', $plain->token, 3)[2];

    $raw = File::get(storage_path('statamic/mcp/tokens.yaml'));

    expect(str_contains($raw, $secret))->toBeFalse()
        ->and(str_contains($raw, $plain->token))->toBeFalse();

    $parsed = YAML::parse($raw);

    expect($parsed)->toHaveKey($plain->tokenId)
        ->and($parsed[$plain->tokenId]['hash'])->toBe(hash('sha256', $secret))
        ->and($parsed[$plain->tokenId]['user'])->toBe((string) $this->user->id())
        ->and($parsed[$plain->tokenId]['name'])->toBe('laptop')
        ->and($parsed[$plain->tokenId]['expires_at'])->toBeNull()
        ->and($parsed[$plain->tokenId]['created_at'])->not->toBeNull();
});

it('records an iso-8601 expiry when issued with expires days', function () {
    $this->travelTo(Carbon::parse('2026-07-09T12:00:00Z'));

    $plain = $this->repo->issue($this->user, null, 30);

    expect($plain->expiresAt->toIso8601String())->toBe('2026-08-08T12:00:00+00:00');

    $record = $this->repo->find($plain->tokenId);

    expect($record['expires_at'])->toBe('2026-08-08T12:00:00+00:00');
});

it('finds a token record by id', function () {
    $plain = $this->repo->issue($this->user, 'laptop');

    $record = $this->repo->find($plain->tokenId);

    expect($record)->not->toBeNull()
        ->and($record['user'])->toBe((string) $this->user->id())
        ->and($record['name'])->toBe('laptop');
});

it('returns null for an unknown token id', function () {
    expect($this->repo->find('doesnotexist'))->toBeNull();
});

it('revokes a token and rewrites the file without it, leaving others intact', function () {
    $keep = $this->repo->issue($this->user, 'keep');
    $kill = $this->repo->issue($this->user, 'kill');

    expect($this->repo->revoke($kill->tokenId))->toBeTrue()
        ->and($this->repo->find($kill->tokenId))->toBeNull()
        ->and($this->repo->find($keep->tokenId))->not->toBeNull();

    $raw = File::get(storage_path('statamic/mcp/tokens.yaml'));

    expect(str_contains($raw, $kill->tokenId))->toBeFalse()
        ->and(str_contains($raw, $keep->tokenId))->toBeTrue();
});

it('returns false when revoking an unknown token id', function () {
    expect($this->repo->revoke('doesnotexist'))->toBeFalse();
});

it('returns all issued tokens keyed by token id', function () {
    $a = $this->repo->issue($this->user, 'a');
    $b = $this->repo->issue($this->user, 'b');

    $all = $this->repo->all();

    expect($all)->toHaveCount(2)
        ->and($all)->toHaveKeys([$a->tokenId, $b->tokenId]);
});
```

- [ ] **Step 2: Run the test — expect 8 failures (class does not exist)**

```
vendor/bin/pest tests/Tokens/TokenRepositoryTest.php
```

Expected output — every test errors identically:

```
   FAIL  Tests\Tokens\TokenRepositoryTest
  ⨯ it issues a token in the mcp_{tokenId}_{secret} format
    Error: Class "Danielgnh\StatamicMcp\Tokens\TokenRepository" not found
  ...
  Tests:    8 failed
```

- [ ] **Step 3: Create PlainToken and TokenRepository (verbatim from the shared contracts)**

Create `src/Tokens/PlainToken.php`:

```php
<?php

namespace Danielgnh\StatamicMcp\Tokens;

use Illuminate\Support\Carbon;

final readonly class PlainToken
{
    public function __construct(
        public string $tokenId,
        public string $token, // full "mcp_{tokenId}_{secret}" — display exactly once, never persisted
        public string $userId,
        public ?string $name,
        public ?Carbon $expiresAt,
    ) {
    }
}
```

Create `src/Tokens/TokenRepository.php`. Token format `mcp_{tokenId}_{secret}`; `Str::random()` is alphanumeric so neither part ever contains `_` — positional parsing is safe. SHA-256 only; plaintext exists only inside the returned `PlainToken`:

```php
<?php

namespace Danielgnh\StatamicMcp\Tokens;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Statamic\Contracts\Auth\User;
use Statamic\Facades\YAML;

class TokenRepository
{
    public function issue(User $user, ?string $name = null, ?int $expiresDays = null): PlainToken
    {
        $tokenId = Str::lower(Str::random(12));
        $secret = Str::random(40);

        $expiresAt = $expiresDays ? Carbon::now()->addDays($expiresDays) : null;

        $tokens = $this->read();

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
    }

    /**
     * @return array<string, array{user: string, name: ?string, hash: string, created_at: string, expires_at: ?string}>
     */
    public function all(): array
    {
        return $this->read();
    }

    /**
     * @return array{user: string, name: ?string, hash: string, created_at: string, expires_at: ?string}|null
     */
    public function find(string $tokenId): ?array
    {
        return $this->read()[$tokenId] ?? null;
    }

    public function revoke(string $tokenId): bool
    {
        $tokens = $this->read();

        if (! array_key_exists($tokenId, $tokens)) {
            return false;
        }

        unset($tokens[$tokenId]);

        $this->write($tokens);

        return true;
    }

    protected function path(): string
    {
        return storage_path('statamic/mcp/tokens.yaml');
    }

    protected function read(): array
    {
        return File::exists($this->path())
            ? YAML::parse(File::get($this->path()))
            : [];
    }

    protected function write(array $tokens): void
    {
        File::ensureDirectoryExists(dirname($this->path()));
        File::put($this->path(), YAML::dump($tokens));
    }
}
```

- [ ] **Step 4: Run the test — expect 8 passing**

```
vendor/bin/pest tests/Tokens/TokenRepositoryTest.php
```

Expected output:

```
   PASS  Tests\Tokens\TokenRepositoryTest
  ✓ it issues a token in the mcp_{tokenId}_{secret} format
  ✓ it stores only the sha-256 hash in tokens.yaml — never the plaintext secret
  ✓ it records an iso-8601 expiry when issued with expires days
  ✓ it finds a token record by id
  ✓ it returns null for an unknown token id
  ✓ it revokes a token and rewrites the file without it, leaving others intact
  ✓ it returns false when revoking an unknown token id
  ✓ it returns all issued tokens keyed by token id

  Tests:    8 passed
```

Then run the full suite to prove nothing regressed:

```
vendor/bin/pest
```

Expected: everything green (`Tests: N passed`, zero failures).

- [ ] **Step 5: Format**

```
composer format
```

Expected: Pint reports `PASS` (or lists the files it just fixed — re-run the suite if it changed anything).

- [ ] **Step 6: Commit**

```
git add -A && git commit -m "feat: add hashed YAML token store (TokenRepository + PlainToken)

Tokens live in storage/statamic/mcp/tokens.yaml as SHA-256 hashes with
user id, name, created/expires timestamps. The plaintext secret exists
exactly once, inside the returned PlainToken — never on disk.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

### Task 6: AuthenticateMcpToken middleware — 401 wall + real Statamic auth

*The ServiceProvider (Task 3) already wires `AuthenticateMcpToken::class` onto the MCP route in token mode, and `EnsureMcpPermission::class` (the `'access mcp'` check, created in Task 4) after it. Until this task lands, any request to the MCP endpoint 500s with "Target class [Danielgnh\StatamicMcp\Middleware\AuthenticateMcpToken] does not exist" — this task creates the class and proves the whole stack end-to-end. The load-bearing detail (spec §5): authenticate via `Auth::shouldUse()` + `Auth::setUser()` on the auth manager — never merely `$request->setUserResolver()` — so `Statamic\Facades\User::current()`, revision authorship, and event listeners all see the acting user. laravel/mcp v0.8.2's `Request::user()` resolves through the auth manager's userResolver, so this also makes `$request->user()` work inside tools. The guard is `config('statamic.users.guards.cp', 'web')` — `web` in the AddonTestCase environment.*

**Files:**
- Create: `src/Middleware/AuthenticateMcpToken.php`
- Test: `tests/Middleware/AuthenticateMcpTokenTest.php`

- [ ] **Step 1: Write the failing middleware test**

The test registers a probe route carrying the exact middleware stack the ServiceProvider puts on the MCP route in token mode (`AuthenticateMcpToken` then `EnsureMcpPermission`). The probe body returns `Statamic\Facades\User::current()->email()` — the success test asserting on it is the literal proof that the middleware authenticates on the auth manager, not just a request resolver. The end-to-end test against the real `/mcp/statamic` endpoint lives in Task 8's test file — it requires the `Server` class, which does not exist until then.

Create `tests/Middleware/AuthenticateMcpTokenTest.php`:

```php
<?php

use Danielgnh\StatamicMcp\Middleware\AuthenticateMcpToken;
use Danielgnh\StatamicMcp\Middleware\EnsureMcpPermission;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tokens\TokenRepository;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Statamic\Facades\User;

beforeEach(function () {
    File::delete(storage_path('statamic/mcp/tokens.yaml'));

    $this->repo = app(TokenRepository::class);

    // Same middleware stack the ServiceProvider mounts on the MCP route in
    // token mode. The body returns what Statamic thinks the current user is —
    // the visibility that revision authorship and event listeners depend on.
    Route::post('/mcp-auth-probe', function () {
        return response()->json(['email' => User::current()->email()]);
    })->middleware([AuthenticateMcpToken::class, EnsureMcpPermission::class]);
});

it('rejects a request with no authorization header', function () {
    $this->postJson('/mcp-auth-probe')
        ->assertStatus(401)
        ->assertHeader('WWW-Authenticate', 'Bearer');
});

it('rejects a non-bearer authorization scheme', function () {
    $this->postJson('/mcp-auth-probe', [], ['Authorization' => 'Basic dXNlcjpwYXNz'])
        ->assertStatus(401);
});

it('rejects malformed bearer tokens', function (string $token) {
    $this->postJson('/mcp-auth-probe', [], ['Authorization' => "Bearer {$token}"])
        ->assertStatus(401);
})->with([
    'no mcp prefix' => 'sk-something-else',
    'wrong prefix' => 'mpc_abcdefghijkl_'.str_repeat('s', 40),
    'missing secret part' => 'mcp_abcdefghijkl',
    'empty token id' => 'mcp__'.str_repeat('s', 40),
    'empty secret' => 'mcp_abcdefghijkl_',
]);

it('rejects an oversized authorization header before parsing', function () {
    $token = 'mcp_abcdefghijkl_'.str_repeat('a', 300); // pushes the header past 256 chars

    $this->postJson('/mcp-auth-probe', [], ['Authorization' => "Bearer {$token}"])
        ->assertStatus(401);
});

it('rejects an unknown token id', function () {
    $this->postJson('/mcp-auth-probe', [], ['Authorization' => 'Bearer mcp_unknownnnnnn_'.Str::random(40)])
        ->assertStatus(401);
});

it('rejects a known token id with the wrong secret', function () {
    $user = Fixtures::makeUser();
    $plain = $this->repo->issue($user);

    $this->postJson('/mcp-auth-probe', [], ['Authorization' => "Bearer mcp_{$plain->tokenId}_".Str::random(40)])
        ->assertStatus(401);
});

it('rejects an expired token', function () {
    $user = Fixtures::makeUser();
    $plain = $this->repo->issue($user, null, 5);

    $this->travelTo(now()->addDays(6));

    $this->postJson('/mcp-auth-probe', [], ['Authorization' => "Bearer {$plain->token}"])
        ->assertStatus(401);
});

it('rejects a revoked token that worked moments before', function () {
    $user = Fixtures::makeUser();
    $plain = $this->repo->issue($user);

    $headers = ['Authorization' => "Bearer {$plain->token}"];

    $this->postJson('/mcp-auth-probe', [], $headers)->assertOk();

    $this->repo->revoke($plain->tokenId);

    $this->postJson('/mcp-auth-probe', [], $headers)->assertStatus(401);
});

it('rejects a token whose user has been deleted', function () {
    $user = Fixtures::makeUser();
    $plain = $this->repo->issue($user);

    $user->delete();

    $this->postJson('/mcp-auth-probe', [], ['Authorization' => "Bearer {$plain->token}"])
        ->assertStatus(401);
});

it('authenticates so Statamic User::current() resolves to the token user inside the request', function () {
    $user = Fixtures::makeUser();
    $plain = $this->repo->issue($user);

    // The probe body runs Statamic\Facades\User::current()->email() — this
    // passing proves Auth::shouldUse + Auth::setUser, not just a resolver.
    $this->postJson('/mcp-auth-probe', [], ['Authorization' => "Bearer {$plain->token}"])
        ->assertOk()
        ->assertJson(['email' => $user->email()]);
});

it("returns 403 when the authenticated user lacks the 'access mcp' permission", function () {
    $user = tap(User::make()->email('no-mcp@site.test'))->save(); // no roles at all
    $plain = $this->repo->issue($user);

    $this->postJson('/mcp-auth-probe', [], ['Authorization' => "Bearer {$plain->token}"])
        ->assertStatus(403)
        ->assertJsonFragment([
            'error' => "requires 'access mcp' — grant it to a role of no-mcp@site.test in the Control Panel",
        ]);
});

it("lets supers through without an explicit 'access mcp' grant", function () {
    $super = Fixtures::makeSuper();
    $plain = $this->repo->issue($super);

    $this->postJson('/mcp-auth-probe', [], ['Authorization' => "Bearer {$plain->token}"])
        ->assertOk()
        ->assertJson(['email' => $super->email()]);
});
```

- [ ] **Step 2: Run the test — expect every case to fail with a 500**

```
vendor/bin/pest tests/Middleware/AuthenticateMcpTokenTest.php
```

Expected output — the middleware class cannot be resolved, so every request 500s instead of returning 401/403/200:

```
   FAIL  Tests\Middleware\AuthenticateMcpTokenTest
  ⨯ it rejects a request with no authorization header
    Expected response status code [401] but received 500.
    ... Target class [Danielgnh\StatamicMcp\Middleware\AuthenticateMcpToken] does not exist.
  ...
  Tests:    16 failed
```

- [ ] **Step 3: Create the middleware (verbatim from the shared contracts)**

Check order is load-bearing (spec §5): length cap → positional parse → hash_equals → expiry → `User::find` → auth manager.

Create `src/Middleware/AuthenticateMcpToken.php`:

```php
<?php

namespace Danielgnh\StatamicMcp\Middleware;

use Closure;
use Danielgnh\StatamicMcp\Tokens\TokenRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Statamic\Facades\User;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateMcpToken
{
    public function __construct(protected TokenRepository $tokens)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        // 1. Length cap before any parsing or hashing (hash-DoS guard).
        $header = (string) $request->header('Authorization', '');

        if ($header === '' || strlen($header) > 256) {
            return $this->unauthenticated();
        }

        // 2. Positional parse: mcp_{tokenId}_{secret}.
        $parts = explode('_', (string) $request->bearerToken(), 3);

        if (count($parts) !== 3 || $parts[0] !== 'mcp' || $parts[1] === '' || $parts[2] === '') {
            return $this->unauthenticated();
        }

        [, $tokenId, $secret] = $parts;

        // 3. Constant-time compare against the stored SHA-256.
        $record = $this->tokens->find($tokenId);

        if (! $record || ! hash_equals($record['hash'], hash('sha256', $secret))) {
            return $this->unauthenticated();
        }

        // 4. Expiry.
        if ($record['expires_at'] && Carbon::parse($record['expires_at'])->isPast()) {
            return $this->unauthenticated();
        }

        // 5. Tokens die with their user — no orphan bookkeeping.
        if (! $user = User::find($record['user'])) {
            return $this->unauthenticated();
        }

        // 6. Authenticate on the auth manager so User::current() resolves.
        $guard = config('statamic.users.guards.cp', 'web');
        Auth::shouldUse($guard);
        Auth::setUser($user);

        return $next($request);
    }

    protected function unauthenticated(): Response
    {
        return response()->json(['error' => 'Unauthenticated.'], 401, ['WWW-Authenticate' => 'Bearer']);
    }
}
```

- [ ] **Step 4: Run the test — expect 16 passing**

```
vendor/bin/pest tests/Middleware/AuthenticateMcpTokenTest.php
```

Expected output:

```
   PASS  Tests\Middleware\AuthenticateMcpTokenTest
  ✓ it rejects a request with no authorization header
  ✓ it rejects a non-bearer authorization scheme
  ✓ it rejects malformed bearer tokens with (no mcp prefix)
  ✓ it rejects malformed bearer tokens with (wrong prefix)
  ✓ it rejects malformed bearer tokens with (missing secret part)
  ✓ it rejects malformed bearer tokens with (empty token id)
  ✓ it rejects malformed bearer tokens with (empty secret)
  ✓ it rejects an oversized authorization header before parsing
  ✓ it rejects an unknown token id
  ✓ it rejects a known token id with the wrong secret
  ✓ it rejects an expired token
  ✓ it rejects a revoked token that worked moments before
  ✓ it rejects a token whose user has been deleted
  ✓ it authenticates so Statamic User::current() resolves to the token user inside the request
  ✓ it returns 403 when the authenticated user lacks the 'access mcp' permission
  ✓ it lets supers through without an explicit 'access mcp' grant

  Tests:    16 passed
```

Then the full suite:

```
vendor/bin/pest
```

Expected: everything green.

- [ ] **Step 5: Format**

```
composer format
```

Expected: Pint `PASS` (re-run the suite if it changed files).

- [ ] **Step 6: Commit**

```
git add -A && git commit -m "feat: authenticate MCP requests with addon-issued bearer tokens

AuthenticateMcpToken checks in load-bearing order: header length cap ->
positional mcp_{id}_{secret} parse -> hash_equals against stored SHA-256 ->
expiry -> User::find (tokens die with their user). On success it calls
Auth::shouldUse + Auth::setUser so Statamic's User::current(), revision
authorship, and event listeners all see the acting user. 'access mcp' is
enforced after auth by EnsureMcpPermission on the same route.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

### Task 7: please commands — mcp:token, mcp:tokens, mcp:token:revoke (+ mcp:doctor)

*All four command classes ship in this task, not just the three token commands: the ServiceProvider's `$commands` array (Task 3, contracts-verbatim) lists `IssueToken`, `ListTokens`, `RevokeToken` AND `Doctor`, and Laravel resolves every registered command eagerly when the console kernel boots — so the very first `$this->artisan(...)` call in a test dies with "Target class [Danielgnh\StatamicMcp\Console\Doctor] does not exist" if any of the four is missing. The Doctor written here is deliberately MINIMAL — token-mode checks only: the enabled flag, endpoint URL, auth mode, token-store writability, and the zero-token "locked door" warning. Task 24 replaces this implementation wholesale with the full version (OAuth prerequisites etc.). Every line the minimal Doctor prints uses the exact wording of the full version, so the doctor test below keeps passing unchanged after the Task 24 replacement.*

*Each command uses Statamic's stock `Statamic\Console\RunsInPlease` trait: the `statamic:` signature prefix is stripped under the `please` binary, so `php artisan statamic:mcp:token` and `php please mcp:token` (the spec §5 spelling) are the same command. Tests invoke the artisan spelling.*

**Files:**
- Create: `src/Console/IssueToken.php`
- Create: `src/Console/ListTokens.php`
- Create: `src/Console/RevokeToken.php`
- Create: `src/Console/Doctor.php`
- Test: `tests/Console/IssueTokenTest.php`
- Test: `tests/Console/TokenCommandsTest.php`

- [ ] **Step 1: Write the failing mcp:token test**

Create `tests/Console/IssueTokenTest.php`:

```php
<?php

use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tokens\TokenRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Statamic\Facades\User;

beforeEach(function () {
    File::delete(storage_path('statamic/mcp/tokens.yaml'));
});

it('issues a token, prints it once, and prints ready-to-paste client snippets', function () {
    $user = Fixtures::makeUser();

    $this->artisan('statamic:mcp:token', ['email' => $user->email(), '--name' => 'ci token'])
        ->expectsOutputToContain('ONLY time')
        // Claude Code one-liner, exactly as the docs spell it, with the real URL + token:
        ->expectsOutputToContain('claude mcp add --transport http statamic http://localhost/mcp/statamic --header "Authorization: Bearer mcp_')
        // Cursor mcp.json snippet:
        ->expectsOutputToContain('"mcpServers"')
        ->expectsOutputToContain('"Authorization": "Bearer mcp_')
        // Honest client-coverage note (spec §2/§5):
        ->expectsOutputToContain('claude.ai')
        ->expectsOutputToContain("'auth' => 'oauth'")
        ->assertExitCode(0);

    $tokens = app(TokenRepository::class)->all();

    expect($tokens)->toHaveCount(1);

    $record = array_values($tokens)[0];

    expect($record['user'])->toBe((string) $user->id())
        ->and($record['name'])->toBe('ci token')
        ->and($record['expires_at'])->toBeNull();
});

it('records an expiry when --expires-days is given', function () {
    $this->travelTo(Carbon::parse('2026-07-09T12:00:00Z'));

    $user = Fixtures::makeUser();

    $this->artisan('statamic:mcp:token', ['email' => $user->email(), '--expires-days' => '30'])
        ->expectsOutputToContain('Expires: 2026-08-08T12:00:00+00:00')
        ->assertExitCode(0);

    $record = array_values(app(TokenRepository::class)->all())[0];

    expect($record['expires_at'])->toBe('2026-08-08T12:00:00+00:00');
});

it('fails for an unknown email without touching the token store', function () {
    $this->artisan('statamic:mcp:token', ['email' => 'ghost@site.test'])
        ->expectsOutputToContain('No user with email ghost@site.test')
        ->assertExitCode(1);

    expect(app(TokenRepository::class)->all())->toBe([]);
});

it('rejects a non-positive or non-numeric --expires-days', function (string $days) {
    $user = Fixtures::makeUser();

    $this->artisan('statamic:mcp:token', ['email' => $user->email(), '--expires-days' => $days])
        ->expectsOutputToContain('--expires-days must be a positive whole number')
        ->assertExitCode(1);

    expect(app(TokenRepository::class)->all())->toBe([]);
})->with(['zero' => '0', 'negative' => '-3', 'word' => 'soon']);

it("warns when the user lacks 'access mcp' but still issues the token", function () {
    tap(User::make()->email('bare@site.test'))->save(); // no roles at all

    $this->artisan('statamic:mcp:token', ['email' => 'bare@site.test'])
        ->expectsOutputToContain("does not have the 'access mcp' permission")
        ->assertExitCode(0);

    expect(app(TokenRepository::class)->all())->toHaveCount(1);
});
```

- [ ] **Step 2: Write the failing mcp:tokens / mcp:token:revoke / mcp:doctor test**

Create `tests/Console/TokenCommandsTest.php`:

```php
<?php

use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tokens\TokenRepository;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    File::delete(storage_path('statamic/mcp/tokens.yaml'));

    $this->repo = app(TokenRepository::class);
});

it('lists issued tokens with id, user email, name, and expiry', function () {
    $user = Fixtures::makeUser();
    $plain = $this->repo->issue($user, 'laptop', 30);

    $this->artisan('statamic:mcp:tokens')
        ->expectsOutputToContain($plain->tokenId)
        ->expectsOutputToContain($user->email())
        ->expectsOutputToContain('laptop')
        ->assertExitCode(0);
});

it('says so when no tokens exist', function () {
    $this->artisan('statamic:mcp:tokens')
        ->expectsOutputToContain('No MCP tokens issued')
        ->assertExitCode(0);
});

it('revokes a token by id', function () {
    $user = Fixtures::makeUser();
    $plain = $this->repo->issue($user);

    $this->artisan('statamic:mcp:token:revoke', ['id' => $plain->tokenId])
        ->expectsOutputToContain("Token {$plain->tokenId} revoked")
        ->assertExitCode(0);

    expect($this->repo->find($plain->tokenId))->toBeNull();
});

it('fails to revoke an unknown token id', function () {
    $this->artisan('statamic:mcp:token:revoke', ['id' => 'nope'])
        ->expectsOutputToContain('No token with id nope')
        ->assertExitCode(1);
});

it('doctor prints the endpoint and auth mode and warns about the locked door', function () {
    $this->artisan('statamic:mcp:doctor')
        ->expectsOutputToContain('http://localhost/mcp/statamic')
        ->expectsOutputToContain('Auth mode: token')
        ->expectsOutputToContain('locked door')
        ->assertExitCode(0);
});
```

- [ ] **Step 3: Run both — expect every test to error (commands cannot be resolved)**

```
vendor/bin/pest tests/Console
```

Expected output — the console kernel eagerly resolves the ServiceProvider's `$commands` and dies on the first missing class:

```
   FAIL  Tests\Console\IssueTokenTest
  ⨯ it issues a token, prints it once, and prints ready-to-paste client snippets
    Illuminate\Contracts\Container\BindingResolutionException:
    Target class [Danielgnh\StatamicMcp\Console\IssueToken] does not exist.
  ...
  Tests:    12 failed
```

- [ ] **Step 4: Create the four command classes**

Create `src/Console/IssueToken.php`:

```php
<?php

namespace Danielgnh\StatamicMcp\Console;

use Danielgnh\StatamicMcp\Tokens\TokenRepository;
use Illuminate\Console\Command;
use Statamic\Console\RunsInPlease;
use Statamic\Facades\User;

class IssueToken extends Command
{
    use RunsInPlease;

    protected $signature = 'statamic:mcp:token
        {email : Email of the Statamic user the token will act as}
        {--name= : Label for this token, e.g. "claude-code laptop"}
        {--expires-days= : Days until the token expires (never expires when omitted)}';

    protected $description = 'Issue an MCP access token for a Statamic user';

    public function handle(TokenRepository $tokens): int
    {
        $email = $this->argument('email');

        $user = User::findByEmail($email);

        if (! $user) {
            $this->error("No user with email {$email} — create one in the Control Panel first.");

            return self::FAILURE;
        }

        $days = $this->option('expires-days');

        if ($days !== null && (! ctype_digit((string) $days) || (int) $days < 1)) {
            $this->error('--expires-days must be a positive whole number.');

            return self::FAILURE;
        }

        $plain = $tokens->issue($user, $this->option('name'), $days === null ? null : (int) $days);

        $url = url(config('statamic.mcp.route'));

        $this->line('');
        $this->info('Token created. This is the ONLY time it will be displayed — copy it now:');
        $this->line('');
        $this->line("  {$plain->token}");
        $this->line('');
        $this->line("Token id: {$plain->tokenId} (revoke with: php please mcp:token:revoke {$plain->tokenId})");
        $this->line($plain->expiresAt ? "Expires: {$plain->expiresAt->toIso8601String()}" : 'Expires: never');

        if (! $user->isSuper() && ! $user->hasPermission('access mcp')) {
            $this->warn("Heads up: {$email} does not have the 'access mcp' permission yet — requests will get 403 until you grant it to one of their roles in the Control Panel.");
        }

        $this->line('');
        $this->info('Claude Code:');
        $this->line('');
        $this->line("  claude mcp add --transport http statamic {$url} --header \"Authorization: Bearer {$plain->token}\"");
        $this->line('');
        $this->info('Cursor (.cursor/mcp.json):');
        $this->line('');
        $this->line(json_encode([
            'mcpServers' => [
                'statamic' => [
                    'url' => $url,
                    'headers' => ['Authorization' => "Bearer {$plain->token}"],
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->line('');
        $this->line('Works with Claude Code, Cursor, and any MCP client that can send a static Authorization header.');
        $this->line('Individual claude.ai and Claude Desktop connectors cannot send static headers (that is an');
        $this->line("org-admin beta for Team/Enterprise plans) — for those clients use OAuth mode ('auth' => 'oauth').");
        $this->line('See the README client-compatibility matrix.');

        return self::SUCCESS;
    }
}
```

Create `src/Console/ListTokens.php`:

```php
<?php

namespace Danielgnh\StatamicMcp\Console;

use Danielgnh\StatamicMcp\Tokens\TokenRepository;
use Illuminate\Console\Command;
use Statamic\Console\RunsInPlease;
use Statamic\Facades\User;

class ListTokens extends Command
{
    use RunsInPlease;

    protected $signature = 'statamic:mcp:tokens';

    protected $description = 'List issued MCP access tokens';

    public function handle(TokenRepository $tokens): int
    {
        $all = $tokens->all();

        if ($all === []) {
            $this->info('No MCP tokens issued. Create one with: php please mcp:token you@site.com');

            return self::SUCCESS;
        }

        $this->table(
            ['Id', 'User', 'Name', 'Created', 'Expires'],
            collect($all)->map(function (array $record, string $tokenId) {
                return [
                    $tokenId,
                    User::find($record['user'])?->email() ?? "{$record['user']} (user deleted — token dead)",
                    $record['name'] ?? '—',
                    $record['created_at'],
                    $record['expires_at'] ?? 'never',
                ];
            })->values()->all(),
        );

        return self::SUCCESS;
    }
}
```

Create `src/Console/RevokeToken.php`:

```php
<?php

namespace Danielgnh\StatamicMcp\Console;

use Danielgnh\StatamicMcp\Tokens\TokenRepository;
use Illuminate\Console\Command;
use Statamic\Console\RunsInPlease;

class RevokeToken extends Command
{
    use RunsInPlease;

    protected $signature = 'statamic:mcp:token:revoke
        {id : The token id (first column of mcp:tokens)}';

    protected $description = 'Revoke an MCP access token';

    public function handle(TokenRepository $tokens): int
    {
        $id = $this->argument('id');

        if (! $tokens->revoke($id)) {
            $this->error("No token with id {$id} — list tokens with: php please mcp:tokens");

            return self::FAILURE;
        }

        $this->info("Token {$id} revoked. Clients using it will get 401 on their next request.");

        return self::SUCCESS;
    }
}
```

Create `src/Console/Doctor.php` — minimal, token-mode checks only. Task 24 replaces this implementation wholesale with the full version (OAuth prerequisites etc.); every output line below is worded exactly as the full version prints it, so tests written against these lines survive the replacement:

```php
<?php

namespace Danielgnh\StatamicMcp\Console;

use Danielgnh\StatamicMcp\Tokens\TokenRepository;
use Illuminate\Console\Command;
use Statamic\Console\RunsInPlease;

class Doctor extends Command
{
    use RunsInPlease;

    protected $signature = 'statamic:mcp:doctor';

    protected $description = 'Check the MCP server configuration and auth setup';

    public function handle(TokenRepository $tokens): int
    {
        $ok = true;

        $mode = config('statamic.mcp.auth', 'token');

        $this->line('Statamic MCP doctor');
        $this->line('');
        $this->line('  Endpoint:  '.url(config('statamic.mcp.route', 'mcp/statamic')));
        $this->line('  Auth mode: '.$mode);
        $this->line('');

        if (config('statamic.mcp.enabled')) {
            $this->info('[ OK ] MCP is enabled.');
        } else {
            $this->warn("[WARN] MCP is disabled ('enabled' => false) — the endpoint is not registered. Set STATAMIC_MCP_ENABLED=true to serve requests.");
        }

        if ($mode === 'token') {
            $dir = storage_path('statamic/mcp');

            // The directory may not exist before the first token is issued —
            // probe the closest existing ancestor for writability.
            $probe = $dir;

            while (! is_dir($probe)) {
                $probe = dirname($probe);
            }

            if (is_writable($probe)) {
                $this->info('[ OK ] Token store is writable ('.$dir.').');
            } else {
                $this->error('[FAIL] Token store is not writable — fix permissions on '.$probe.' so tokens can be saved to '.$dir.'/tokens.yaml.');
                $ok = false;
            }

            $count = count($tokens->all());

            if ($count === 0) {
                $this->warn('[WARN] No tokens issued — the endpoint is a locked door. Run: php please mcp:token you@site.com');
            } else {
                $this->info('[ OK ] '.$count.' token(s) issued.');
            }
        }

        $this->line('');

        if (! $ok) {
            $this->error('Problems found. Fix the [FAIL] items above.');

            return self::FAILURE;
        }

        $this->info('No blocking problems found.');

        return self::SUCCESS;
    }
}
```

- [ ] **Step 5: Run the console tests — expect 12 passing**

```
vendor/bin/pest tests/Console
```

Expected output:

```
   PASS  Tests\Console\IssueTokenTest
  ✓ it issues a token, prints it once, and prints ready-to-paste client snippets
  ✓ it records an expiry when --expires-days is given
  ✓ it fails for an unknown email without touching the token store
  ✓ it rejects a non-positive or non-numeric --expires-days with (zero)
  ✓ it rejects a non-positive or non-numeric --expires-days with (negative)
  ✓ it rejects a non-positive or non-numeric --expires-days with (word)
  ✓ it warns when the user lacks 'access mcp' but still issues the token

   PASS  Tests\Console\TokenCommandsTest
  ✓ it lists issued tokens with id, user email, name, and expiry
  ✓ it says so when no tokens exist
  ✓ it revokes a token by id
  ✓ it fails to revoke an unknown token id
  ✓ it doctor prints the endpoint and auth mode and warns about the locked door

  Tests:    12 passed
```

Then the full suite:

```
vendor/bin/pest
```

Expected: everything green — token auth is now end-to-end: issue via command → authenticate via middleware → revoke via command → 401.

- [ ] **Step 6: Format**

```
composer format
```

Expected: Pint `PASS` (re-run the suite if it changed files).

- [ ] **Step 7: Commit**

```
git add -A && git commit -m "feat: add mcp:token, mcp:tokens, mcp:token:revoke and mcp:doctor commands

mcp:token prints the plaintext exactly once, with ready-to-paste Claude Code
(claude mcp add --transport http ... --header) and Cursor mcp.json snippets,
plus the honest client-coverage note: individual claude.ai/Claude Desktop
connectors cannot send static headers (org-admin Team/Enterprise beta) and
need OAuth mode. All commands run under php please via RunsInPlease.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```


### Task 8: Server, abstract base Tool, and `statamic_overview`

**Files:**
- Create: `src/Tools/ToolException.php`
- Create: `src/Tools/Tool.php`
- Create: `src/Server.php`
- Create: `src/Tools/StatamicOverview.php`
- Test: `tests/Feature/StatamicOverviewTest.php`

Preconditions (created by earlier tasks): `composer.json` with dependencies installed, `config/mcp.php`, `src/ServiceProvider.php`, `tests/TestCase.php`, `tests/Pest.php`, `tests/Support/Fixtures.php`, and `tests/__fixtures__/dev-null/.gitkeep` — all exactly as in the contracts appendix (`docs/superpowers/plans/2026-07-09-statamic-mcp-contracts.md` §1–§3, §8). If any is missing, copy it verbatim from the contracts appendix before starting.

Spec contract for this tool (spec §4 row 1): zero params. Returns sites; exposed collections (handle, title, dated?, revisions?, blueprints); taxonomies; global sets; the acting user (email, roles, is_super) plus capability flags per exposed resource computed via `hasPermission()` — `can_create`/`can_edit`/`can_publish`/`can_delete` per collection, `can_create`/`can_edit`/`can_delete` per taxonomy (v6 has **no** `publish {taxonomy} terms` permission — verified facts §3 — so taxonomies get no `can_publish` flag), `can_edit` per global set; delete flags only present when deletes are enabled; server flags (`read_only`, `deletes`). Everything filtered to the config allowlist AND what the user may view. Globals visibility is gated on `edit {handle} globals` because v6 has no `view {handle} globals` permission and the CP itself gates viewing on edit (verified facts §3).

- [ ] **Step 1: Write the failing feature test**

Create `tests/Feature/StatamicOverviewTest.php` with this complete content. The tests assert exact JSON fragments — this is safe because `Response::json()` encodes compactly with `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE` (verified laravel/mcp v0.8.2) and the tool builds every array in a fixed, sorted order. Note test 1's collection fragment ends with `"can_publish":true}]` — that exact match also proves `can_delete` is *absent* when deletes are off.

```php
<?php

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tokens\TokenRepository;
use Danielgnh\StatamicMcp\Tools\StatamicOverview;
use Statamic\Facades\Collection;

it('returns sites, resources with capability flags, acting user, and server flags for a super', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();
    Fixtures::settings();

    $super = Fixtures::makeSuper();

    Server::actingAs($super)
        ->tool(StatamicOverview::class, [])
        ->assertOk()
        ->assertSee('"sites":[{"handle":"en","name":"English","url":"/","locale":"en_US"}]')
        // fragment ends at "can_publish":true}] — proves can_delete is absent while deletes are disabled
        ->assertSee('"collections":[{"handle":"blog","title":"Blog","dated":false,"revisions":false,"blueprints":["article"],"can_create":true,"can_edit":true,"can_publish":true}]')
        ->assertSee('"taxonomies":[{"handle":"tags","title":"Tags","blueprints":["tag"],"can_create":true,"can_edit":true}]')
        ->assertSee('"globals":[{"handle":"settings","title":"Settings","can_edit":true}]')
        ->assertSee(sprintf('"user":{"email":"%s","roles":[],"is_super":true}', $super->email()))
        ->assertSee('"server":{"read_only":false,"deletes":false}');
});

it('omits collections excluded by the resources allowlist', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    Collection::make('secrets')->title('Secrets')->save();

    config(['statamic.mcp.resources.collections' => ['blog']]);

    $super = Fixtures::makeSuper();

    Server::actingAs($super)
        ->tool(StatamicOverview::class, [])
        ->assertOk()
        // trailing ],"taxonomies" proves the collections array holds exactly one element:
        // 'secrets' exists on the site but is not exposed to MCP
        ->assertSee('"collections":[{"handle":"blog","title":"Blog","dated":false,"revisions":false,"blueprints":["article"],"can_create":true,"can_edit":true,"can_publish":true}],"taxonomies"');
});

it('omits resources the user may not view and reflects granted permissions in flags', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    Collection::make('pages')->title('Pages')->save();

    $user = Fixtures::makeUser('view blog entries', 'edit blog entries');

    Server::actingAs($user)
        ->tool(StatamicOverview::class, [])
        ->assertOk()
        // 'pages' (no 'view pages entries') and 'tags' (no 'view tags terms') are filtered out
        // entirely; blog flags mirror the granted permissions: view+edit but no create/publish
        ->assertSee('"collections":[{"handle":"blog","title":"Blog","dated":false,"revisions":false,"blueprints":["article"],"can_create":false,"can_edit":true,"can_publish":false}],"taxonomies":[],"globals":[]');
});

it('hides global sets the user may not edit', function () {
    Fixtures::site();
    Fixtures::settings();

    $user = Fixtures::makeUser(); // 'access mcp' only

    Server::actingAs($user)
        ->tool(StatamicOverview::class, [])
        ->assertOk()
        ->assertSee('"globals":[],"user"');
});

it('lists global sets the user may edit', function () {
    Fixtures::site();
    Fixtures::settings();

    $user = Fixtures::makeUser('edit settings globals');

    Server::actingAs($user)
        ->tool(StatamicOverview::class, [])
        ->assertOk()
        ->assertSee('"globals":[{"handle":"settings","title":"Settings","can_edit":true}]');
});

it('includes can_delete flags only when deletes are enabled', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    config(['statamic.mcp.deletes' => true]);

    $user = Fixtures::makeUser('view blog entries', 'delete blog entries', 'view tags terms');

    Server::actingAs($user)
        ->tool(StatamicOverview::class, [])
        ->assertOk()
        ->assertSee('"collections":[{"handle":"blog","title":"Blog","dated":false,"revisions":false,"blueprints":["article"],"can_create":false,"can_edit":false,"can_publish":false,"can_delete":true}]')
        ->assertSee('"taxonomies":[{"handle":"tags","title":"Tags","blueprints":["tag"],"can_create":false,"can_edit":false,"can_delete":false}]')
        ->assertSee('"deletes":true');
});

it('reports the read_only server flag and forces the deletes flag off', function () {
    Fixtures::site();

    config(['statamic.mcp.read_only' => true, 'statamic.mcp.deletes' => true]);

    $super = Fixtures::makeSuper();

    Server::actingAs($super)
        ->tool(StatamicOverview::class, [])
        ->assertOk()
        ->assertSee('"server":{"read_only":true,"deletes":false}');
});

// moved here from Task 6: requires the Server class
it('guards the real MCP endpoint end to end', function () {
    $initialize = [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2025-11-25',
            'capabilities' => [],
            'clientInfo' => ['name' => 'pest', 'version' => '1.0.0'],
        ],
    ];

    $this->postJson('/mcp/statamic', $initialize)->assertStatus(401);

    $user = Fixtures::makeUser();
    $plain = app(TokenRepository::class)->issue($user);

    $this->postJson('/mcp/statamic', $initialize, [
        'Authorization' => "Bearer {$plain->token}",
        'Accept' => 'application/json, text/event-stream', // streamable-HTTP clients send both
    ])
        ->assertOk()
        ->assertSee('Statamic'); // serverInfo name from the #[Name] attribute
});
```

- [ ] **Step 2: Run the test, confirm it fails for the right reason**

```
vendor/bin/pest tests/Feature/StatamicOverviewTest.php
```

Expected: all 8 tests FAIL — the seven overview tests with `Error: Class "Danielgnh\StatamicMcp\Server" not found` (the `Server::actingAs()` call is the first thing each test hits, and neither `Server` nor any Tool class exists yet), and the moved end-to-end test with a 500 on the authenticated request (the route cannot instantiate the missing `Server` class; its unauthenticated 401 assertion already passes). Output ends with `Tests: 8 failed`.

- [ ] **Step 3: Create `src/Tools/ToolException.php` (verbatim from contracts §5)**

```php
<?php

namespace Danielgnh\StatamicMcp\Tools;

use RuntimeException;

/**
 * Thrown by base-Tool guards (ensureExposed, ensurePermission, and tool-level
 * failures); rendered as Response::error() by Tool::handle().
 */
class ToolException extends RuntimeException
{
}
```

- [ ] **Step 4: Create `src/Tools/Tool.php` — the ONE abstract base (verbatim from contracts §5)**

Background for the try/catch: laravel/mcp v0.8.2's `CallTool` catches every Throwable but masks generic exception messages as "An internal server error occurred." when `app.debug` is off — so the base catches `ToolException` itself, guaranteeing guard messages reach the model. `ValidationException` from `$request->validate()` is deliberately NOT caught: CallTool renders it as a tool error with full field messages.

```php
<?php

namespace Danielgnh\StatamicMcp\Tools;

use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool as BaseTool;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Collection;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\User;
use Statamic\Globals\Variables;
use Statamic\Taxonomies\LocalizedTerm;

abstract class Tool extends BaseTool
{
    public const LIVENESS_DRAFT = 'saved as draft — not live';

    public const LIVENESS_PUBLISHED = 'published';

    public const LIVENESS_WORKING_COPY = 'working copy created — live entry unchanged';

    public const LIVENESS_LIVE = 'updated — live'; // terms/globals have no draft state

    public const LIVENESS_CREATED = 'created — live'; // used later by terms_create

    final public function handle(Request $request): Response
    {
        try {
            return $this->execute($request);
        } catch (ToolException $e) {
            return Response::error($e->getMessage());
        }
    }

    abstract protected function execute(Request $request): Response;

    /**
     * The acting Statamic user, mode-agnostic: under Passport $request->user()
     * is the Eloquent model; fromUser() normalizes both (spec §5).
     */
    protected function user(Request $request): UserContract
    {
        return User::fromUser($request->user());
    }

    /**
     * @param  'collections'|'taxonomies'|'globals'  $type
     *
     * Throws when $handle is missing OR exists-but-unexposed — indistinguishable
     * by design (spec §4); the error lists only exposed handles.
     */
    protected function ensureExposed(string $type, string $handle): void
    {
        $exposed = $this->exposedHandles($type);

        if (! in_array($handle, $exposed, true)) {
            throw new ToolException($this->notFoundMessage(Str::singular($type), $handle, $exposed));
        }
    }

    /**
     * @param  'collections'|'taxonomies'|'globals'  $type
     * @return list<string> handles that exist AND pass config('statamic.mcp.resources.{$type}')
     */
    protected function exposedHandles(string $type): array
    {
        $configured = config("statamic.mcp.resources.{$type}", false);

        if ($configured === false || $configured === []) {
            return [];
        }

        $all = match ($type) {
            'collections' => Collection::handles()->all(),
            'taxonomies' => Taxonomy::handles()->all(),
            'globals' => GlobalSet::all()->map->handle()->values()->all(),
        };

        return $configured === true
            ? array_values($all)
            : array_values(array_intersect($all, $configured));
    }

    /**
     * Uniform denial message for every native-permission check (spec §6).
     * Supers auto-pass. Publish/site checks pass their own permission strings.
     */
    protected function ensurePermission(UserContract $user, string $permission): void
    {
        if ($user->isSuper() || $user->hasPermission($permission)) {
            return;
        }

        throw new ToolException(sprintf(
            "requires '%s' — grant it to a role of %s in the Control Panel",
            $permission,
            $user->email(),
        ));
    }

    protected function writesEnabled(): bool
    {
        return ! config('statamic.mcp.read_only');
    }

    protected function deletesEnabled(): bool
    {
        return $this->writesEnabled() && config('statamic.mcp.deletes');
    }

    /**
     * Compact JSON in a text block (spec §8). Response::json() encodes with
     * JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE (verified v0.8.2).
     */
    protected function json(array $data): Response
    {
        return Response::json($data);
    }

    protected function notFound(string $what, string $given, array $available): Response
    {
        return Response::error($this->notFoundMessage($what, $given, $available));
    }

    /**
     * Liveness block appended to every write response (spec §4): pass a
     * LIVENESS_* constant. editUrl() verified on Entry, LocalizedTerm, and
     * Variables in 6.x source.
     */
    protected function liveness(EntryContract|LocalizedTerm|Variables $saved, string $state): array
    {
        return [
            'result' => $state,
            'cp_edit_url' => $saved->editUrl(),
        ];
    }

    private function notFoundMessage(string $what, string $given, array $available): string
    {
        sort($available);

        return sprintf(
            "%s '%s' not found — available: %s",
            $what,
            $given,
            $available === [] ? '(none exposed)' : implode(', ', $available),
        );
    }
}
```

- [ ] **Step 5: Create `src/Server.php`**

The `#[Name]`/`#[Instructions]` attributes and docblock are verbatim from contracts §4. The `$tools` array lists only the tools that exist at this point in the plan; Task 9 and each later tool task append exactly one line, so after the final tool task the array matches contracts §4 line for line (the full 14-tool list in contracts §9 is the finish line — do not add a class here before its task creates it, because laravel/mcp instantiates every registered class-string when resolving a tool call).

```php
<?php

namespace Danielgnh\StatamicMcp;

use Laravel\Mcp\Server as McpServer;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('Statamic')]
#[Instructions('MCP server for this Statamic site. Call statamic_overview first: it returns the sites, collections, taxonomies, and global sets you can work with, plus your own permission flags per resource. Before creating or updating content, call blueprints_get for the target blueprint — writes accept raw field data only (never augmented data). Writes save drafts by default; publishing requires an explicit published: true and the matching Statamic permission.')]
class Server extends McpServer
{
    /** @var array<int, class-string<\Laravel\Mcp\Server\Tool>> */
    protected array $tools = [
        Tools\StatamicOverview::class,
    ];
}
```

- [ ] **Step 6: Create `src/Tools/StatamicOverview.php`**

Implementation notes baked in below: handles are sorted before mapping so JSON output is deterministic (the exact-fragment tests depend on it); `can()` mirrors the supers-auto-pass shape of the base `ensurePermission()` but returns a bool for flags; taxonomies get no `can_publish` (no such permission exists in v6 — verified facts §3); globals visibility === the `edit {handle} globals` permission (no view permission exists in v6).

```php
<?php

namespace Danielgnh\StatamicMcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection as SupportCollection;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Facades\Collection;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy;

#[Name('statamic_overview')]
#[Description('Start here — zero parameters. Returns the sites; the collections, taxonomies, and global sets exposed to MCP and visible to you; your capability flags per resource (can_create, can_edit, can_publish, can_delete — delete flags appear only when deletes are enabled); the acting user (email, roles, is_super); and server flags (read_only, deletes).')]
#[IsReadOnly]
#[IsIdempotent]
class StatamicOverview extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return []; // zero parameters (spec §4 row 1)
    }

    protected function execute(Request $request): Response
    {
        $user = $this->user($request);

        return $this->json([
            'sites' => $this->sites(),
            'collections' => $this->collections($user),
            'taxonomies' => $this->taxonomies($user),
            'globals' => $this->globals($user),
            'user' => [
                'email' => $user->email(),
                'roles' => $user->roles()->map->handle()->values()->all(),
                'is_super' => $user->isSuper(),
            ],
            'server' => [
                'read_only' => ! $this->writesEnabled(),
                'deletes' => $this->deletesEnabled(),
            ],
        ]);
    }

    private function sites(): array
    {
        return Site::all()->map(fn ($site) => [
            'handle' => $site->handle(),
            'name' => $site->name(),
            'url' => $site->url(),
            'locale' => $site->locale(),
        ])->values()->all();
    }

    private function collections(UserContract $user): array
    {
        $collections = Collection::all()->keyBy->handle();

        return $this->sortedExposed('collections')
            ->filter(fn (string $handle) => $this->can($user, "view {$handle} entries"))
            ->map(function (string $handle) use ($collections, $user) {
                $collection = $collections->get($handle);

                $resource = [
                    'handle' => $handle,
                    'title' => $collection->title(),
                    'dated' => $collection->dated(),
                    'revisions' => $collection->revisionsEnabled(),
                    'blueprints' => $collection->entryBlueprints()->map->handle()->values()->all(),
                    'can_create' => $this->can($user, "create {$handle} entries"),
                    'can_edit' => $this->can($user, "edit {$handle} entries"),
                    'can_publish' => $this->can($user, "publish {$handle} entries"),
                ];

                if ($this->deletesEnabled()) {
                    $resource['can_delete'] = $this->can($user, "delete {$handle} entries");
                }

                return $resource;
            })
            ->values()
            ->all();
    }

    private function taxonomies(UserContract $user): array
    {
        $taxonomies = Taxonomy::all()->keyBy->handle();

        return $this->sortedExposed('taxonomies')
            ->filter(fn (string $handle) => $this->can($user, "view {$handle} terms"))
            ->map(function (string $handle) use ($taxonomies, $user) {
                $taxonomy = $taxonomies->get($handle);

                $resource = [
                    'handle' => $handle,
                    'title' => $taxonomy->title(),
                    'blueprints' => $taxonomy->termBlueprints()->map->handle()->values()->all(),
                    // no can_publish: v6 has no 'publish {taxonomy} terms' permission — terms have no status
                    'can_create' => $this->can($user, "create {$handle} terms"),
                    'can_edit' => $this->can($user, "edit {$handle} terms"),
                ];

                if ($this->deletesEnabled()) {
                    $resource['can_delete'] = $this->can($user, "delete {$handle} terms");
                }

                return $resource;
            })
            ->values()
            ->all();
    }

    private function globals(UserContract $user): array
    {
        $sets = GlobalSet::all()->keyBy->handle();

        return $this->sortedExposed('globals')
            // v6 has no 'view {global} globals' permission — the CP itself gates viewing on edit
            ->filter(fn (string $handle) => $this->can($user, "edit {$handle} globals"))
            ->map(fn (string $handle) => [
                'handle' => $handle,
                'title' => $sets->get($handle)->title(),
                'can_edit' => true, // the visibility filter above IS the edit-permission check
            ])
            ->values()
            ->all();
    }

    /**
     * @return SupportCollection<int, string> exposed handles, sorted for deterministic output
     */
    private function sortedExposed(string $type): SupportCollection
    {
        return collect($this->exposedHandles($type))->sort()->values();
    }

    private function can(UserContract $user, string $permission): bool
    {
        return $user->isSuper() || $user->hasPermission($permission);
    }
}
```

Key-order is load-bearing for the exact-fragment tests: collections build `handle, title, dated, revisions, blueprints, can_create, can_edit, can_publish[, can_delete]`; taxonomies build `handle, title, blueprints, can_create, can_edit[, can_delete]`; globals build `handle, title, can_edit`. Do not reorder keys.

- [ ] **Step 7: Run the test file — expect all green**

```
vendor/bin/pest tests/Feature/StatamicOverviewTest.php
```

Expected output: `PASS  Tests\Feature\StatamicOverviewTest` with all 8 test names listed, ending `Tests: 8 passed`.

If a fragment assertion fails, diff the actual output (add `->dump()` before the failing `assertSee` to print the raw tool response) against the expected fragment — the usual culprit is key order inside the built arrays, which must match Step 6 exactly.

- [ ] **Step 8: Run the full suite and the formatter**

```
vendor/bin/pest
```

Expected: every test from earlier tasks still passes plus the 8 new ones — `Tests: N passed` with zero failures.

```
composer format
```

Expected: Pint output ending in `PASS` (or listing the files it fixed — rerun `vendor/bin/pest` if it changed anything, expect all green again).

- [ ] **Step 9: Commit**

```
git add src/Server.php src/Tools/Tool.php src/Tools/ToolException.php src/Tools/StatamicOverview.php tests/Feature/StatamicOverviewTest.php
git commit -m "feat: add MCP Server, abstract base Tool, and statamic_overview" -m "Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

### Task 9: `blueprints_get` with bounded example generation

**Files:**
- Create: `src/Tools/BlueprintsGet.php`
- Modify: `src/Server.php`
- Test: `tests/Feature/BlueprintsGetTest.php`

Spec contract for this tool (spec §4 row 2): params `type` (collection|taxonomy|global), `handle`, optional `blueprint`. Returns fields via the **Fields API** (handle, type, rules, required, options, instructions) — never YAML parsing — plus a valid example payload. Example generation is bounded: real examples for text/textarea/markdown/slug, numeric (integer/float), toggle, date, select/radio/checkboxes (first option), and relation fields (obviously fake placeholders like `"REPLACE-WITH-REAL-ENTRY-ID"`); every other fieldtype (bard, replicator, grid, assets, …) falls back to `null` plus a note in `example_notes` keyed by field handle. Exposure is enforced via the base `ensureExposed()` — an existing-but-unexposed handle is indistinguishable from a missing one.

- [ ] **Step 1: Write the failing feature test**

Create `tests/Feature/BlueprintsGetTest.php` with this complete content. The `Fixtures::blog()` article blueprint (contracts §8) has exactly these fields in order: `title` (text, required), `content` (bard), `hero_image` (text), `topic` (terms relation) — the example fragments below depend on that order.

```php
<?php

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tools\BlueprintsGet;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;

it('returns fields and a bounded example payload for a collection blueprint', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $user = Fixtures::makeUser();

    Server::actingAs($user)
        ->tool(BlueprintsGet::class, ['type' => 'collection', 'handle' => 'blog'])
        ->assertOk()
        ->assertSee('"type":"collection","handle":"blog","blueprint":"article","available_blueprints":["article"]')
        ->assertSee('"handle":"title","type":"text","required":true')
        ->assertSee('"handle":"topic","type":"terms","required":false')
        ->assertSee('"example":{"title":"Example text","content":null,"hero_image":"Example text","topic":["REPLACE-WITH-REAL-TERM-ID"]}');
});

it('falls back to null plus a type note for a bard field', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $user = Fixtures::makeUser();

    Server::actingAs($user)
        ->tool(BlueprintsGet::class, ['type' => 'collection', 'handle' => 'blog'])
        ->assertOk()
        ->assertSee('"content":null')
        ->assertSee('"example_notes":{"content":"no example generated for fieldtype \'bard\' — read a real value from existing content before writing this field"}');
});

it('returns the blueprint of a taxonomy', function () {
    Fixtures::site();
    Fixtures::tags();

    $user = Fixtures::makeUser();

    Server::actingAs($user)
        ->tool(BlueprintsGet::class, ['type' => 'taxonomy', 'handle' => 'tags'])
        ->assertOk()
        ->assertSee('"type":"taxonomy","handle":"tags","blueprint":"tag","available_blueprints":["tag"]')
        ->assertSee('"example":{"title":"Example text"}');
});

it('returns the blueprint of a global set', function () {
    Fixtures::site();
    Fixtures::settings();

    $user = Fixtures::makeUser();

    Server::actingAs($user)
        ->tool(BlueprintsGet::class, ['type' => 'global', 'handle' => 'settings'])
        ->assertOk()
        ->assertSee('"type":"global","handle":"settings","blueprint":"settings","available_blueprints":["settings"]')
        ->assertSee('"handle":"site_name","type":"text","required":false')
        ->assertSee('"example":{"site_name":"Example text","footer_text":"Example text"}');
});

it('generates real examples for select, toggle, integer, and date fields', function () {
    Fixtures::site();

    Collection::make('pages')->title('Pages')->save();

    Blueprint::makeFromFields([
        'title' => ['type' => 'text', 'validate' => 'required'],
        'color' => ['type' => 'select', 'options' => ['red' => 'Red', 'blue' => 'Blue']],
        'featured' => ['type' => 'toggle'],
        'priority' => ['type' => 'integer'],
        'launch_date' => ['type' => 'date'],
    ])->setHandle('page')->setNamespace('collections.pages')->save();

    $user = Fixtures::makeUser();

    Server::actingAs($user)
        ->tool(BlueprintsGet::class, ['type' => 'collection', 'handle' => 'pages'])
        ->assertOk()
        ->assertSee('"options":{"red":"Red","blue":"Blue"}')
        ->assertSee('"example":{"title":"Example text","color":"red","featured":true,"priority":42,"launch_date":"2026-01-15"}');
});

it('treats unexposed and missing collections identically, listing only exposed handles', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    Collection::make('secrets')->title('Secrets')->save();

    config(['statamic.mcp.resources.collections' => ['blog']]);

    $user = Fixtures::makeUser();

    // exists but unexposed
    Server::actingAs($user)
        ->tool(BlueprintsGet::class, ['type' => 'collection', 'handle' => 'secrets'])
        ->assertHasErrors(["collection 'secrets' not found — available: blog"]);

    // does not exist at all — identical error shape
    Server::actingAs($user)
        ->tool(BlueprintsGet::class, ['type' => 'collection', 'handle' => 'nope'])
        ->assertHasErrors(["collection 'nope' not found — available: blog"]);
});

it('rejects an unknown blueprint handle, listing available blueprints', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $user = Fixtures::makeUser();

    Server::actingAs($user)
        ->tool(BlueprintsGet::class, ['type' => 'collection', 'handle' => 'blog', 'blueprint' => 'story'])
        ->assertHasErrors(["blueprint 'story' not found — available: article"]);
});

it('rejects an unknown type via validation', function () {
    Fixtures::site();

    $user = Fixtures::makeUser();

    Server::actingAs($user)
        ->tool(BlueprintsGet::class, ['type' => 'navigation', 'handle' => 'main'])
        ->assertHasErrors();
});
```

- [ ] **Step 2: Run the test, confirm it fails for the right reason**

```
vendor/bin/pest tests/Feature/BlueprintsGetTest.php
```

Expected: all 8 tests FAIL with `Error: Class "Danielgnh\StatamicMcp\Tools\BlueprintsGet" not found`. Output ends with `Tests: 8 failed`.

- [ ] **Step 3: Create `src/Tools/BlueprintsGet.php`**

Implementation notes baked in below: the resource is resolved through Statamic facades and its blueprints read via the Fields API (`entryBlueprints()` / `termBlueprints()` / `GlobalSet::blueprint()`) — never YAML (v6 blueprint YAML shape is `tabs:`, deliberately untouched). Rules are filtered to strings because closure/Rule-object rules are not JSON-serializable (the write tools' validator still enforces them). `options`/`instructions` keys appear only when configured, keeping the JSON compact.

```php
<?php

namespace Danielgnh\StatamicMcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection as SupportCollection;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Statamic\Facades\Collection;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Taxonomy;
use Statamic\Fields\Field;

#[Name('blueprints_get')]
#[Description('Returns a blueprint\'s fields (handle, type, rules, required, options, instructions) plus a valid example payload for writes. Pass type (collection|taxonomy|global) and the resource handle from statamic_overview; optionally a specific blueprint handle (defaults to the first). Relation-field examples are placeholders — replace them with real IDs. Fields with a null example carry a note in example_notes; read a real value from existing content for those.')]
#[IsReadOnly]
class BlueprintsGet extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()
                ->enum(['collection', 'taxonomy', 'global'])
                ->description('Resource type the handle belongs to.')
                ->required(),
            'handle' => $schema->string()
                ->description('Collection, taxonomy, or global set handle (see statamic_overview).')
                ->required(),
            'blueprint' => $schema->string()
                ->description("Blueprint handle. Defaults to the resource's first blueprint."),
        ];
    }

    protected function execute(Request $request): Response
    {
        $request->validate(
            [
                'type' => 'required|string|in:collection,taxonomy,global',
                'handle' => 'required|string',
                'blueprint' => 'nullable|string',
            ],
            [
                'type.in' => 'type must be one of: collection, taxonomy, global.',
            ],
        );

        $type = $request->get('type');
        $handle = $request->get('handle');

        $this->ensureExposed($this->configKey($type), $handle);

        $blueprints = $this->blueprintsFor($type, $handle);

        if ($blueprints->isEmpty()) {
            return Response::error(sprintf("%s '%s' has no blueprint defined", $type, $handle));
        }

        $requested = $request->get('blueprint');

        if ($requested !== null && ! $blueprints->has($requested)) {
            return $this->notFound('blueprint', $requested, $blueprints->keys()->all());
        }

        $blueprint = $requested === null ? $blueprints->first() : $blueprints->get($requested);

        $fields = [];
        $example = [];
        $notes = [];

        foreach ($blueprint->fields()->all() as $field) {
            $fields[] = $this->describe($field);

            [$value, $note] = $this->exampleFor($field);

            $example[$field->handle()] = $value;

            if ($note !== null) {
                $notes[$field->handle()] = $note;
            }
        }

        $payload = [
            'type' => $type,
            'handle' => $handle,
            'blueprint' => $blueprint->handle(),
            'available_blueprints' => $blueprints->keys()->values()->all(),
            'fields' => $fields,
            'example' => $example,
        ];

        if ($notes !== []) {
            $payload['example_notes'] = $notes;
        }

        return $this->json($payload);
    }

    /**
     * @return 'collections'|'taxonomies'|'globals'
     */
    private function configKey(string $type): string
    {
        return match ($type) {
            'collection' => 'collections',
            'taxonomy' => 'taxonomies',
            'global' => 'globals',
        };
    }

    /**
     * Blueprints of the resource, keyed by blueprint handle. ensureExposed()
     * already guaranteed the handle exists, so the lookups never return null.
     *
     * @return SupportCollection<string, \Statamic\Fields\Blueprint>
     */
    private function blueprintsFor(string $type, string $handle): SupportCollection
    {
        $blueprints = match ($type) {
            'collection' => Collection::findByHandle($handle)->entryBlueprints(),
            'taxonomy' => Taxonomy::findByHandle($handle)->termBlueprints(),
            'global' => collect([GlobalSet::findByHandle($handle)->blueprint()])->filter(),
        };

        return collect($blueprints)->keyBy(fn ($blueprint) => $blueprint->handle());
    }

    private function describe(Field $field): array
    {
        $config = $field->config();

        $descriptor = [
            'handle' => $field->handle(),
            'type' => $field->type(),
            'required' => $field->isRequired(),
            // closure/Rule-object rules are not JSON-serializable; writes still enforce them
            'rules' => array_values(array_filter($field->rules()[$field->handle()] ?? [], 'is_string')),
        ];

        if (isset($config['options'])) {
            $descriptor['options'] = $config['options'];
        }

        if (isset($config['instructions'])) {
            $descriptor['instructions'] = $config['instructions'];
        }

        return $descriptor;
    }

    /**
     * Bounded example generation (spec §4 row 2): real examples for a fixed
     * set of fieldtypes, obviously-fake placeholders for relation fields, and
     * a null + note fallback for everything else (bard, replicator, grid, …).
     *
     * @return array{0: mixed, 1: ?string} [example value, note or null]
     */
    private function exampleFor(Field $field): array
    {
        return match ($field->type()) {
            'text' => ['Example text', null],
            'textarea' => ['A longer example paragraph of plain text.', null],
            'markdown' => ["## Example Heading\n\nExample **markdown** body.", null],
            'slug' => ['example-slug', null],
            'integer' => [42, null],
            'float' => [3.14, null],
            'toggle' => [true, null],
            'date' => ['2026-01-15', null],
            'select', 'radio' => $this->firstOption($field),
            'checkboxes' => $this->firstOption($field, wrapInArray: true),
            'entries' => [['REPLACE-WITH-REAL-ENTRY-ID'], null],
            'terms' => [['REPLACE-WITH-REAL-TERM-ID'], null],
            'users' => [['REPLACE-WITH-REAL-USER-ID'], null],
            default => [null, sprintf(
                "no example generated for fieldtype '%s' — read a real value from existing content before writing this field",
                $field->type(),
            )],
        };
    }

    /**
     * First option of a select/radio/checkboxes field. Options may be an
     * associative map (value => label) or a plain list of values.
     *
     * @return array{0: mixed, 1: ?string}
     */
    private function firstOption(Field $field, bool $wrapInArray = false): array
    {
        $options = $field->config()['options'] ?? [];

        if (! is_array($options) || $options === []) {
            return [null, sprintf("fieldtype '%s' has no options configured", $field->type())];
        }

        $first = array_is_list($options) ? $options[0] : array_key_first($options);

        return [$wrapInArray ? [$first] : $first, null];
    }
}
```

- [ ] **Step 4: Register the tool on the Server**

In `src/Server.php`, apply exactly this change (Task 8 left the array with one entry):

```php
    protected array $tools = [
        Tools\StatamicOverview::class,
        Tools\BlueprintsGet::class,
    ];
```

- [ ] **Step 5: Run the test file — expect all green**

```
vendor/bin/pest tests/Feature/BlueprintsGetTest.php
```

Expected output: `PASS  Tests\Feature\BlueprintsGetTest` with all 8 test names listed, ending `Tests: 8 passed`.

If the example fragment fails, add `->dump()` before the failing `assertSee` and compare the actual `"example"` object — field order follows blueprint field order, and the payload key order is fixed: `type, handle, blueprint, available_blueprints, fields, example[, example_notes]`. Do not reorder keys.

- [ ] **Step 6: Run the full suite and the formatter**

```
vendor/bin/pest
```

Expected: everything passes — the 8 overview tests, the 8 new blueprint tests, and all earlier tasks' tests: `Tests: N passed`, zero failures.

```
composer format
```

Expected: Pint output ending in `PASS` (or listing fixed files — rerun `vendor/bin/pest` if it changed anything, expect all green again).

- [ ] **Step 7: Commit**

```
git add src/Server.php src/Tools/BlueprintsGet.php tests/Feature/BlueprintsGetTest.php
git commit -m "feat: add blueprints_get tool with bounded example generation" -m "Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```


### Task 10: entries_list — summary listing with pagination, status filter, site access

Prerequisites from earlier tasks (must already exist): `src/Tools/Tool.php` + `src/Tools/ToolException.php` (contracts §5), `src/Server.php` (contracts §4, currently registering `StatamicOverview` + `BlueprintsGet`), `tests/TestCase.php`, `tests/Pest.php`, `tests/Support/Fixtures.php` (contracts §8).

**Files:**
- Create: `src/Tools/Concerns/ResolvesSites.php`
- Create: `src/Tools/EntriesList.php`
- Modify: `src/Server.php`
- Test: `tests/Feature/EntriesListTest.php`

- [ ] **Step 1: Write the failing feature test**

Create `tests/Feature/EntriesListTest.php` with exactly this content. The first two tests are copied verbatim from the contracts file §8 (they are the canonical pattern):

```php
<?php

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tools\EntriesList;
use Statamic\Facades\Entry;

it('lists entries in an exposed collection', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    Entry::make()
        ->collection('blog')
        ->slug('hello-world')
        ->data(['title' => 'Hello World'])
        ->published(true)
        ->save();

    $user = Fixtures::makeUser('view blog entries');

    Server::actingAs($user)
        ->tool(EntriesList::class, ['collection' => 'blog'])
        ->assertOk()
        ->assertSee('hello-world');
});

it('denies listing without the view permission', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $user = Fixtures::makeUser(); // 'access mcp' only

    Server::actingAs($user)
        ->tool(EntriesList::class, ['collection' => 'blog'])
        ->assertHasErrors(["requires 'view blog entries' — grant it to a role of {$user->email()} in the Control Panel"]);
});

it('returns summary columns for each entry', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    Entry::make()
        ->collection('blog')
        ->slug('summary-post')
        ->data(['title' => 'Summary Post'])
        ->published(true)
        ->save();

    Server::actingAs(Fixtures::makeUser('view blog entries'))
        ->tool(EntriesList::class, ['collection' => 'blog'])
        ->assertOk()
        ->assertSee('"slug":"summary-post"')
        ->assertSee('"title":"Summary Post"')
        ->assertSee('"status":"published"')
        ->assertSee('"url"')
        ->assertSee('"updated_at"');
});

it('filters by status via whereStatus', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    Entry::make()->collection('blog')->slug('live-post')->data(['title' => 'Live'])->published(true)->save();
    Entry::make()->collection('blog')->slug('draft-post')->data(['title' => 'Draft'])->published(false)->save();

    // total:1 proves the published entry was excluded (no negative assertion needed)
    Server::actingAs(Fixtures::makeUser('view blog entries'))
        ->tool(EntriesList::class, ['collection' => 'blog', 'status' => 'draft'])
        ->assertOk()
        ->assertSee('draft-post')
        ->assertSee('"total":1');
});

it('paginates with totals and a next-page hint, capping per_page at 100', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    foreach (range(1, 3) as $i) {
        Entry::make()
            ->collection('blog')
            ->slug("post-{$i}")
            ->data(['title' => "Post {$i}"])
            ->published(true)
            ->save();
    }

    $user = Fixtures::makeUser('view blog entries');

    Server::actingAs($user)
        ->tool(EntriesList::class, ['collection' => 'blog', 'limit' => 2, 'page' => 1])
        ->assertOk()
        ->assertSee('"total":3')
        ->assertSee('"total_pages":2')
        ->assertSee('"next_page":2');

    Server::actingAs($user)
        ->tool(EntriesList::class, ['collection' => 'blog', 'limit' => 2, 'page' => 2])
        ->assertOk()
        ->assertSee('"next_page":null');

    Server::actingAs($user)
        ->tool(EntriesList::class, ['collection' => 'blog', 'limit' => 500])
        ->assertOk()
        ->assertSee('"per_page":100');
});

it('treats an unexposed collection as not found', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    config(['statamic.mcp.resources.collections' => ['pages']]); // 'blog' exists but is not exposed

    Server::actingAs(Fixtures::makeSuper())
        ->tool(EntriesList::class, ['collection' => 'blog'])
        ->assertHasErrors(["collection 'blog' not found — available: (none exposed)"]);
});

it('rejects an unknown site naming the available ones', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    Server::actingAs(Fixtures::makeSuper())
        ->tool(EntriesList::class, ['collection' => 'blog', 'site' => 'fr'])
        ->assertHasErrors(["site 'fr' not found — available: en"]);
});

it("requires 'access {site} site' for non-default sites", function () {
    Fixtures::multisite();
    Fixtures::tags();
    Fixtures::blog();

    $user = Fixtures::makeUser('view blog entries');

    Server::actingAs($user)
        ->tool(EntriesList::class, ['collection' => 'blog', 'site' => 'de'])
        ->assertHasErrors(["requires 'access de site' — grant it to a role of {$user->email()} in the Control Panel"]);

    Server::actingAs(Fixtures::makeUser('view blog entries', 'access de site'))
        ->tool(EntriesList::class, ['collection' => 'blog', 'site' => 'de'])
        ->assertOk()
        ->assertSee('"total":0');
});
```

- [ ] **Step 2: Run the test — expect failure**

```bash
vendor/bin/pest tests/Feature/EntriesListTest.php
```

Expected: 8 failing tests, each erroring with `Error: Class "Danielgnh\StatamicMcp\Tools\EntriesList" not found`.

- [ ] **Step 3: Create the ResolvesSites concern**

Create `src/Tools/Concerns/ResolvesSites.php`:

```php
<?php

namespace Danielgnh\StatamicMcp\Tools\Concerns;

use Danielgnh\StatamicMcp\Tools\ToolException;
use Laravel\Mcp\Request;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Facades\Site;

trait ResolvesSites
{
    /**
     * The requested site (default: Site::default()), validated against the
     * configured sites, with 'access {site} site' enforced for non-default
     * sites on multisite installs (spec §6).
     */
    protected function resolveSite(Request $request, UserContract $user): string
    {
        $site = $request->get('site') ?? Site::default()->handle();

        $handles = Site::all()->map->handle()->values()->all();

        if (! in_array($site, $handles, true)) {
            sort($handles);

            throw new ToolException(sprintf(
                "site '%s' not found — available: %s",
                $site,
                implode(', ', $handles),
            ));
        }

        $this->ensureSiteAccess($user, $site);

        return $site;
    }

    /**
     * The 'access {site} site' permission only exists on multisite installs
     * (verified facts §3) — never check it on single-site.
     */
    protected function ensureSiteAccess(UserContract $user, string $site): void
    {
        if (! Site::multiEnabled() || $site === Site::default()->handle()) {
            return;
        }

        $this->ensurePermission($user, "access {$site} site");
    }
}
```

- [ ] **Step 4: Create the EntriesList tool**

Create `src/Tools/EntriesList.php`:

```php
<?php

namespace Danielgnh\StatamicMcp\Tools;

use Danielgnh\StatamicMcp\Tools\Concerns\ResolvesSites;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Statamic\Facades\Entry;

#[Name('entries_list')]
#[Description('List entries in a collection — summary columns only (id, title, slug, status, url, date, updated_at); field data is never included, use entries_get for that. Paginated: the response carries total, total_pages, and next_page (null on the last page).')]
#[IsReadOnly]
class EntriesList extends Tool
{
    use ResolvesSites;

    public function schema(JsonSchema $schema): array
    {
        return [
            'collection' => $schema->string()->description('Collection handle — see statamic_overview for what is available.')->required(),
            'site' => $schema->string()->description('Site handle. Defaults to the default site.'),
            'status' => $schema->string()->enum(['published', 'draft', 'scheduled'])->description('Filter by status.'),
            'search' => $schema->string()->description('Only entries whose title contains this text.'),
            'limit' => $schema->integer()->description('Page size. Defaults to the server default (25); hard-capped at 100.'),
            'page' => $schema->integer()->default(1)->description('Page number, starting at 1.'),
        ];
    }

    protected function execute(Request $request): Response
    {
        $validated = $request->validate(
            [
                'collection' => 'required|string',
                'status' => 'nullable|string|in:published,draft,scheduled',
                'limit' => 'nullable|integer|min:1',
                'page' => 'nullable|integer|min:1',
            ],
            [
                'collection.required' => 'Pass a collection handle, e.g. "blog" — see statamic_overview.',
                'status.in' => 'status must be one of: published, draft, scheduled.',
            ],
        );

        $collection = $validated['collection'];
        $this->ensureExposed('collections', $collection);

        $user = $this->user($request);
        $this->ensurePermission($user, "view {$collection} entries");

        $site = $this->resolveSite($request, $user);

        $perPage = min((int) ($request->get('limit') ?? config('statamic.mcp.per_page', 25)), 100);
        $perPage = max($perPage, 1);
        $page = max((int) $request->get('page', 1), 1);

        $query = Entry::query()
            ->where('collection', $collection)
            ->where('site', $site);

        if ($status = $request->get('status')) {
            $query->whereStatus($status); // v6: never where('status', ...)
        }

        if ($search = $request->get('search')) {
            $query->where('title', 'like', '%'.$search.'%');
        }

        $total = (clone $query)->count();
        $totalPages = max((int) ceil($total / $perPage), 1);

        $entries = $query->offset(($page - 1) * $perPage)->limit($perPage)->get();

        return $this->json([
            'entries' => $entries->map(fn ($entry) => [
                'id' => $entry->id(),
                'title' => $entry->get('title') ?? ($entry->hasOrigin() ? $entry->origin()->get('title') : null),
                'slug' => $entry->slug(),
                'status' => $entry->status(),
                'url' => $entry->url(),
                'date' => $entry->collection()->dated() ? $entry->date()?->toIso8601String() : null,
                'updated_at' => $entry->lastModified()?->toIso8601String(),
            ])->values()->all(),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $totalPages,
                'next_page' => $page < $totalPages ? $page + 1 : null,
            ],
        ]);
    }
}
```

- [ ] **Step 5: Register the tool on the server**

In `src/Server.php`, add `Tools\EntriesList::class,` to the `$tools` array directly after `Tools\BlueprintsGet::class,`:

```php
    protected array $tools = [
        Tools\StatamicOverview::class,
        Tools\BlueprintsGet::class,
        Tools\EntriesList::class,
    ];
```

(Later tasks append the remaining entry/term/global tools; the final order is fixed in contracts §4.)

- [ ] **Step 6: Run the tests — expect pass**

```bash
vendor/bin/pest tests/Feature/EntriesListTest.php
```

Expected: `Tests: 8 passed`. Then run the whole suite to confirm nothing regressed:

```bash
vendor/bin/pest
```

Expected: all tests pass.

- [ ] **Step 7: Format and commit**

```bash
composer format
git add -A && git commit -m "$(cat <<'COMMIT'
feat: add entries_list tool with pagination and status filtering

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>
COMMIT
)"
```

### Task 11: entries_get — raw/augmented formats, field selection, rich-text previews

**Files:**
- Create: `src/Tools/Concerns/ResolvesEntries.php`
- Create: `src/Tools/EntriesGet.php`
- Modify: `src/Server.php`
- Test: `tests/Feature/EntriesGetTest.php`

- [ ] **Step 1: Write the failing feature test**

Create `tests/Feature/EntriesGetTest.php`. (The helper function has a task-specific name because Pest test files share one global namespace — never reuse plain function names across test files.)

```php
<?php

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tools\EntriesGet;
use Statamic\Facades\Entry;

function makeBlogEntryForGet(array $data = [], string $slug = 'hello-world'): \Statamic\Contracts\Entries\Entry
{
    return tap(
        Entry::make()
            ->collection('blog')
            ->slug($slug)
            ->data(array_merge(['title' => 'Hello World'], $data))
            ->published(true)
    )->save();
}

function longBardValue(): array
{
    // ~1400 chars encoded — comfortably over the 500-char preview threshold
    return [[
        'type' => 'paragraph',
        'content' => [['type' => 'text', 'text' => str_repeat('Statamic and MCP together at last. ', 40)]],
    ]];
}

it('returns raw entry data by id', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $entry = makeBlogEntryForGet(['hero_image' => 'hero.jpg']);

    Server::actingAs(Fixtures::makeUser('view blog entries'))
        ->tool(EntriesGet::class, ['id' => $entry->id()])
        ->assertOk()
        ->assertSee('"format":"raw"')
        ->assertSee('"title":"Hello World"')
        ->assertSee('"hero_image":"hero.jpg"')
        ->assertSee('"status":"published"')
        ->assertSee('"cp_edit_url"');
});

it('finds an entry by collection and slug', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $entry = makeBlogEntryForGet();

    Server::actingAs(Fixtures::makeUser('view blog entries'))
        ->tool(EntriesGet::class, ['collection' => 'blog', 'slug' => 'hello-world'])
        ->assertOk()
        ->assertSee($entry->id());
});

it('errors when neither id nor collection + slug is given', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    Server::actingAs(Fixtures::makeSuper())
        ->tool(EntriesGet::class, [])
        ->assertHasErrors(['pass id, or collection + slug, to identify the entry']);
});

it('returns augmented data with a do-not-write-back warning when requested', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $entry = makeBlogEntryForGet();

    Server::actingAs(Fixtures::makeUser('view blog entries'))
        ->tool(EntriesGet::class, ['id' => $entry->id(), 'format' => 'augmented'])
        ->assertOk()
        ->assertSee('"format":"augmented"')
        ->assertSee('NEVER send it back into entries_update');
});

it('truncates long bard values to preview objects', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $entry = makeBlogEntryForGet(['content' => longBardValue()]);

    Server::actingAs(Fixtures::makeUser('view blog entries'))
        ->tool(EntriesGet::class, ['id' => $entry->id()])
        ->assertOk()
        ->assertSee('__preview')
        ->assertSee('"truncated":true')
        ->assertSee('NOT writable — fetch raw field before editing');
});

it('returns the full raw bard value when requested via fields', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $entry = makeBlogEntryForGet(['content' => longBardValue()]);

    Server::actingAs(Fixtures::makeUser('view blog entries'))
        ->tool(EntriesGet::class, ['id' => $entry->id(), 'fields' => ['content']])
        ->assertOk()
        ->assertSee('"type":"paragraph"')
        ->assertSee('"type":"text"');
});

it('rejects unknown field handles naming valid ones', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $entry = makeBlogEntryForGet();

    Server::actingAs(Fixtures::makeUser('view blog entries'))
        ->tool(EntriesGet::class, ['id' => $entry->id(), 'fields' => ['bodyy']])
        ->assertHasErrors(['unknown field bodyy — valid handles: content, hero_image, title, topic']);
});

it('treats an entry in an unexposed collection as not found', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $entry = makeBlogEntryForGet();

    config(['statamic.mcp.resources.collections' => []]);

    Server::actingAs(Fixtures::makeSuper())
        ->tool(EntriesGet::class, ['id' => $entry->id()])
        ->assertHasErrors(["entry '{$entry->id()}' not found"]);
});

it('denies reading without the view permission', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $entry = makeBlogEntryForGet();
    $user = Fixtures::makeUser(); // 'access mcp' only

    Server::actingAs($user)
        ->tool(EntriesGet::class, ['id' => $entry->id()])
        ->assertHasErrors(["requires 'view blog entries' — grant it to a role of {$user->email()} in the Control Panel"]);
});
```

- [ ] **Step 2: Run the test — expect failure**

```bash
vendor/bin/pest tests/Feature/EntriesGetTest.php
```

Expected: 9 failing tests, each erroring with `Error: Class "Danielgnh\StatamicMcp\Tools\EntriesGet" not found`.

- [ ] **Step 3: Create the ResolvesEntries concern**

Create `src/Tools/Concerns/ResolvesEntries.php`:

```php
<?php

namespace Danielgnh\StatamicMcp\Tools\Concerns;

use Danielgnh\StatamicMcp\Tools\ToolException;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry;

trait ResolvesEntries
{
    /**
     * Missing and exists-but-unexposed are indistinguishable by design
     * (spec §4 / §6 layer 2).
     */
    protected function findExposedEntry(string $id): EntryContract
    {
        $entry = Entry::find($id);

        if (! $entry || ! in_array($entry->collection()->handle(), $this->exposedHandles('collections'), true)) {
            throw new ToolException("entry '{$id}' not found");
        }

        return $entry;
    }
}
```

- [ ] **Step 4: Create the EntriesGet tool**

Create `src/Tools/EntriesGet.php` (multi-site precedence and inheritance annotation land in Task 12 — this version is single-site complete):

```php
<?php

namespace Danielgnh\StatamicMcp\Tools;

use Danielgnh\StatamicMcp\Tools\Concerns\ResolvesEntries;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use JsonSerializable;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Fields\Blueprint;

#[Name('entries_get')]
#[Description('Get a single entry by id, or by collection + slug. Returns raw field data by default — the round-trippable shape for entries_update. format=augmented returns rendered values for display only: NEVER send augmented data back into entries_update. Long Bard/rich-text values are truncated to preview objects unless requested via fields (an array of top-level field handles; no nesting in v1).')]
#[IsReadOnly]
class EntriesGet extends Tool
{
    use ResolvesEntries;

    private const PREVIEW_THRESHOLD = 500; // chars of encoded JSON before truncation

    private const PREVIEW_LENGTH = 300;    // chars of plain-text preview kept

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('Entry id. Either id, or collection + slug, is required.'),
            'collection' => $schema->string()->description('Collection handle — used with slug when id is omitted.'),
            'slug' => $schema->string()->description('Entry slug — used with collection when id is omitted.'),
            'site' => $schema->string()->description("Site handle. With an id it must match that entry's own site (omit it otherwise); with collection + slug it selects the localization. Defaults to the default site."),
            'format' => $schema->string()->enum(['raw', 'augmented'])->description('raw (default): $entry->data(), writable. augmented: rendered values, display only — never writable.'),
            'fields' => $schema->array()->description('Top-level field handles to return in full — Bard/rich-text fields listed here skip preview truncation.'),
        ];
    }

    protected function execute(Request $request): Response
    {
        $user = $this->user($request);
        $entry = $this->resolveEntry($request);

        $collection = $entry->collection()->handle();
        $this->ensurePermission($user, "view {$collection} entries");

        $format = $request->get('format') ?? 'raw';
        $requestedFields = array_values((array) $request->get('fields', []));
        $blueprint = $entry->blueprint();

        $this->assertKnownFields($requestedFields, $blueprint);

        $data = $format === 'augmented'
            ? $entry->toAugmentedArray() // shallow, display only (spec §4 row 4)
            : $entry->data()->all();     // raw: the round-trippable write shape

        if ($requestedFields !== []) {
            $data = array_intersect_key($data, array_flip($requestedFields));
        }

        $data = $this->withRichTextPreviews($data, $blueprint, $requestedFields);

        $response = [
            'id' => $entry->id(),
            'collection' => $collection,
            'slug' => $entry->slug(),
            'site' => $entry->locale(),
            'status' => $entry->status(),
            'published' => $entry->published(),
            'url' => $entry->url(),
            'format' => $format,
            'data' => $data,
            'cp_edit_url' => $entry->editUrl(),
        ];

        if ($entry->collection()->dated()) {
            $response['date'] = $entry->date()?->toIso8601String();
        }

        if ($format === 'augmented') {
            $response['warning'] = 'augmented data is rendered for display — NEVER send it back into entries_update; fetch raw first';
        }

        return $this->json($response);
    }

    private function resolveEntry(Request $request): EntryContract
    {
        if ($id = $request->get('id')) {
            return $this->findExposedEntry((string) $id);
        }

        $collection = $request->get('collection');
        $slug = $request->get('slug');

        if (! $collection || ! $slug) {
            throw new ToolException('pass id, or collection + slug, to identify the entry');
        }

        $this->ensureExposed('collections', (string) $collection);

        $site = $request->get('site') ?? Site::default()->handle();
        $handles = Site::all()->map->handle()->values()->all();

        if (! in_array($site, $handles, true)) {
            sort($handles);

            throw new ToolException(sprintf("site '%s' not found — available: %s", $site, implode(', ', $handles)));
        }

        $entry = Entry::query()
            ->where('collection', $collection)
            ->where('slug', $slug)
            ->where('site', $site)
            ->first();

        if (! $entry) {
            throw new ToolException(sprintf("entry '%s/%s' not found in site '%s'", $collection, $slug, $site));
        }

        return $entry;
    }

    private function assertKnownFields(array $requestedFields, Blueprint $blueprint): void
    {
        if ($requestedFields === []) {
            return;
        }

        $handles = $blueprint->fields()->all()->keys()->all();
        $unknown = array_values(array_diff($requestedFields, $handles));

        if ($unknown === []) {
            return;
        }

        sort($handles);

        throw new ToolException(sprintf(
            'unknown field%s %s — valid handles: %s',
            count($unknown) === 1 ? '' : 's',
            implode(', ', $unknown),
            implode(', ', $handles),
        ));
    }

    /**
     * Long Bard/markdown values become {__preview, truncated, note} objects
     * unless explicitly requested via fields (spec §4 row 4).
     */
    private function withRichTextPreviews(array $data, Blueprint $blueprint, array $requestedFields): array
    {
        foreach ($data as $handle => $value) {
            if (in_array($handle, $requestedFields, true)) {
                continue;
            }

            $field = $blueprint->fields()->all()->get($handle);

            if (! $field || ! in_array($field->type(), ['bard', 'markdown'], true)) {
                continue;
            }

            // Augmented values are JsonSerializable wrappers; normalize before measuring.
            $raw = $value instanceof JsonSerializable ? $value->jsonSerialize() : $value;

            $encoded = json_encode($raw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if ($encoded === false || strlen($encoded) <= self::PREVIEW_THRESHOLD) {
                continue;
            }

            $data[$handle] = [
                '__preview' => Str::limit($this->plainText($raw), self::PREVIEW_LENGTH),
                'truncated' => true,
                'note' => sprintf('NOT writable — fetch raw field before editing: entries_get with fields: ["%s"]', $handle),
            ];
        }

        return $data;
    }

    /**
     * Extract readable text from a ProseMirror document (Bard stores
     * {type, content: [{type: text, text: ...}]} trees) or pass strings through.
     */
    private function plainText(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (! is_array($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $text = '';

        array_walk_recursive($value, function ($item, $key) use (&$text) {
            if ($key === 'text' && is_string($item)) {
                $text .= $item.' ';
            }
        });

        return trim($text);
    }
}
```

- [ ] **Step 5: Register the tool on the server**

In `src/Server.php`, add `Tools\EntriesGet::class,` to the `$tools` array directly after `Tools\EntriesList::class,`:

```php
    protected array $tools = [
        Tools\StatamicOverview::class,
        Tools\BlueprintsGet::class,
        Tools\EntriesList::class,
        Tools\EntriesGet::class,
    ];
```

- [ ] **Step 6: Run the tests — expect pass**

```bash
vendor/bin/pest tests/Feature/EntriesGetTest.php
```

Expected: `Tests: 9 passed`. Then:

```bash
vendor/bin/pest
```

Expected: all tests pass.

- [ ] **Step 7: Format and commit**

```bash
composer format
git add -A && git commit -m "$(cat <<'COMMIT'
feat: add entries_get tool with raw/augmented formats and rich-text previews

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>
COMMIT
)"
```

### Task 12: entries_get multi-site — site/id precedence, origin annotation, site permission

**Files:**
- Modify: `src/Tools/Concerns/ResolvesEntries.php`
- Modify: `src/Tools/EntriesGet.php`
- Test: `tests/Feature/EntriesGetMultisiteTest.php`

- [ ] **Step 1: Write the failing feature test**

Create `tests/Feature/EntriesGetMultisiteTest.php`:

```php
<?php

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tools\EntriesGet;
use Statamic\Facades\Entry;

/**
 * @return array{0: \Statamic\Contracts\Entries\Entry, 1: \Statamic\Contracts\Entries\Entry} [origin (en), localization (de)]
 */
function makeLocalizedBlogEntry(): array
{
    $origin = tap(
        Entry::make()
            ->collection('blog')
            ->slug('hello')
            ->locale('en')
            ->data(['title' => 'Hello', 'hero_image' => 'hero.jpg'])
            ->published(true)
    )->save();

    // de overrides only the title; hero_image stays inherited from the origin
    $localization = tap(
        $origin->makeLocalization('de')->data(['title' => 'Hallo'])
    )->save();

    return [$origin, $localization];
}

it('rejects a site that does not match the entry id, listing localization ids', function () {
    Fixtures::multisite();
    Fixtures::tags();
    Fixtures::blog();

    [$origin, $localization] = makeLocalizedBlogEntry();

    Server::actingAs(Fixtures::makeSuper())
        ->tool(EntriesGet::class, ['id' => $origin->id(), 'site' => 'de'])
        ->assertHasErrors([
            "entry '{$origin->id()}' belongs to site 'en', not 'de' — pass the matching localization id instead (or omit site). Localizations: en => {$origin->id()}; de => {$localization->id()}",
        ]);
});

it('accepts a site that matches the entry id', function () {
    Fixtures::multisite();
    Fixtures::tags();
    Fixtures::blog();

    [$origin] = makeLocalizedBlogEntry();

    Server::actingAs(Fixtures::makeSuper())
        ->tool(EntriesGet::class, ['id' => $origin->id(), 'site' => 'en'])
        ->assertOk()
        ->assertSee('"site":"en"');
});

it('annotates inherited vs local fields with the origin id', function () {
    Fixtures::multisite();
    Fixtures::tags();
    Fixtures::blog();

    [$origin, $localization] = makeLocalizedBlogEntry();

    Server::actingAs(Fixtures::makeSuper())
        ->tool(EntriesGet::class, ['id' => $localization->id()])
        ->assertOk()
        ->assertSee('"origin_id":"'.$origin->id().'"')
        ->assertSee('"local_overrides":["title"]')
        ->assertSee('"inherited_from_origin":["hero_image"]')
        ->assertSee('"hero_image":"hero.jpg"')  // inherited value is shown
        ->assertSee('"title":"Hallo"');         // local override wins
});

it("requires 'access {site} site' to read a non-default-site entry by id", function () {
    Fixtures::multisite();
    Fixtures::tags();
    Fixtures::blog();

    [, $localization] = makeLocalizedBlogEntry();

    $user = Fixtures::makeUser('view blog entries');

    Server::actingAs($user)
        ->tool(EntriesGet::class, ['id' => $localization->id()])
        ->assertHasErrors(["requires 'access de site' — grant it to a role of {$user->email()} in the Control Panel"]);

    Server::actingAs(Fixtures::makeUser('view blog entries', 'access de site'))
        ->tool(EntriesGet::class, ['id' => $localization->id()])
        ->assertOk();
});

it('selects the localization via site with collection + slug', function () {
    Fixtures::multisite();
    Fixtures::tags();
    Fixtures::blog();

    makeLocalizedBlogEntry();

    Server::actingAs(Fixtures::makeSuper())
        ->tool(EntriesGet::class, ['collection' => 'blog', 'slug' => 'hello', 'site' => 'de'])
        ->assertOk()
        ->assertSee('"site":"de"')
        ->assertSee('"title":"Hallo"');
});
```

- [ ] **Step 2: Run the test — expect failure**

```bash
vendor/bin/pest tests/Feature/EntriesGetMultisiteTest.php
```

Expected: the first test fails (`assertHasErrors` finds no error — the mismatched site is silently ignored), the third fails (no `origin_id` in the output), and the fourth fails (no `access de site` denial). The other two pass already. Roughly: `Tests: 3 failed, 2 passed`.

- [ ] **Step 3: Add the site/id precedence guard to ResolvesEntries**

Replace `src/Tools/Concerns/ResolvesEntries.php` with:

```php
<?php

namespace Danielgnh\StatamicMcp\Tools\Concerns;

use Danielgnh\StatamicMcp\Tools\ToolException;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry;

trait ResolvesEntries
{
    /**
     * Missing and exists-but-unexposed are indistinguishable by design
     * (spec §4 / §6 layer 2).
     */
    protected function findExposedEntry(string $id): EntryContract
    {
        $entry = Entry::find($id);

        if (! $entry || ! in_array($entry->collection()->handle(), $this->exposedHandles('collections'), true)) {
            throw new ToolException("entry '{$id}' not found");
        }

        return $entry;
    }

    /**
     * Site/id precedence (spec §4 intro): with an id, site must be omitted
     * or match that entry's own site — a mismatch errors with the sibling
     * localization ids to use instead. Never creates or moves localizations.
     */
    protected function assertSiteMatchesEntry(EntryContract $entry, ?string $site): void
    {
        if ($site === null || $site === $entry->locale()) {
            return;
        }

        $siblings = $entry->collection()->sites()
            ->map(fn (string $handle) => $entry->in($handle))
            ->filter()
            ->map(fn ($localization) => $localization->locale().' => '.$localization->id())
            ->values()
            ->all();

        throw new ToolException(sprintf(
            "entry '%s' belongs to site '%s', not '%s' — pass the matching localization id instead (or omit site). Localizations: %s",
            $entry->id(),
            $entry->locale(),
            $site,
            implode('; ', $siblings),
        ));
    }
}
```

- [ ] **Step 4: Wire precedence, site permission, and origin annotation into EntriesGet**

Make three changes to `src/Tools/EntriesGet.php`.

(a) Add the `ResolvesSites` trait (for `ensureSiteAccess`) next to the existing one, and import it:

```php
use Danielgnh\StatamicMcp\Tools\Concerns\ResolvesEntries;
use Danielgnh\StatamicMcp\Tools\Concerns\ResolvesSites;
```

```php
class EntriesGet extends Tool
{
    use ResolvesEntries;
    use ResolvesSites;
```

(b) In `resolveEntry()`, enforce precedence on the id path — replace the id branch:

```php
        if ($id = $request->get('id')) {
            return $this->findExposedEntry((string) $id);
        }
```

with:

```php
        if ($id = $request->get('id')) {
            $entry = $this->findExposedEntry((string) $id);
            $this->assertSiteMatchesEntry($entry, $request->get('site'));

            return $entry;
        }
```

(c) In `execute()`, replace everything from the permission check down to the `fields` filter — i.e. these lines:

```php
        $collection = $entry->collection()->handle();
        $this->ensurePermission($user, "view {$collection} entries");

        $format = $request->get('format') ?? 'raw';
        $requestedFields = array_values((array) $request->get('fields', []));
        $blueprint = $entry->blueprint();

        $this->assertKnownFields($requestedFields, $blueprint);

        $data = $format === 'augmented'
            ? $entry->toAugmentedArray() // shallow, display only (spec §4 row 4)
            : $entry->data()->all();     // raw: the round-trippable write shape

        if ($requestedFields !== []) {
            $data = array_intersect_key($data, array_flip($requestedFields));
        }
```

with:

```php
        $collection = $entry->collection()->handle();
        $this->ensurePermission($user, "view {$collection} entries");
        $this->ensureSiteAccess($user, $entry->locale());

        $format = $request->get('format') ?? 'raw';
        $requestedFields = array_values((array) $request->get('fields', []));
        $blueprint = $entry->blueprint();

        $this->assertKnownFields($requestedFields, $blueprint);

        $localization = null;

        if ($format === 'augmented') {
            $data = $entry->toAugmentedArray(); // shallow, display only (spec §4 row 4)
        } else {
            $data = $entry->data()->all(); // raw: the round-trippable write shape

            if ($entry->hasOrigin()) {
                $origin = $entry->origin();
                $inherited = array_diff_key($origin->data()->all(), $data);

                $localization = [
                    'origin_id' => $origin->id(),
                    'local_overrides' => array_keys($data),
                    'inherited_from_origin' => array_keys($inherited),
                    'note' => 'inherited fields are shown from the origin — sending one back in entries_update makes it a local override',
                ];

                $data = array_merge($inherited, $data); // effective values, local wins
            }
        }

        if ($requestedFields !== []) {
            $data = array_intersect_key($data, array_flip($requestedFields));
        }
```

and at the bottom of `execute()`, just before `return $this->json($response);`, add:

```php
        if ($localization !== null) {
            $response['localization'] = $localization;
        }
```

- [ ] **Step 5: Run the tests — expect pass**

```bash
vendor/bin/pest tests/Feature/EntriesGetMultisiteTest.php tests/Feature/EntriesGetTest.php
```

Expected: `Tests: 14 passed`. Then:

```bash
vendor/bin/pest
```

Expected: all tests pass.

- [ ] **Step 6: Format and commit**

```bash
composer format
git add -A && git commit -m "$(cat <<'COMMIT'
feat: add multi-site handling to entries_get (site/id precedence, origin annotation)

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>
COMMIT
)"
```

### Task 13: entries_create — draft default, slug generation, publish gate, blueprint validation

**Files:**
- Create: `src/Tools/Concerns/ValidatesBlueprintData.php`
- Create: `src/Tools/EntriesCreate.php`
- Modify: `src/Server.php`
- Test: `tests/Feature/EntriesCreateTest.php`

- [ ] **Step 1: Write the failing feature test**

Create `tests/Feature/EntriesCreateTest.php`:

```php
<?php

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tools\EntriesCreate;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

function makeDatedEventsCollection(): void
{
    tap(
        Collection::make('events')
            ->title('Events')
            ->dated(true)
            ->sites(['en'])
            ->routes('/events/{slug}')
    )->save();

    Blueprint::makeFromFields([
        'title' => ['type' => 'text', 'validate' => 'required'],
    ])->setHandle('event')->setNamespace('collections.events')->save();
}

it('creates a draft by default with a slug generated from the title', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    Server::actingAs(Fixtures::makeUser('create blog entries'))
        ->tool(EntriesCreate::class, ['collection' => 'blog', 'data' => ['title' => 'My First Post']])
        ->assertOk()
        ->assertSee('"slug":"my-first-post"')
        ->assertSee('"status":"draft"')
        ->assertSee('saved as draft — not live')
        ->assertSee('"cp_edit_url"');

    $entry = Entry::query()->where('collection', 'blog')->where('slug', 'my-first-post')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->published())->toBeFalse()
        ->and($entry->get('title'))->toBe('My First Post');
});

it("requires 'publish blog entries' for published: true", function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $user = Fixtures::makeUser('create blog entries');

    Server::actingAs($user)
        ->tool(EntriesCreate::class, ['collection' => 'blog', 'data' => ['title' => 'Live Post'], 'published' => true])
        ->assertHasErrors(["requires 'publish blog entries' — grant it to a role of {$user->email()} in the Control Panel"]);

    Server::actingAs(Fixtures::makeUser('create blog entries', 'publish blog entries'))
        ->tool(EntriesCreate::class, ['collection' => 'blog', 'data' => ['title' => 'Live Post'], 'published' => true])
        ->assertOk()
        ->assertSee('"status":"published"')
        ->assertSee('"result":"published"');
});

it('requires date for dated collections', function () {
    Fixtures::site();
    makeDatedEventsCollection();

    Server::actingAs(Fixtures::makeUser('create events entries'))
        ->tool(EntriesCreate::class, ['collection' => 'events', 'data' => ['title' => 'Launch Party']])
        ->assertHasErrors(["collection 'events' is dated — pass date (e.g. 2026-07-09 or 2026-07-09 15:30)"]);

    Server::actingAs(Fixtures::makeUser('create events entries'))
        ->tool(EntriesCreate::class, ['collection' => 'events', 'data' => ['title' => 'Launch Party'], 'date' => '2026-08-01'])
        ->assertOk()
        ->assertSee('"slug":"launch-party"');
});

it('rejects date on a non-dated collection', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    Server::actingAs(Fixtures::makeUser('create blog entries'))
        ->tool(EntriesCreate::class, ['collection' => 'blog', 'data' => ['title' => 'Hi'], 'date' => '2026-08-01'])
        ->assertHasErrors(["collection 'blog' is not dated — omit date"]);
});

it('rejects a colliding slug with the existing id and points to entries_update', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $existing = tap(
        Entry::make()->collection('blog')->slug('hello-world')->data(['title' => 'Hello'])->published(true)
    )->save();

    Server::actingAs(Fixtures::makeUser('create blog entries'))
        ->tool(EntriesCreate::class, ['collection' => 'blog', 'data' => ['title' => 'Hello World'], 'slug' => 'hello-world'])
        ->assertHasErrors(["slug 'hello-world' already exists in collection 'blog' (site 'en') as entry '{$existing->id()}' — use entries_update to modify it"]);
});

it('rejects unknown data keys with valid handles and a did-you-mean hint', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    Server::actingAs(Fixtures::makeUser('create blog entries'))
        ->tool(EntriesCreate::class, ['collection' => 'blog', 'data' => ['title' => 'Hi', 'hero_imge' => 'x.jpg']])
        ->assertHasErrors(["unknown field hero_imge — valid handles: content, hero_image, title, topic — did you mean 'hero_image' instead of 'hero_imge'?"]);
});

it('returns field-level validation errors from the blueprint', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    // title is required by the article blueprint
    Server::actingAs(Fixtures::makeUser('create blog entries'))
        ->tool(EntriesCreate::class, ['collection' => 'blog', 'data' => ['hero_image' => 'x.jpg']])
        ->assertHasErrors()
        ->assertSee('validation failed');
});

it('denies creating without the create permission', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $user = Fixtures::makeUser('view blog entries');

    Server::actingAs($user)
        ->tool(EntriesCreate::class, ['collection' => 'blog', 'data' => ['title' => 'Hi']])
        ->assertHasErrors(["requires 'create blog entries' — grant it to a role of {$user->email()} in the Control Panel"]);
});

it('refuses to create when the server is read-only', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    config(['statamic.mcp.read_only' => true]);

    // Either the registration gate (shouldRegister) or the in-handler
    // re-check rejects the call — both are errors, which is all that matters.
    Server::actingAs(Fixtures::makeSuper())
        ->tool(EntriesCreate::class, ['collection' => 'blog', 'data' => ['title' => 'Hi']])
        ->assertHasErrors();

    expect(Entry::query()->where('collection', 'blog')->count())->toBe(0);
});
```

- [ ] **Step 2: Run the test — expect failure**

```bash
vendor/bin/pest tests/Feature/EntriesCreateTest.php
```

Expected: 9 failing tests, each erroring with `Error: Class "Danielgnh\StatamicMcp\Tools\EntriesCreate" not found`.

- [ ] **Step 3: Create the ValidatesBlueprintData concern**

Create `src/Tools/Concerns/ValidatesBlueprintData.php` (shared by entries_create and entries_update):

```php
<?php

namespace Danielgnh\StatamicMcp\Tools\Concerns;

use Danielgnh\StatamicMcp\Tools\ToolException;
use Illuminate\Validation\ValidationException;
use Statamic\Fields\Blueprint;

trait ValidatesBlueprintData
{
    /**
     * Statamic silently stores unknown keys (typos become content) — reject
     * them instead, naming valid handles plus a Levenshtein "did you mean"
     * (spec §8).
     */
    protected function rejectUnknownKeys(array $data, Blueprint $blueprint): void
    {
        $handles = $blueprint->fields()->all()->keys()->all();
        $unknown = array_values(array_diff(array_keys($data), $handles));

        if ($unknown === []) {
            return;
        }

        $suggestions = [];

        foreach ($unknown as $key) {
            $best = null;
            $bestDistance = PHP_INT_MAX;

            foreach ($handles as $handle) {
                $distance = levenshtein((string) $key, $handle);

                if ($distance < $bestDistance) {
                    $bestDistance = $distance;
                    $best = $handle;
                }
            }

            if ($best !== null && $bestDistance <= 3) {
                $suggestions[] = sprintf("did you mean '%s' instead of '%s'?", $best, $key);
            }
        }

        sort($handles);

        $message = sprintf(
            'unknown field%s %s — valid handles: %s',
            count($unknown) === 1 ? '' : 's',
            implode(', ', $unknown),
            implode(', ', $handles),
        );

        if ($suggestions !== []) {
            $message .= ' — '.implode(' ', $suggestions);
        }

        throw new ToolException($message);
    }

    /**
     * The CP's own validation path (spec §8, verified facts §5). Callers pass
     * MERGED values (existing + patch) so partial updates never false-fail
     * required fields. Field-level messages reach the model for one-round-trip
     * self-correction.
     */
    protected function validateAgainstBlueprint(Blueprint $blueprint, array $merged): void
    {
        try {
            $blueprint->fields()->addValues($merged)->validator()->validate();
        } catch (ValidationException $e) {
            throw new ToolException('validation failed: '.json_encode(
                $e->errors(),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
        }
    }
}
```

- [ ] **Step 4: Create the EntriesCreate tool**

Create `src/Tools/EntriesCreate.php` (the revisions branch lands in Task 16):

```php
<?php

namespace Danielgnh\StatamicMcp\Tools;

use Carbon\Exceptions\InvalidFormatException;
use Danielgnh\StatamicMcp\Tools\Concerns\ResolvesSites;
use Danielgnh\StatamicMcp\Tools\Concerns\ValidatesBlueprintData;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Statamic\Contracts\Entries\Collection as CollectionContract;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

#[Name('entries_create')]
#[Description('Create a new entry from raw field data (call blueprints_get first for the shape — never send augmented data). Saves an unpublished draft by default; published: true requires the publish permission for the collection. slug is generated from data.title when omitted. Dated collections require date.')]
class EntriesCreate extends Tool
{
    use ResolvesSites;
    use ValidatesBlueprintData;

    public function schema(JsonSchema $schema): array
    {
        return [
            'collection' => $schema->string()->description('Collection handle.')->required(),
            'data' => $schema->object()->description('Raw field values keyed by blueprint field handle. Unknown keys are rejected.')->required(),
            'slug' => $schema->string()->description('URL slug. Generated from data.title when omitted.'),
            'site' => $schema->string()->description('Site handle. Defaults to the default site.'),
            'date' => $schema->string()->description('Entry date (e.g. 2026-07-09 or 2026-07-09 15:30). Required for dated collections; rejected otherwise.'),
            'published' => $schema->boolean()->description('Defaults to false (draft). true requires the publish permission for the collection.'),
        ];
    }

    public function shouldRegister(Request $request): bool
    {
        return $this->writesEnabled();
    }

    protected function execute(Request $request): Response
    {
        $validated = $request->validate(
            ['collection' => 'required|string', 'data' => 'required|array'],
            ['data.required' => "Pass 'data' as an object of raw field values — call blueprints_get for the shape."],
        );

        // Re-check the registration gate: stale client tool caches are a
        // documented UX wart, not a security hole (spec §6 layer 1).
        if (! $this->writesEnabled()) {
            throw new ToolException("this server is read-only — set 'read_only' => false in config/statamic/mcp.php to enable writes");
        }

        $collectionHandle = $validated['collection'];
        $data = $validated['data'];

        $this->ensureExposed('collections', $collectionHandle);

        $user = $this->user($request);
        $this->ensurePermission($user, "create {$collectionHandle} entries");

        $site = $this->resolveSite($request, $user);

        $published = (bool) $request->get('published', false);

        if ($published) {
            // Publish is distinct — matches the CP's own gate (spec §6 layer 3).
            $this->ensurePermission($user, "publish {$collectionHandle} entries");
        }

        $collection = Collection::findByHandle($collectionHandle);
        $blueprint = $collection->entryBlueprint(); // the collection's default blueprint

        $this->rejectUnknownKeys($data, $blueprint);
        $this->validateAgainstBlueprint($blueprint, $data);

        $date = $this->resolveDate($request, $collection);
        $slug = $this->resolveSlug($request, $data, $collectionHandle, $site);

        $entry = Entry::make()
            ->collection($collectionHandle)
            ->slug($slug)
            ->locale($site)
            ->data($data)
            ->published($published);

        if ($date) {
            $entry->date($date);
        }

        $entry->save();

        $payload = [
            'id' => $entry->id(),
            'slug' => $entry->slug(),
            'site' => $site,
            'status' => $entry->status(),
            'url' => $entry->url(),
            ...$this->liveness($entry, $published ? self::LIVENESS_PUBLISHED : self::LIVENESS_DRAFT),
        ];

        if ($collection->dated()) {
            $payload['date'] = $entry->date()?->toIso8601String();
        }

        return $this->json($payload);
    }

    private function resolveDate(Request $request, CollectionContract $collection): ?Carbon
    {
        $date = $request->get('date');

        if ($collection->dated() && ! $date) {
            throw new ToolException(sprintf(
                "collection '%s' is dated — pass date (e.g. 2026-07-09 or 2026-07-09 15:30)",
                $collection->handle(),
            ));
        }

        if (! $collection->dated() && $date) {
            throw new ToolException(sprintf("collection '%s' is not dated — omit date", $collection->handle()));
        }

        if (! $date) {
            return null;
        }

        try {
            return Carbon::parse($date);
        } catch (InvalidFormatException) {
            throw new ToolException(sprintf("could not parse date '%s' — use e.g. 2026-07-09 or 2026-07-09 15:30", $date));
        }
    }

    private function resolveSlug(Request $request, array $data, string $collection, string $site): string
    {
        $slug = $request->get('slug');

        if (! $slug) {
            $title = $data['title'] ?? null;

            if (! is_string($title) || trim($title) === '') {
                throw new ToolException('pass slug, or include a title in data so a slug can be generated from it');
            }

            $slug = Str::slug($title);
        }

        $existing = Entry::query()
            ->where('collection', $collection)
            ->where('slug', $slug)
            ->where('site', $site)
            ->first();

        if ($existing) {
            throw new ToolException(sprintf(
                "slug '%s' already exists in collection '%s' (site '%s') as entry '%s' — use entries_update to modify it",
                $slug,
                $collection,
                $site,
                $existing->id(),
            ));
        }

        return $slug;
    }
}
```

- [ ] **Step 5: Register the tool on the server**

In `src/Server.php`, add `Tools\EntriesCreate::class,` to the `$tools` array directly after `Tools\EntriesGet::class,`:

```php
    protected array $tools = [
        Tools\StatamicOverview::class,
        Tools\BlueprintsGet::class,
        Tools\EntriesList::class,
        Tools\EntriesGet::class,
        Tools\EntriesCreate::class,
    ];
```

- [ ] **Step 6: Run the tests — expect pass**

```bash
vendor/bin/pest tests/Feature/EntriesCreateTest.php
```

Expected: `Tests: 9 passed`. Then:

```bash
vendor/bin/pest
```

Expected: all tests pass.

- [ ] **Step 7: Format and commit**

```bash
composer format
git add -A && git commit -m "$(cat <<'COMMIT'
feat: add entries_create tool with draft default and publish gate

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>
COMMIT
)"
```

### Task 14: entries_update — shallow merge, explicit null, no-op detection, site selector

**Files:**
- Create: `src/Tools/EntriesUpdate.php`
- Modify: `src/Server.php`
- Test: `tests/Feature/EntriesUpdateTest.php`

- [ ] **Step 1: Write the failing feature test**

Create `tests/Feature/EntriesUpdateTest.php`:

```php
<?php

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tools\EntriesUpdate;
use Illuminate\Support\Facades\Event;
use Statamic\Events\EntrySaved;
use Statamic\Facades\Entry;

function makeUpdatableBlogEntry(array $data = []): \Statamic\Contracts\Entries\Entry
{
    return tap(
        Entry::make()
            ->collection('blog')
            ->slug('hello-world')
            ->data(array_merge(['title' => 'Hello World', 'hero_image' => 'hero.jpg'], $data))
            ->published(true)
    )->save();
}

it('merges top-level keys shallowly, preserving untouched fields and publish state', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $entry = makeUpdatableBlogEntry();

    Server::actingAs(Fixtures::makeUser('edit blog entries'))
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['title' => 'Hello Again']])
        ->assertOk()
        ->assertSee('"result":"published"') // publish state untouched
        ->assertSee('"cp_edit_url"');

    $fresh = Entry::find($entry->id());

    expect($fresh->get('title'))->toBe('Hello Again')
        ->and($fresh->get('hero_image'))->toBe('hero.jpg')
        ->and($fresh->published())->toBeTrue();
});

it('replaces nested structures wholesale, never deep-merging', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $entry = makeUpdatableBlogEntry(['content' => [
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Old paragraph one.']]],
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Old paragraph two.']]],
    ]]);

    $newContent = [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'The only paragraph now.']]]];

    Server::actingAs(Fixtures::makeUser('edit blog entries'))
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['content' => $newContent]])
        ->assertOk();

    expect(Entry::find($entry->id())->get('content'))->toBe($newContent);
});

it('stores an explicit null to clear a field', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $entry = makeUpdatableBlogEntry();

    Server::actingAs(Fixtures::makeUser('edit blog entries'))
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['hero_image' => null]])
        ->assertOk();

    $fresh = Entry::find($entry->id());

    expect($fresh->data()->has('hero_image'))->toBeTrue() // a local null, not an absent key
        ->and($fresh->get('hero_image'))->toBeNull();
});

it('errors when clearing a required field, via merged validation', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $entry = makeUpdatableBlogEntry();

    Server::actingAs(Fixtures::makeUser('edit blog entries'))
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['title' => null]])
        ->assertHasErrors()
        ->assertSee('validation failed');
});

it('does not false-fail required fields on partial updates', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $entry = makeUpdatableBlogEntry();

    // title stays present via the merge — updating only hero_image must pass
    Server::actingAs(Fixtures::makeUser('edit blog entries'))
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['hero_image' => 'new.jpg']])
        ->assertOk()
        ->assertHasNoErrors();
});

it('is a no-op when merged data equals current data', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $entry = makeUpdatableBlogEntry();

    Event::fake([EntrySaved::class]);

    Server::actingAs(Fixtures::makeUser('edit blog entries'))
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['title' => 'Hello World']])
        ->assertOk()
        ->assertSee('no-op');

    Event::assertNotDispatched(EntrySaved::class); // nothing was saved
});

it('changes publish state only when published is sent explicitly', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $entry = makeUpdatableBlogEntry();

    // unpublishing needs only the edit permission (the gate is on the transition to true)
    Server::actingAs(Fixtures::makeUser('edit blog entries'))
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['title' => 'Hello World'], 'published' => false])
        ->assertOk()
        ->assertSee('saved as draft — not live');

    expect(Entry::find($entry->id())->published())->toBeFalse();
});

it("requires 'publish blog entries' to set published: true", function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $entry = tap(
        Entry::make()->collection('blog')->slug('a-draft')->data(['title' => 'Draft'])->published(false)
    )->save();

    $user = Fixtures::makeUser('edit blog entries');

    Server::actingAs($user)
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['title' => 'Draft'], 'published' => true])
        ->assertHasErrors(["requires 'publish blog entries' — grant it to a role of {$user->email()} in the Control Panel"]);

    Server::actingAs(Fixtures::makeUser('edit blog entries', 'publish blog entries'))
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['title' => 'Draft'], 'published' => true])
        ->assertOk()
        ->assertSee('"result":"published"');
});

it('rejects a mismatched site selector, listing localization ids', function () {
    Fixtures::multisite();
    Fixtures::tags();
    Fixtures::blog();

    $origin = tap(
        Entry::make()->collection('blog')->slug('hello')->locale('en')->data(['title' => 'Hello'])->published(true)
    )->save();

    $localization = tap($origin->makeLocalization('de')->data(['title' => 'Hallo']))->save();

    Server::actingAs(Fixtures::makeSuper())
        ->tool(EntriesUpdate::class, ['id' => $origin->id(), 'data' => ['title' => 'Hi'], 'site' => 'de'])
        ->assertHasErrors([
            "entry '{$origin->id()}' belongs to site 'en', not 'de' — pass the matching localization id instead (or omit site). Localizations: en => {$origin->id()}; de => {$localization->id()}",
        ]);
});

it('rejects unknown data keys with a did-you-mean hint', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $entry = makeUpdatableBlogEntry();

    Server::actingAs(Fixtures::makeUser('edit blog entries'))
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['titel' => 'Hi']])
        ->assertHasErrors(["unknown field titel — valid handles: content, hero_image, title, topic — did you mean 'title' instead of 'titel'?"]);
});

it('denies updating without the edit permission', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    $entry = makeUpdatableBlogEntry();
    $user = Fixtures::makeUser('view blog entries');

    Server::actingAs($user)
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['title' => 'Hi']])
        ->assertHasErrors(["requires 'edit blog entries' — grant it to a role of {$user->email()} in the Control Panel"]);
});
```

- [ ] **Step 2: Run the test — expect failure**

```bash
vendor/bin/pest tests/Feature/EntriesUpdateTest.php
```

Expected: 11 failing tests, each erroring with `Error: Class "Danielgnh\StatamicMcp\Tools\EntriesUpdate" not found`.

- [ ] **Step 3: Create the EntriesUpdate tool**

Create `src/Tools/EntriesUpdate.php` (the revisions branch lands in Task 16):

```php
<?php

namespace Danielgnh\StatamicMcp\Tools;

use Carbon\Exceptions\InvalidFormatException;
use Danielgnh\StatamicMcp\Tools\Concerns\ResolvesEntries;
use Danielgnh\StatamicMcp\Tools\Concerns\ResolvesSites;
use Danielgnh\StatamicMcp\Tools\Concerns\ValidatesBlueprintData;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Statamic\Contracts\Entries\Entry as EntryContract;

#[Name('entries_update')]
#[Description('Update an entry with a shallow top-level merge of raw field data: nested structures (Bard, arrays) are replaced wholesale, never deep-merged — always send the complete new value for a nested field. Explicit null clears a field (stores a local null); resetting a field to inherit from its origin localization is not supported in v1. Publish state is untouched unless published is sent. site is a selector only — it must match the entry\'s own site and never creates or moves localizations. If the merged result equals current data, nothing is saved.')]
#[IsIdempotent]
class EntriesUpdate extends Tool
{
    use ResolvesEntries;
    use ResolvesSites;
    use ValidatesBlueprintData;

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('Entry id.')->required(),
            'data' => $schema->object()->description('Raw field values to merge over the current top-level data. Unknown keys are rejected; null clears a field.')->required(),
            'slug' => $schema->string()->description('New slug.'),
            'date' => $schema->string()->description('New date — dated collections only.'),
            'published' => $schema->boolean()->description('Omit to leave publish state untouched. true requires the publish permission.'),
            'site' => $schema->string()->description("Selector only: must match the entry's own site, or be omitted."),
        ];
    }

    public function shouldRegister(Request $request): bool
    {
        return $this->writesEnabled();
    }

    protected function execute(Request $request): Response
    {
        $validated = $request->validate(
            ['id' => 'required|string', 'data' => 'required|array'],
            ['data.required' => "Pass 'data' as an object of raw field values to merge — call entries_get (format raw) first."],
        );

        // Re-check the registration gate (stale client tool caches, spec §6 layer 1).
        if (! $this->writesEnabled()) {
            throw new ToolException("this server is read-only — set 'read_only' => false in config/statamic/mcp.php to enable writes");
        }

        $entry = $this->findExposedEntry($validated['id']);
        $this->assertSiteMatchesEntry($entry, $request->get('site'));

        $collection = $entry->collection()->handle();

        $user = $this->user($request);
        $this->ensurePermission($user, "edit {$collection} entries");
        $this->ensureSiteAccess($user, $entry->locale());

        $publishedSent = array_key_exists('published', $request->all());
        $published = $publishedSent ? (bool) $request->get('published') : null;

        if ($publishedSent && $published === true) {
            // Publish is distinct — matches the CP's own gate (spec §6 layer 3).
            $this->ensurePermission($user, "publish {$collection} entries");
        }

        $data = $validated['data'];
        $blueprint = $entry->blueprint();

        $this->rejectUnknownKeys($data, $blueprint);

        $current = $entry->data()->all();
        $merged = array_merge($current, $data); // shallow top-level merge by design (spec §4/§8)

        $slug = $request->get('slug');
        $date = $this->resolveDate($request, $entry);

        // == not ===: key order is irrelevant, values must match exactly.
        $dirty = $merged != $current
            || ($slug !== null && $slug !== $entry->slug())
            || ($date !== null && ! $date->equalTo($entry->date()))
            || ($publishedSent && $published !== $entry->published());

        if (! $dirty) {
            return $this->json([
                'id' => $entry->id(),
                'result' => 'no-op — merged data equals current data; nothing saved, no revision created',
                'cp_edit_url' => $entry->editUrl(),
            ]);
        }

        $this->validateAgainstBlueprint($blueprint, $merged);

        $entry->data($merged);

        if ($slug !== null) {
            $entry->slug($slug);
        }

        if ($date !== null) {
            $entry->date($date);
        }

        if ($publishedSent) {
            $entry->published($published);
        }

        $entry->updateLastModified($user)->save();

        return $this->json([
            'id' => $entry->id(),
            'slug' => $entry->slug(),
            'status' => $entry->status(),
            'url' => $entry->url(),
            ...$this->liveness($entry, $entry->published() ? self::LIVENESS_PUBLISHED : self::LIVENESS_DRAFT),
        ]);
    }

    private function resolveDate(Request $request, EntryContract $entry): ?Carbon
    {
        $date = $request->get('date');

        if (! $date) {
            return null;
        }

        if (! $entry->collection()->dated()) {
            throw new ToolException(sprintf(
                "collection '%s' is not dated — omit date",
                $entry->collection()->handle(),
            ));
        }

        try {
            return Carbon::parse($date);
        } catch (InvalidFormatException) {
            throw new ToolException(sprintf("could not parse date '%s' — use e.g. 2026-07-09 or 2026-07-09 15:30", $date));
        }
    }
}
```

- [ ] **Step 4: Register the tool on the server**

In `src/Server.php`, add `Tools\EntriesUpdate::class,` to the `$tools` array directly after `Tools\EntriesCreate::class,`:

```php
    protected array $tools = [
        Tools\StatamicOverview::class,
        Tools\BlueprintsGet::class,
        Tools\EntriesList::class,
        Tools\EntriesGet::class,
        Tools\EntriesCreate::class,
        Tools\EntriesUpdate::class,
    ];
```

- [ ] **Step 5: Run the tests — expect pass**

```bash
vendor/bin/pest tests/Feature/EntriesUpdateTest.php
```

Expected: `Tests: 11 passed`. Then:

```bash
vendor/bin/pest
```

Expected: all tests pass.

- [ ] **Step 6: Format and commit**

```bash
composer format
git add -A && git commit -m "$(cat <<'COMMIT'
feat: add entries_update tool with shallow merge and no-op detection

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>
COMMIT
)"
```

### Task 15: entries_delete — destructive, registered only behind the deletes gate

**Files:**
- Create: `src/Tools/EntriesDelete.php`
- Modify: `src/Server.php`
- Test: `tests/Feature/EntriesDeleteTest.php`

- [ ] **Step 1: Write the failing feature test**

Create `tests/Feature/EntriesDeleteTest.php`:

```php
<?php

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tools\EntriesDelete;
use Statamic\Facades\Entry;

function makeDeletableBlogEntry(): \Statamic\Contracts\Entries\Entry
{
    return tap(
        Entry::make()
            ->collection('blog')
            ->slug('doomed-post')
            ->data(['title' => 'Doomed Post'])
            ->published(true)
    )->save();
}

it('deletes an entry when deletes are enabled and the user may delete', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    config(['statamic.mcp.deletes' => true]);

    $entry = makeDeletableBlogEntry();

    Server::actingAs(Fixtures::makeUser('delete blog entries'))
        ->tool(EntriesDelete::class, ['id' => $entry->id()])
        ->assertOk()
        ->assertSee('deleted');

    expect(Entry::find($entry->id()))->toBeNull();
});

it('denies deleting without the delete permission', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    config(['statamic.mcp.deletes' => true]);

    $entry = makeDeletableBlogEntry();
    $user = Fixtures::makeUser('edit blog entries');

    Server::actingAs($user)
        ->tool(EntriesDelete::class, ['id' => $entry->id()])
        ->assertHasErrors(["requires 'delete blog entries' — grant it to a role of {$user->email()} in the Control Panel"]);

    expect(Entry::find($entry->id()))->not->toBeNull();
});

it('refuses to delete when deletes are disabled (the default)', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    // config default: statamic.mcp.deletes = false

    $entry = makeDeletableBlogEntry();

    // Either the registration gate (shouldRegister) or the in-handler
    // re-check rejects the call — both surface as errors.
    Server::actingAs(Fixtures::makeSuper())
        ->tool(EntriesDelete::class, ['id' => $entry->id()])
        ->assertHasErrors();

    expect(Entry::find($entry->id()))->not->toBeNull();
});

it('refuses to delete when the server is read-only even if deletes are enabled', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    config(['statamic.mcp.deletes' => true, 'statamic.mcp.read_only' => true]);

    $entry = makeDeletableBlogEntry();

    Server::actingAs(Fixtures::makeSuper())
        ->tool(EntriesDelete::class, ['id' => $entry->id()])
        ->assertHasErrors();

    expect(Entry::find($entry->id()))->not->toBeNull();
});

it('treats an entry in an unexposed collection as not found', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();

    config(['statamic.mcp.deletes' => true, 'statamic.mcp.resources.collections' => []]);

    $entry = makeDeletableBlogEntry();

    Server::actingAs(Fixtures::makeSuper())
        ->tool(EntriesDelete::class, ['id' => $entry->id()])
        ->assertHasErrors(["entry '{$entry->id()}' not found"]);

    expect(Entry::find($entry->id()))->not->toBeNull();
});
```

- [ ] **Step 2: Run the test — expect failure**

```bash
vendor/bin/pest tests/Feature/EntriesDeleteTest.php
```

Expected: 5 failing tests, each erroring with `Error: Class "Danielgnh\StatamicMcp\Tools\EntriesDelete" not found`.

- [ ] **Step 3: Create the EntriesDelete tool**

Create `src/Tools/EntriesDelete.php`. Note: `deletesEnabled()` on the base Tool already combines both config gates — `! read_only && deletes` (contracts §5) — so `shouldRegister()` hides this tool unless deletes are enabled AND the server is not read-only:

```php
<?php

namespace Danielgnh\StatamicMcp\Tools;

use Danielgnh\StatamicMcp\Tools\Concerns\ResolvesEntries;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('entries_delete')]
#[Description('Permanently delete an entry by id, including all of its localizations. This cannot be undone.')]
#[IsDestructive]
class EntriesDelete extends Tool
{
    use ResolvesEntries;

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('Entry id.')->required(),
        ];
    }

    public function shouldRegister(Request $request): bool
    {
        return $this->deletesEnabled();
    }

    protected function execute(Request $request): Response
    {
        $validated = $request->validate(['id' => 'required|string']);

        // Re-check the registration gate (stale client tool caches, spec §6 layer 1).
        if (! $this->deletesEnabled()) {
            throw new ToolException("deletes are disabled on this server — set 'deletes' => true (and 'read_only' => false) in config/statamic/mcp.php");
        }

        $entry = $this->findExposedEntry($validated['id']);

        $collection = $entry->collection()->handle();

        $user = $this->user($request);
        $this->ensurePermission($user, "delete {$collection} entries");

        $entry->delete();

        return $this->json([
            'id' => $validated['id'],
            'collection' => $collection,
            'slug' => $entry->slug(),
            'result' => 'deleted — the entry (and any localizations) is gone; this cannot be undone',
        ]);
    }
}
```

(No `cp_edit_url` here on purpose: the CP edit page for a deleted entry would 404. The liveness statement is the `result` line.)

- [ ] **Step 4: Register the tool on the server**

In `src/Server.php`, add `Tools\EntriesDelete::class,` to the `$tools` array directly after `Tools\EntriesUpdate::class,`:

```php
    protected array $tools = [
        Tools\StatamicOverview::class,
        Tools\BlueprintsGet::class,
        Tools\EntriesList::class,
        Tools\EntriesGet::class,
        Tools\EntriesCreate::class,
        Tools\EntriesUpdate::class,
        Tools\EntriesDelete::class,
    ];
```

(The class is always listed; `shouldRegister()` keeps it out of `tools/list` unless `deletes` is on and `read_only` is off.)

- [ ] **Step 5: Run the tests — expect pass**

```bash
vendor/bin/pest tests/Feature/EntriesDeleteTest.php
```

Expected: `Tests: 5 passed`. Then:

```bash
vendor/bin/pest
```

Expected: all tests pass.

- [ ] **Step 6: Format and commit**

```bash
composer format
git add -A && git commit -m "$(cat <<'COMMIT'
feat: add entries_delete tool behind the deletes config gate

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>
COMMIT
)"
```

### Task 16: revisions — working-copy writes on revision-enabled collections

Behavioral contract (spec §6, mechanics verified in facts §2): on revision-enabled collections, updating a **published** entry writes a working copy (a `Revision` with `action: working` — there is no WorkingCopy class in 6.x) attributed to the acting user with a message naming the tool, and never touches the live entry. Updating an **unpublished draft** saves directly (CP parity — the CP only makes working copies for published entries). Creates go through `$entry->store()` (unpublished draft + initial revision). ANY explicit `published` value (true or false) is rejected. A no-op update creates nothing.

**Files:**
- Modify: `src/Tools/EntriesCreate.php`
- Modify: `src/Tools/EntriesUpdate.php`
- Test: `tests/Feature/EntriesRevisionsTest.php`

- [ ] **Step 1: Write the failing feature test**

Create `tests/Feature/EntriesRevisionsTest.php`. Note the three switches revisions require: `statamic.editions.pro` (revisionsEnabled() requires Pro — facts §2), `statamic.revisions.enabled`, and the per-collection flag:

```php
<?php

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tools\EntriesCreate;
use Danielgnh\StatamicMcp\Tools\EntriesUpdate;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

function enableBlogRevisions(): void
{
    config([
        'statamic.editions.pro' => true,       // revisionsEnabled() requires Statamic Pro
        'statamic.revisions.enabled' => true,
        // unique per test so file assertions never see another test's leftovers
        'statamic.revisions.path' => storage_path('statamic/revisions-test-'.Str::lower(Str::random(8))),
    ]);

    Collection::findByHandle('blog')->revisionsEnabled(true)->save();
}

function makePublishedRevisableEntry(): \Statamic\Contracts\Entries\Entry
{
    return tap(
        Entry::make()
            ->collection('blog')
            ->slug('live-post')
            ->data(['title' => 'Live Title'])
            ->published(true)
    )->save();
}

it('writes a working copy for a published entry, leaving the live entry unchanged', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();
    enableBlogRevisions();

    $entry = makePublishedRevisableEntry();
    $user = Fixtures::makeUser('edit blog entries');

    Server::actingAs($user)
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['title' => 'Edited Title']])
        ->assertOk()
        ->assertSee('working copy created — live entry unchanged');

    $fresh = Entry::find($entry->id());

    expect($fresh->get('title'))->toBe('Live Title')   // live entry untouched
        ->and($fresh->published())->toBeTrue()
        ->and($fresh->hasWorkingCopy())->toBeTrue();

    // Attribution: the working.yaml on disk names the acting user and the tool
    $workingYaml = collect(File::allFiles(config('statamic.revisions.path')))
        ->first(fn ($file) => $file->getFilename() === 'working.yaml');

    expect($workingYaml)->not->toBeNull();

    $contents = File::get($workingYaml->getPathname());

    expect($contents)->toContain('via MCP entries_update')
        ->toContain((string) $user->id())
        ->toContain('Edited Title');
});

it('rejects any explicit published value on update in a revision-enabled collection', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();
    enableBlogRevisions();

    $entry = makePublishedRevisableEntry();

    foreach ([true, false] as $published) {
        Server::actingAs(Fixtures::makeSuper())
            ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['title' => 'X'], 'published' => $published])
            ->assertHasErrors(["collection 'blog' uses revisions — publish/unpublish from the Control Panel, not via entries_update"]);
    }

    expect(Entry::find($entry->id())->get('title'))->toBe('Live Title');
});

it('creates an unpublished draft through the revision pipeline', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();
    enableBlogRevisions();

    Server::actingAs(Fixtures::makeUser('create blog entries'))
        ->tool(EntriesCreate::class, ['collection' => 'blog', 'data' => ['title' => 'New Draft']])
        ->assertOk()
        ->assertSee('saved as draft — not live');

    $entry = Entry::query()->where('collection', 'blog')->where('slug', 'new-draft')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->published())->toBeFalse()
        ->and($entry->hasWorkingCopy())->toBeFalse();

    // store() recorded an initial revision on disk
    expect(collect(File::allFiles(config('statamic.revisions.path')))->isNotEmpty())->toBeTrue();
});

it('rejects explicit published on create in a revision-enabled collection', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();
    enableBlogRevisions();

    foreach ([true, false] as $published) {
        Server::actingAs(Fixtures::makeSuper())
            ->tool(EntriesCreate::class, ['collection' => 'blog', 'data' => ['title' => 'X'], 'published' => $published])
            ->assertHasErrors(["collection 'blog' uses revisions — entries are always created as unpublished drafts here; publish/unpublish from the Control Panel"]);
    }
});

it('creates no working copy when the merged update equals current data', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();
    enableBlogRevisions();

    $entry = makePublishedRevisableEntry();

    Server::actingAs(Fixtures::makeUser('edit blog entries'))
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['title' => 'Live Title']])
        ->assertOk()
        ->assertSee('no-op');

    expect(Entry::find($entry->id())->hasWorkingCopy())->toBeFalse();
});

it('saves unpublished drafts directly without a working copy (CP parity)', function () {
    Fixtures::site();
    Fixtures::tags();
    Fixtures::blog();
    enableBlogRevisions();

    $entry = tap(
        Entry::make()->collection('blog')->slug('a-draft')->data(['title' => 'Draft Title'])->published(false)
    )->save();

    Server::actingAs(Fixtures::makeUser('edit blog entries'))
        ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['title' => 'Edited Draft']])
        ->assertOk()
        ->assertSee('saved as draft — not live');

    $fresh = Entry::find($entry->id());

    expect($fresh->get('title'))->toBe('Edited Draft')  // saved directly
        ->and($fresh->hasWorkingCopy())->toBeFalse();
});
```

- [ ] **Step 2: Run the test — expect failure**

```bash
vendor/bin/pest tests/Feature/EntriesRevisionsTest.php
```

Expected: `Tests: 4 failed, 2 passed` — the working-copy test fails (live entry WAS changed, no working copy), both explicit-published rejections fail (no error raised), the create-pipeline test fails (no revision written). The no-op and draft-direct-save tests already pass from Tasks 13/14.

- [ ] **Step 3: Add the revisions branch to EntriesCreate**

In `src/Tools/EntriesCreate.php`, replace the whole `execute()` method with the version below. Two changes: the publish gate moves BELOW the revisions branch (on revision collections an explicit `published` is rejected outright, so the rejection must win over a permission denial), and creates route through `$entry->store()` when revisions are on:

```php
    protected function execute(Request $request): Response
    {
        $validated = $request->validate(
            ['collection' => 'required|string', 'data' => 'required|array'],
            ['data.required' => "Pass 'data' as an object of raw field values — call blueprints_get for the shape."],
        );

        // Re-check the registration gate: stale client tool caches are a
        // documented UX wart, not a security hole (spec §6 layer 1).
        if (! $this->writesEnabled()) {
            throw new ToolException("this server is read-only — set 'read_only' => false in config/statamic/mcp.php to enable writes");
        }

        $collectionHandle = $validated['collection'];
        $data = $validated['data'];

        $this->ensureExposed('collections', $collectionHandle);

        $user = $this->user($request);
        $this->ensurePermission($user, "create {$collectionHandle} entries");

        $site = $this->resolveSite($request, $user);

        $collection = Collection::findByHandle($collectionHandle);
        $blueprint = $collection->entryBlueprint(); // the collection's default blueprint

        $this->rejectUnknownKeys($data, $blueprint);
        $this->validateAgainstBlueprint($blueprint, $data);

        $date = $this->resolveDate($request, $collection);
        $slug = $this->resolveSlug($request, $data, $collectionHandle, $site);

        $entry = Entry::make()
            ->collection($collectionHandle)
            ->slug($slug)
            ->locale($site)
            ->data($data);

        if ($date) {
            $entry->date($date);
        }

        if ($entry->revisionsEnabled()) {
            if (array_key_exists('published', $request->all())) {
                throw new ToolException(sprintf(
                    "collection '%s' uses revisions — entries are always created as unpublished drafts here; publish/unpublish from the Control Panel",
                    $collectionHandle,
                ));
            }

            // CP-parity create path (verified facts §2): saves an unpublished
            // draft AND records an attributed initial revision.
            $entry->store(['message' => 'Created via MCP (entries_create)', 'user' => $user]);

            $payload = [
                'id' => $entry->id(),
                'slug' => $entry->slug(),
                'site' => $site,
                'status' => $entry->status(),
                'url' => $entry->url(),
                ...$this->liveness($entry, self::LIVENESS_DRAFT),
            ];

            if ($collection->dated()) {
                $payload['date'] = $entry->date()?->toIso8601String();
            }

            return $this->json($payload);
        }

        $published = (bool) $request->get('published', false);

        if ($published) {
            // Publish is distinct — matches the CP's own gate (spec §6 layer 3).
            $this->ensurePermission($user, "publish {$collectionHandle} entries");
        }

        $entry->published($published);
        $entry->save();

        $payload = [
            'id' => $entry->id(),
            'slug' => $entry->slug(),
            'site' => $site,
            'status' => $entry->status(),
            'url' => $entry->url(),
            ...$this->liveness($entry, $published ? self::LIVENESS_PUBLISHED : self::LIVENESS_DRAFT),
        ];

        if ($collection->dated()) {
            $payload['date'] = $entry->date()?->toIso8601String();
        }

        return $this->json($payload);
    }
```

(All other methods in the class — `schema()`, `shouldRegister()`, `resolveDate()`, `resolveSlug()` — stay exactly as written in Task 13.)

- [ ] **Step 4: Add the revisions branch to EntriesUpdate**

In `src/Tools/EntriesUpdate.php`, replace the whole `execute()` method with:

```php
    protected function execute(Request $request): Response
    {
        $validated = $request->validate(
            ['id' => 'required|string', 'data' => 'required|array'],
            ['data.required' => "Pass 'data' as an object of raw field values to merge — call entries_get (format raw) first."],
        );

        // Re-check the registration gate (stale client tool caches, spec §6 layer 1).
        if (! $this->writesEnabled()) {
            throw new ToolException("this server is read-only — set 'read_only' => false in config/statamic/mcp.php to enable writes");
        }

        $entry = $this->findExposedEntry($validated['id']);
        $this->assertSiteMatchesEntry($entry, $request->get('site'));

        $collection = $entry->collection()->handle();

        $user = $this->user($request);
        $this->ensurePermission($user, "edit {$collection} entries");
        $this->ensureSiteAccess($user, $entry->locale());

        $publishedSent = array_key_exists('published', $request->all());
        $published = $publishedSent ? (bool) $request->get('published') : null;

        // ANY explicit published value is rejected on revision collections —
        // true or false — publishing goes through the CP's revision flow (spec §6).
        if ($entry->revisionsEnabled() && $publishedSent) {
            throw new ToolException(sprintf(
                "collection '%s' uses revisions — publish/unpublish from the Control Panel, not via entries_update",
                $collection,
            ));
        }

        if ($publishedSent && $published === true) {
            // Publish is distinct — matches the CP's own gate (spec §6 layer 3).
            $this->ensurePermission($user, "publish {$collection} entries");
        }

        $data = $validated['data'];
        $blueprint = $entry->blueprint();

        $this->rejectUnknownKeys($data, $blueprint);

        $current = $entry->data()->all();
        $merged = array_merge($current, $data); // shallow top-level merge by design (spec §4/§8)

        $slug = $request->get('slug');
        $date = $this->resolveDate($request, $entry);

        // == not ===: key order is irrelevant, values must match exactly.
        $dirty = $merged != $current
            || ($slug !== null && $slug !== $entry->slug())
            || ($date !== null && ! $date->equalTo($entry->date()))
            || ($publishedSent && $published !== $entry->published());

        if (! $dirty) {
            // No save, no working copy, no revision (spec §6).
            return $this->json([
                'id' => $entry->id(),
                'result' => 'no-op — merged data equals current data; nothing saved, no revision created',
                'cp_edit_url' => $entry->editUrl(),
            ]);
        }

        $this->validateAgainstBlueprint($blueprint, $merged);

        $entry->data($merged);

        if ($slug !== null) {
            $entry->slug($slug);
        }

        if ($date !== null) {
            $entry->date($date);
        }

        if ($entry->revisionsEnabled() && $entry->published()) {
            // CP parity (verified facts §2): makeWorkingCopy() snapshots the
            // in-memory attributes set above — the live entry is NEVER saved.
            $entry->makeWorkingCopy()
                ->user($user)
                ->message('via MCP entries_update')
                ->save();

            return $this->json([
                'id' => $entry->id(),
                'slug' => $entry->slug(),
                'status' => $entry->status(),
                'url' => $entry->url(),
                ...$this->liveness($entry, self::LIVENESS_WORKING_COPY),
            ]);
        }

        // Unpublished drafts in revision collections save directly, exactly
        // like the CP's update branch (verified facts §2).
        if ($publishedSent) {
            $entry->published($published);
        }

        $entry->updateLastModified($user)->save();

        return $this->json([
            'id' => $entry->id(),
            'slug' => $entry->slug(),
            'status' => $entry->status(),
            'url' => $entry->url(),
            ...$this->liveness($entry, $entry->published() ? self::LIVENESS_PUBLISHED : self::LIVENESS_DRAFT),
        ]);
    }
```

(All other methods — `schema()`, `shouldRegister()`, `resolveDate()` — stay exactly as written in Task 14.)

- [ ] **Step 5: Run the tests — expect pass**

```bash
vendor/bin/pest tests/Feature/EntriesRevisionsTest.php
```

Expected: `Tests: 6 passed`. Then run the full suite — the plain create/update tests from Tasks 13/14 must still pass (revisions are off by default in the test environment):

```bash
vendor/bin/pest
```

Expected: all tests pass.

- [ ] **Step 6: Format and commit**

```bash
composer format
git add -A && git commit -m "$(cat <<'COMMIT'
feat: route entry writes through working copies on revision-enabled collections

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>
COMMIT
)"
```



### Task 17: `terms_list` tool

**Files:**
- Create: `src/Tools/TermsList.php`
- Modify: `src/Tools/Concerns/ResolvesSites.php` (extend the shared `resolveSite` from 04-entries Task 10 with an optional valid-sites parameter)
- Modify: `src/Server.php` (add the `Tools\TermsList::class` line to `$tools` — skip if already present)
- Test: `tests/Feature/TermsListTest.php`

Contract (spec §4 rows 8–12): mirrors `entries_list` with `taxonomy` instead of `collection`; NO `status` filter (terms have no status). Summary columns only — never field data. Permission string is `view {taxonomy} terms` (verified facts §3). Site filtering uses the term store's `site` index (each localization is indexed per site in 6.x).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/TermsListTest.php`:

```php
<?php

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tools\TermsList;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;

it('lists terms in an exposed taxonomy with summary columns', function () {
    Fixtures::site();
    Fixtures::tags();

    Term::make()->taxonomy('tags')->slug('php')->data(['title' => 'PHP'])->save();
    Term::make()->taxonomy('tags')->slug('laravel')->data(['title' => 'Laravel'])->save();

    $user = Fixtures::makeUser('view tags terms');

    Server::actingAs($user)
        ->tool(TermsList::class, ['taxonomy' => 'tags'])
        ->assertOk()
        ->assertSee('"slug":"php"')
        ->assertSee('"slug":"laravel"')
        ->assertSee('"total":2');
});

it('paginates and reports the next page, capping per_page at 100', function () {
    Fixtures::site();
    Fixtures::tags();

    Term::make()->taxonomy('tags')->slug('alpha')->data(['title' => 'Alpha'])->save();
    Term::make()->taxonomy('tags')->slug('beta')->data(['title' => 'Beta'])->save();
    Term::make()->taxonomy('tags')->slug('gamma')->data(['title' => 'Gamma'])->save();

    $user = Fixtures::makeUser('view tags terms');

    Server::actingAs($user)
        ->tool(TermsList::class, ['taxonomy' => 'tags', 'limit' => 2, 'page' => 1])
        ->assertOk()
        ->assertSee('"total":3')
        ->assertSee('"next_page":2');

    Server::actingAs($user)
        ->tool(TermsList::class, ['taxonomy' => 'tags', 'limit' => 2, 'page' => 2])
        ->assertOk()
        ->assertSee('"slug":"gamma"')
        ->assertSee('"next_page":null');

    // An over-limit value does not error — it is silently clamped to 100,
    // mirroring entries_list (04-entries Task 10).
    Server::actingAs($user)
        ->tool(TermsList::class, ['taxonomy' => 'tags', 'limit' => 500])
        ->assertOk()
        ->assertSee('"per_page":100');
});

it('filters terms by title search', function () {
    Fixtures::site();
    Fixtures::tags();

    Term::make()->taxonomy('tags')->slug('php')->data(['title' => 'PHP'])->save();
    Term::make()->taxonomy('tags')->slug('laravel')->data(['title' => 'Laravel'])->save();

    $user = Fixtures::makeUser('view tags terms');

    Server::actingAs($user)
        ->tool(TermsList::class, ['taxonomy' => 'tags', 'search' => 'lara'])
        ->assertOk()
        ->assertSee('"slug":"laravel"')
        ->assertDontSee('"slug":"php"');
});

it('denies listing without the view permission', function () {
    Fixtures::site();
    Fixtures::tags();

    $user = Fixtures::makeUser(); // 'access mcp' only

    Server::actingAs($user)
        ->tool(TermsList::class, ['taxonomy' => 'tags'])
        ->assertHasErrors(["requires 'view tags terms' — grant it to a role of {$user->email()} in the Control Panel"]);
});

it('treats an unexposed taxonomy as missing, listing only exposed handles', function () {
    Fixtures::site();
    Fixtures::tags();
    tap(Taxonomy::make('secrets')->title('Secrets'))->save();

    config(['statamic.mcp.resources.taxonomies' => ['tags']]);

    Server::actingAs(Fixtures::makeSuper())
        ->tool(TermsList::class, ['taxonomy' => 'secrets'])
        ->assertHasErrors(["taxonomy 'secrets' not found — available: tags"]);
});
```

- [ ] **Step 2: Run the test and confirm the failure**

```
vendor/bin/pest tests/Feature/TermsListTest.php
```

Expected: all 5 tests error with `Error: Class "Danielgnh\StatamicMcp\Tools\TermsList" not found`.

- [ ] **Step 3: Extend the shared ResolvesSites concern, implement the tool, and register it**

Terms and globals are only valid in their resource's own configured sites (`$taxonomy->sites()` / `$set->sites()`), unlike entries. Extend the shared `src/Tools/Concerns/ResolvesSites.php` concern (created in 04-entries Task 10) so `resolveSite` accepts an optional valid-sites collection. The change is backward-compatible — 04's two-argument callers are untouched — and the error wording stays the canonical `"site 'X' not found — available: ..."`. Add `use Illuminate\Support\Collection;` to the trait's imports and replace its `resolveSite` method with (`ensureSiteAccess` is unchanged):

```php
    /**
     * The requested site (default: Site::default()), validated against the
     * configured sites, with 'access {site} site' enforced for non-default
     * sites on multisite installs (spec §6). Pass $validSites to limit the
     * check to a resource's own configured sites (taxonomies, global sets);
     * when omitted, every configured site is valid (entries).
     */
    protected function resolveSite(Request $request, UserContract $user, ?Collection $validSites = null): string
    {
        $site = $request->get('site') ?? Site::default()->handle();

        $handles = $validSites?->values()->all()
            ?? Site::all()->map->handle()->values()->all();

        if (! in_array($site, $handles, true)) {
            sort($handles);

            throw new ToolException(sprintf(
                "site '%s' not found — available: %s",
                $site,
                implode(', ', $handles),
            ));
        }

        $this->ensureSiteAccess($user, $site);

        return $site;
    }
```

Create `src/Tools/TermsList.php`:

```php
<?php

namespace Danielgnh\StatamicMcp\Tools;

use Danielgnh\StatamicMcp\Tools\Concerns\ResolvesSites;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;

#[Name('terms_list')]
#[Description('List taxonomy terms with summary columns only (id, title, slug, url, updated_at) — never field data. Paginated; returns total and next_page. Use terms_get for a term\'s field data.')]
#[IsReadOnly]
class TermsList extends Tool
{
    use ResolvesSites;

    public function schema(JsonSchema $schema): array
    {
        return [
            'taxonomy' => $schema->string()->description('Taxonomy handle, e.g. "tags".')->required(),
            'site' => $schema->string()->description('Site handle. Defaults to the default site.'),
            'search' => $schema->string()->description('Filter terms by title (case-insensitive contains match).'),
            'limit' => $schema->integer()->description('Terms per page, max 100. Defaults to the configured per_page.'),
            'page' => $schema->integer()->description('Page number.')->default(1),
        ];
    }

    protected function execute(Request $request): Response
    {
        $validated = $request->validate(
            [
                'taxonomy' => 'required|string',
                'site' => 'sometimes|string',
                'search' => 'sometimes|string',
                'limit' => 'sometimes|integer|min:1',
                'page' => 'sometimes|integer|min:1',
            ],
            ['taxonomy.required' => 'Pass a taxonomy handle, e.g. "tags".'],
        );

        $taxonomy = $validated['taxonomy'];

        $this->ensureExposed('taxonomies', $taxonomy);

        $user = $this->user($request);

        $this->ensurePermission($user, "view {$taxonomy} terms");

        $site = $this->resolveSite($request, $user, Taxonomy::findByHandle($taxonomy)->sites());

        $query = Term::query()
            ->where('taxonomy', $taxonomy)
            ->where('site', $site)
            ->orderBy('title', 'asc');

        if ($search = $request->get('search')) {
            $query->where('title', 'like', "%{$search}%");
        }

        $perPage = max(1, min((int) ($request->get('limit') ?? config('statamic.mcp.per_page', 25)), 100));
        $page = max(1, (int) $request->get('page', 1));

        $paginated = $query->paginate($perPage, ['*'], 'page', $page);

        return $this->json([
            'taxonomy' => $taxonomy,
            'site' => $site,
            'total' => $paginated->total(),
            'page' => $paginated->currentPage(),
            'per_page' => $paginated->perPage(),
            'next_page' => $paginated->hasMorePages() ? $paginated->currentPage() + 1 : null,
            'terms' => collect($paginated->items())->map(fn ($term) => [
                'id' => $term->id(),
                'title' => $term->title(),
                'slug' => $term->slug(),
                'url' => $term->url(),
                // get('updated_at') only — never fileLastModified(), which
                // breaks on items that were never written to disk (tests).
                'updated_at' => ($timestamp = $term->get('updated_at'))
                    ? Carbon::createFromTimestamp($timestamp, config('app.timezone'))->toIso8601String()
                    : null,
            ])->values()->all(),
        ]);
    }
}
```

Then open `src/Server.php` and make sure `$tools` contains `Tools\TermsList::class` directly after `Tools\EntriesDelete::class` (§4 order in the contracts appendix, docs/superpowers/plans/2026-07-09-statamic-mcp-contracts.md). If your `Server.php` already carries the full 14-tool contracts list, change nothing:

```php
        Tools\EntriesDelete::class,
        Tools\TermsList::class,
    ];
```

- [ ] **Step 4: Run the tests and confirm they pass**

```
vendor/bin/pest tests/Feature/TermsListTest.php
```

Expected: `PASS  Tests\Feature\TermsListTest` — `Tests: 5 passed`.

```
vendor/bin/pest
```

Expected: every test in the suite passes, 0 failures.

- [ ] **Step 5: Format**

```
composer format
```

Expected: Pint reports the touched files as fixed or clean.

- [ ] **Step 6: Commit**

```
git add src/Tools/Concerns/ResolvesSites.php src/Tools/TermsList.php src/Server.php tests/Feature/TermsListTest.php
git commit -m "feat: add terms_list tool" -m "Paginated summary listing for taxonomy terms with site filter, title search, exposure allowlist and native view-permission gating.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

### Task 18: `terms_get` tool

**Files:**
- Create: `src/Tools/TermsGet.php`
- Modify: `src/Server.php` (add the `Tools\TermsGet::class` line to `$tools` — skip if already present)
- Test: `tests/Feature/TermsGetTest.php`

Contract (spec §4 rows 8–12, review-fixed): `id` (`"{taxonomy}::{slug}"`) or `taxonomy`+`slug`; optional `site`, `format` (raw default | augmented), `fields`. Multi-site follows the **globals rule**: `$term->in($site)` reads that site's localized data override; values not overridden locally are reported under `inherited` with `origin_site` (a term's localizations are data overrides inside one term — verified 6.x `LocalizedTerm`). No status/date fields. Long raw values become read-only previews unless requested via `fields`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/TermsGetTest.php`:

```php
<?php

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tools\TermsGet;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;

it('gets raw term data by id', function () {
    Fixtures::site();
    Fixtures::tags();

    Term::make()->taxonomy('tags')->slug('php')->data(['title' => 'PHP'])->save();

    $user = Fixtures::makeUser('view tags terms');

    Server::actingAs($user)
        ->tool(TermsGet::class, ['id' => 'tags::php'])
        ->assertOk()
        ->assertSee('"format":"raw"')
        ->assertSee('"title":"PHP"')
        ->assertSee('"id":"tags::php"');
});

it('gets a term by taxonomy and slug', function () {
    Fixtures::site();
    Fixtures::tags();

    Term::make()->taxonomy('tags')->slug('php')->data(['title' => 'PHP'])->save();

    $user = Fixtures::makeUser('view tags terms');

    Server::actingAs($user)
        ->tool(TermsGet::class, ['taxonomy' => 'tags', 'slug' => 'php'])
        ->assertOk()
        ->assertSee('"id":"tags::php"');
});

it('reads a site localization override', function () {
    Fixtures::multisite();
    Fixtures::tags();
    Taxonomy::findByHandle('tags')->sites(['en', 'de'])->save();

    $term = Term::make()->taxonomy('tags')->slug('php')->data(['title' => 'PHP']);
    $term->dataForLocale('de', ['title' => 'PHP (DE)']);
    $term->save();

    $user = Fixtures::makeUser('view tags terms', 'access de site');

    Server::actingAs($user)
        ->tool(TermsGet::class, ['id' => 'tags::php', 'site' => 'de'])
        ->assertOk()
        ->assertSee('"title":"PHP (DE)"')
        ->assertSee('"origin_site":"en"');
});

it('annotates values inherited from the default site', function () {
    Fixtures::multisite();
    Fixtures::tags();
    Taxonomy::findByHandle('tags')->sites(['en', 'de'])->save();

    Term::make()->taxonomy('tags')->slug('php')->data(['title' => 'PHP'])->save();

    $user = Fixtures::makeUser('view tags terms', 'access de site');

    Server::actingAs($user)
        ->tool(TermsGet::class, ['id' => 'tags::php', 'site' => 'de'])
        ->assertOk()
        ->assertSee('"inherited":{"title":"PHP"}')
        ->assertSee('"data":[]'); // no local overrides yet — empty PHP array encodes as []
});

it('requires the site permission for a non-default site', function () {
    Fixtures::multisite();
    Fixtures::tags();
    Taxonomy::findByHandle('tags')->sites(['en', 'de'])->save();

    Term::make()->taxonomy('tags')->slug('php')->data(['title' => 'PHP'])->save();

    $user = Fixtures::makeUser('view tags terms'); // no 'access de site'

    Server::actingAs($user)
        ->tool(TermsGet::class, ['id' => 'tags::php', 'site' => 'de'])
        ->assertHasErrors(["requires 'access de site' — grant it to a role of {$user->email()} in the Control Panel"]);
});

it('marks augmented data as not writable', function () {
    Fixtures::site();
    Fixtures::tags();

    Term::make()->taxonomy('tags')->slug('php')->data(['title' => 'PHP'])->save();

    $user = Fixtures::makeUser('view tags terms');

    Server::actingAs($user)
        ->tool(TermsGet::class, ['id' => 'tags::php', 'format' => 'augmented'])
        ->assertOk()
        ->assertSee('augmented values are read-only');
});

it('names a remedy when the term does not exist', function () {
    Fixtures::site();
    Fixtures::tags();

    $user = Fixtures::makeUser('view tags terms');

    Server::actingAs($user)
        ->tool(TermsGet::class, ['id' => 'tags::nope'])
        ->assertHasErrors(["term 'tags::nope' not found — use terms_list with taxonomy 'tags' to see available terms"]);
});
```

- [ ] **Step 2: Run the test and confirm the failure**

```
vendor/bin/pest tests/Feature/TermsGetTest.php
```

Expected: all 7 tests error with `Error: Class "Danielgnh\StatamicMcp\Tools\TermsGet" not found`.

- [ ] **Step 3: Implement the tool and register it**

Create `src/Tools/TermsGet.php`:

```php
<?php

namespace Danielgnh\StatamicMcp\Tools;

use Danielgnh\StatamicMcp\Tools\Concerns\ResolvesSites;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Statamic\Facades\Term;

#[Name('terms_get')]
#[Description('Get one taxonomy term by id ("{taxonomy}::{slug}") or by taxonomy + slug. format=raw (default) returns the round-trippable data shape for terms_update; format=augmented is read-only — never send augmented values back into terms_update. With site, returns that site\'s local overrides in data plus what is inherited from the default site.')]
#[IsReadOnly]
class TermsGet extends Tool
{
    use ResolvesSites;

    private const PREVIEW_THRESHOLD = 2000;

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('Term id: "{taxonomy}::{slug}", e.g. "tags::php". Or pass taxonomy + slug instead.'),
            'taxonomy' => $schema->string()->description('Taxonomy handle — use together with slug when id is not passed.'),
            'slug' => $schema->string()->description('Term slug — use together with taxonomy when id is not passed.'),
            'site' => $schema->string()->description('Site handle. Defaults to the default site.'),
            'format' => $schema->string()->enum(['raw', 'augmented'])->description('raw (default) = round-trippable write shape; augmented = rendered values, read-only.'),
            'fields' => $schema->array()->description('Top-level field handles to return; also disables long-value truncation for those fields.'),
        ];
    }

    protected function execute(Request $request): Response
    {
        $request->validate(
            [
                'id' => 'required_without_all:taxonomy,slug|string',
                'taxonomy' => 'required_without:id|string',
                'slug' => 'required_without:id|string',
                'site' => 'sometimes|string',
                'format' => 'sometimes|string|in:raw,augmented',
                'fields' => 'sometimes|array',
                'fields.*' => 'string',
            ],
            [
                'id.required_without_all' => 'Pass id ("{taxonomy}::{slug}", e.g. "tags::php") or taxonomy + slug.',
            ],
        );

        $id = $request->get('id') ?? $request->get('taxonomy').'::'.$request->get('slug');

        if (! str_contains($id, '::')) {
            throw new ToolException("term ids look like '{taxonomy}::{slug}', e.g. 'tags::php' — got '{$id}'");
        }

        [$taxonomyHandle] = explode('::', $id, 2);

        $this->ensureExposed('taxonomies', $taxonomyHandle);

        $user = $this->user($request);

        $this->ensurePermission($user, "view {$taxonomyHandle} terms");

        if (! $term = Term::find($id)) {
            throw new ToolException("term '{$id}' not found — use terms_list with taxonomy '{$taxonomyHandle}' to see available terms");
        }

        $site = $this->resolveSite($request, $user, $term->taxonomy()->sites());

        $localized = $term->in($site);
        $defaultSite = $term->taxonomy()->sites()->first();
        $requestedFields = $request->get('fields') ?? [];
        $format = $request->get('format') ?? 'raw';

        $response = [
            'id' => $term->id(),
            'taxonomy' => $taxonomyHandle,
            'slug' => $localized->slug(),
            'site' => $site,
            'format' => $format,
        ];

        if ($format === 'augmented') {
            $augmented = $localized->toAugmentedArray();

            if ($requestedFields !== []) {
                $augmented = array_intersect_key($augmented, array_flip($requestedFields));
            }

            $response['data'] = $augmented;
            $response['warning'] = 'augmented values are read-only — never send them back into terms_update; fetch format=raw first';
        } else {
            $local = $localized->data()->all();

            if ($requestedFields !== []) {
                $local = array_intersect_key($local, array_flip($requestedFields));
            }

            $response['data'] = $this->withPreviews($local, $requestedFields);

            if ($site !== $defaultSite) {
                // Term localizations are data overrides within one term (the
                // globals rule): everything not overridden locally inherits
                // from the default site's data.
                $inherited = array_diff_key(
                    $term->in($defaultSite)->data()->all(),
                    $localized->data()->all(),
                );

                if ($requestedFields !== []) {
                    $inherited = array_intersect_key($inherited, array_flip($requestedFields));
                }

                $response['origin_site'] = $defaultSite;
                $response['inherited'] = $this->withPreviews($inherited, $requestedFields);
                $response['note'] = "data = this site's local overrides (the round-trippable shape for terms_update with site '{$site}'); inherited = values coming from the default site";
            }
        }

        $response['cp_edit_url'] = $localized->editUrl();

        return $this->json($response);
    }

    /**
     * Spec §4: long values are truncated to previews unless explicitly
     * requested via fields — previews are NOT writable.
     */
    private function withPreviews(array $data, array $requestedFields): array
    {
        foreach ($data as $handle => $value) {
            if (in_array($handle, $requestedFields, true)) {
                continue;
            }

            $encoded = is_string($value)
                ? $value
                : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if ($encoded !== false && strlen($encoded) > self::PREVIEW_THRESHOLD) {
                $data[$handle] = [
                    '__preview' => Str::limit($encoded, 300),
                    'truncated' => true,
                    'note' => "NOT writable — call terms_get with fields: [\"{$handle}\"] for the full raw value before editing",
                ];
            }
        }

        return $data;
    }
}
```

Then in `src/Server.php`, ensure `$tools` contains `Tools\TermsGet::class` directly after `Tools\TermsList::class` (skip if the full contracts list is already there):

```php
        Tools\TermsList::class,
        Tools\TermsGet::class,
    ];
```

- [ ] **Step 4: Run the tests and confirm they pass**

```
vendor/bin/pest tests/Feature/TermsGetTest.php
```

Expected: `PASS  Tests\Feature\TermsGetTest` — `Tests: 7 passed`.

```
vendor/bin/pest
```

Expected: whole suite green, 0 failures.

- [ ] **Step 5: Format**

```
composer format
```

- [ ] **Step 6: Commit**

```
git add src/Tools/TermsGet.php src/Server.php tests/Feature/TermsGetTest.php
git commit -m "feat: add terms_get tool" -m "Raw/augmented term reads with per-site localization overrides, inherited-value annotation, field selection, and long-value previews.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

### Task 19: `terms_create` tool

**Files:**
- Create: `src/Tools/TermsCreate.php`
- Modify: `src/Server.php` (add the `Tools\TermsCreate::class` line to `$tools` — skip if already present)
- Test: `tests/Feature/TermsCreateTest.php`

Contract (spec §4 rows 8–12): mirrors `entries_create` minus `date`/`published` (terms have no status — a created term is live). Permission is `create {taxonomy} terms` (verified facts §3: no publish permission exists for terms). Slug generated from `data.title` when omitted; collision names the existing id + "use terms_update". Unknown-key rejection and blueprint validation run through Statamic's own pipeline (spec §8) via the shared `ValidatesBlueprintData` concern from 04-entries Task 13 (`rejectUnknownKeys` + `validateAgainstBlueprint`, Blueprint-first). The created-term result string is `self::LIVENESS_CREATED` ('created — live'), a constant defined on the base `Tool` alongside the other liveness constants. Terms are created in the default site; localizing is `terms_update`'s job (spec row 8–12: localizations are data overrides written via `$term->in($site)`).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/TermsCreateTest.php`:

```php
<?php

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tools\TermsCreate;
use Statamic\Facades\Term;

it('creates a term with a slug generated from the title', function () {
    Fixtures::site();
    Fixtures::tags();

    $user = Fixtures::makeUser('create tags terms');

    Server::actingAs($user)
        ->tool(TermsCreate::class, ['taxonomy' => 'tags', 'data' => ['title' => 'New Tag']])
        ->assertOk()
        ->assertSee('"id":"tags::new-tag"')
        ->assertSee('created — live')
        ->assertSee('"cp_edit_url"');

    expect(Term::find('tags::new-tag'))->not->toBeNull();
});

it('reports a slug collision with the existing id and remedy', function () {
    Fixtures::site();
    Fixtures::tags();

    Term::make()->taxonomy('tags')->slug('php')->data(['title' => 'PHP'])->save();

    $user = Fixtures::makeUser('create tags terms');

    Server::actingAs($user)
        ->tool(TermsCreate::class, ['taxonomy' => 'tags', 'data' => ['title' => 'PHP']])
        ->assertHasErrors(["term 'php' already exists — use terms_update with id 'tags::php'"]);
});

it('rejects unknown field keys with a did-you-mean hint', function () {
    Fixtures::site();
    Fixtures::tags();

    $user = Fixtures::makeUser('create tags terms');

    Server::actingAs($user)
        ->tool(TermsCreate::class, ['taxonomy' => 'tags', 'data' => ['titel' => 'PHP']])
        ->assertHasErrors(["unknown field titel — valid handles: title — did you mean 'title' instead of 'titel'?"]);
});

it('surfaces blueprint validation failures as field messages', function () {
    Fixtures::site();
    Fixtures::tags();

    $user = Fixtures::makeUser('create tags terms');

    Server::actingAs($user)
        ->tool(TermsCreate::class, ['taxonomy' => 'tags', 'data' => ['title' => '']])
        ->assertHasErrors(['validation failed']);
});

it('denies creating without the create permission', function () {
    Fixtures::site();
    Fixtures::tags();

    $user = Fixtures::makeUser('view tags terms'); // can view, cannot create

    Server::actingAs($user)
        ->tool(TermsCreate::class, ['taxonomy' => 'tags', 'data' => ['title' => 'Nope']])
        ->assertHasErrors(["requires 'create tags terms' — grant it to a role of {$user->email()} in the Control Panel"]);
});

it('refuses a non-default site and points to terms_update for localization', function () {
    Fixtures::multisite();
    Fixtures::tags();
    Statamic\Facades\Taxonomy::findByHandle('tags')->sites(['en', 'de'])->save();

    $user = Fixtures::makeUser('create tags terms', 'access de site');

    Server::actingAs($user)
        ->tool(TermsCreate::class, ['taxonomy' => 'tags', 'data' => ['title' => 'Neu'], 'site' => 'de'])
        ->assertHasErrors(["terms are created in the default site 'en' — create the term first, then localize it with terms_update and site 'de'"]);
});

it('is hidden when the server is read-only', function () {
    Fixtures::site();
    Fixtures::tags();

    config(['statamic.mcp.read_only' => true]);

    Server::actingAs(Fixtures::makeSuper())
        ->tool(TermsCreate::class, ['taxonomy' => 'tags', 'data' => ['title' => 'Nope']])
        ->assertHasErrors(['Tool [terms_create] not found']);
});
```

- [ ] **Step 2: Run the test and confirm the failure**

```
vendor/bin/pest tests/Feature/TermsCreateTest.php
```

Expected: all 7 tests error with `Error: Class "Danielgnh\StatamicMcp\Tools\TermsCreate" not found`.

- [ ] **Step 3: Implement the tool and register it**

Create `src/Tools/TermsCreate.php`:

```php
<?php

namespace Danielgnh\StatamicMcp\Tools;

use Danielgnh\StatamicMcp\Tools\Concerns\ValidatesBlueprintData;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;

#[Name('terms_create')]
#[Description('Create a taxonomy term from raw field data (get the shape from blueprints_get; never send augmented data). Slug is generated from data.title when omitted. Terms are created in the default site — localize afterwards with terms_update and its site parameter. Terms have no draft state: a created term is live immediately.')]
class TermsCreate extends Tool
{
    use ValidatesBlueprintData;

    public function schema(JsonSchema $schema): array
    {
        return [
            'taxonomy' => $schema->string()->description('Taxonomy handle, e.g. "tags".')->required(),
            'data' => $schema->object()->description('Raw field data keyed by blueprint field handle.')->required(),
            'slug' => $schema->string()->description('URL slug. Generated from data.title when omitted.'),
            'site' => $schema->string()->description('Must be the default site when given — terms are created in the default site and localized via terms_update.'),
        ];
    }

    public function shouldRegister(Request $request): bool
    {
        return $this->writesEnabled();
    }

    protected function execute(Request $request): Response
    {
        // Re-check the registration gate: stale client tool caches may still
        // call hidden tools (spec §6 layer 1).
        if (! $this->writesEnabled()) {
            throw new ToolException('this server is read-only (read_only is enabled in config/statamic/mcp.php)');
        }

        $validated = $request->validate(
            [
                'taxonomy' => 'required|string',
                'data' => 'required|array',
                'slug' => 'sometimes|string',
                'site' => 'sometimes|string',
            ],
            [
                'taxonomy.required' => 'Pass a taxonomy handle, e.g. "tags".',
                'data.required' => 'Pass raw field data, e.g. {"title": "PHP"} — call blueprints_get for the field shape.',
            ],
        );

        $taxonomyHandle = $validated['taxonomy'];
        $data = $validated['data'];

        $this->ensureExposed('taxonomies', $taxonomyHandle);

        $user = $this->user($request);

        $this->ensurePermission($user, "create {$taxonomyHandle} terms");

        $taxonomy = Taxonomy::findByHandle($taxonomyHandle);
        $defaultSite = $taxonomy->sites()->first();

        if (($site = $request->get('site')) && $site !== $defaultSite) {
            throw new ToolException(sprintf(
                "terms are created in the default site '%s' — create the term first, then localize it with terms_update and site '%s'",
                $defaultSite,
                $site,
            ));
        }

        $blueprint = $taxonomy->termBlueprint();

        $this->rejectUnknownKeys($data, $blueprint);
        $this->validateAgainstBlueprint($blueprint, $data);

        $slug = Str::slug($validated['slug'] ?? (string) ($data['title'] ?? ''));

        if ($slug === '') {
            throw new ToolException('pass a slug, or include a title in data so one can be generated');
        }

        if ($existing = Term::find("{$taxonomyHandle}::{$slug}")) {
            throw new ToolException(sprintf(
                "term '%s' already exists — use terms_update with id '%s'",
                $slug,
                $existing->id(),
            ));
        }

        $term = Term::make()->taxonomy($taxonomyHandle)->slug($slug);
        $term->dataForLocale($defaultSite, $data);

        $localized = $term->in($defaultSite)->updateLastModified($user);
        $localized->save();

        return $this->json([
            'id' => $term->id(),
            'taxonomy' => $taxonomyHandle,
            'slug' => $slug,
            'site' => $defaultSite,
            // Terms have no draft state (spec §4 rows 8-12); LIVENESS_CREATED
            // ('created — live') is defined on the base Tool alongside the
            // other liveness constants.
            ...$this->liveness($localized, self::LIVENESS_CREATED),
        ]);
    }
}
```

Then in `src/Server.php`, ensure `$tools` contains `Tools\TermsCreate::class` directly after `Tools\TermsGet::class` (skip if the full contracts list is already there):

```php
        Tools\TermsGet::class,
        Tools\TermsCreate::class,
    ];
```

- [ ] **Step 4: Run the tests and confirm they pass**

```
vendor/bin/pest tests/Feature/TermsCreateTest.php
```

Expected: `PASS  Tests\Feature\TermsCreateTest` — `Tests: 7 passed`.

```
vendor/bin/pest
```

Expected: whole suite green, 0 failures.

- [ ] **Step 5: Format**

```
composer format
```

- [ ] **Step 6: Commit**

```
git add src/Tools/TermsCreate.php src/Server.php tests/Feature/TermsCreateTest.php
git commit -m "feat: add terms_create tool" -m "Creates default-site terms from raw data with slug generation, collision remedy, unknown-key rejection, and blueprint validation. Gated by writesEnabled and 'create {taxonomy} terms'.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

### Task 20: `terms_update` tool

**Files:**
- Create: `src/Tools/TermsUpdate.php`
- Modify: `src/Server.php` (add the `Tools\TermsUpdate::class` line to `$tools` — skip if already present)
- Test: `tests/Feature/TermsUpdateTest.php`

Contract (spec §4 rows 8–12, review-fixed): shallow top-level-key merge; explicit `null` stores a local null (clearing a required field errors via merged validation); no-op when the merged result equals current data; no `date`/`published`. **Multi-site is the globals rule, not the entries rule**: `site` writes that site's localized data override via `$term->in($site)` and transparently creates it on first write (verified 6.x: `LocalizedTerm::data()` writes `dataForLocale($locale)` — no separate localization entity exists). Validation runs against the *effective* values (default-site data + local overrides + patch) so partial localized updates never false-fail required fields, while only the local override is stored. Permission is `edit {taxonomy} terms`.

The tags fixture blueprint has only a `title` field, so these tests build a two-field `topics` taxonomy inline to exercise merging.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/TermsUpdateTest.php`:

```php
<?php

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tools\TermsUpdate;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;

function makeTopicsTaxonomy(): void
{
    tap(Taxonomy::make('topics')->title('Topics'))->save();

    Blueprint::makeFromFields([
        'title' => ['type' => 'text', 'validate' => 'required'],
        'description' => ['type' => 'textarea'],
    ])->setHandle('topic')->setNamespace('taxonomies.topics')->save();
}

it('shallow-merges data, preserving untouched top-level keys', function () {
    Fixtures::site();
    makeTopicsTaxonomy();

    Term::make()->taxonomy('topics')->slug('alpha')
        ->data(['title' => 'Alpha', 'description' => 'Old'])->save();

    $user = Fixtures::makeUser('edit topics terms');

    Server::actingAs($user)
        ->tool(TermsUpdate::class, ['id' => 'topics::alpha', 'data' => ['description' => 'New']])
        ->assertOk()
        ->assertSee('"title":"Alpha"')
        ->assertSee('"description":"New"')
        ->assertSee('updated — live');

    expect(Term::find('topics::alpha')->in('en')->data()->get('description'))->toBe('New');
});

it('stores an explicit null to clear an optional field', function () {
    Fixtures::site();
    makeTopicsTaxonomy();

    Term::make()->taxonomy('topics')->slug('alpha')
        ->data(['title' => 'Alpha', 'description' => 'Old'])->save();

    $user = Fixtures::makeUser('edit topics terms');

    Server::actingAs($user)
        ->tool(TermsUpdate::class, ['id' => 'topics::alpha', 'data' => ['description' => null]])
        ->assertOk()
        ->assertSee('"description":null');
});

it('rejects clearing a required field via merged validation', function () {
    Fixtures::site();
    makeTopicsTaxonomy();

    Term::make()->taxonomy('topics')->slug('alpha')->data(['title' => 'Alpha'])->save();

    $user = Fixtures::makeUser('edit topics terms');

    Server::actingAs($user)
        ->tool(TermsUpdate::class, ['id' => 'topics::alpha', 'data' => ['title' => null]])
        ->assertHasErrors(['validation failed']);
});

it('is a no-op when the merged result equals current data', function () {
    Fixtures::site();
    makeTopicsTaxonomy();

    Term::make()->taxonomy('topics')->slug('alpha')->data(['title' => 'Alpha'])->save();

    $user = Fixtures::makeUser('edit topics terms');

    Server::actingAs($user)
        ->tool(TermsUpdate::class, ['id' => 'topics::alpha', 'data' => ['title' => 'Alpha']])
        ->assertOk()
        ->assertSee('no-op — merged data equals current data; nothing saved');
});

it('creates a site localization override transparently on first write', function () {
    Fixtures::multisite();
    makeTopicsTaxonomy();
    Taxonomy::findByHandle('topics')->sites(['en', 'de'])->save();

    Term::make()->taxonomy('topics')->slug('alpha')->data(['title' => 'Alpha'])->save();

    $user = Fixtures::makeUser('edit topics terms', 'access de site');

    Server::actingAs($user)
        ->tool(TermsUpdate::class, ['id' => 'topics::alpha', 'data' => ['title' => 'Alpha DE'], 'site' => 'de'])
        ->assertOk()
        ->assertSee('"site":"de"')
        ->assertSee('"title":"Alpha DE"');

    $term = Term::find('topics::alpha');
    expect($term->in('de')->data()->get('title'))->toBe('Alpha DE')
        ->and($term->in('en')->data()->get('title'))->toBe('Alpha'); // default site untouched
});

it('denies updating without the edit permission', function () {
    Fixtures::site();
    makeTopicsTaxonomy();

    Term::make()->taxonomy('topics')->slug('alpha')->data(['title' => 'Alpha'])->save();

    $user = Fixtures::makeUser('view topics terms');

    Server::actingAs($user)
        ->tool(TermsUpdate::class, ['id' => 'topics::alpha', 'data' => ['title' => 'X']])
        ->assertHasErrors(["requires 'edit topics terms' — grant it to a role of {$user->email()} in the Control Panel"]);
});

it('changes the slug and reports the new id', function () {
    Fixtures::site();
    makeTopicsTaxonomy();

    Term::make()->taxonomy('topics')->slug('alpha')->data(['title' => 'Alpha'])->save();

    $user = Fixtures::makeUser('edit topics terms');

    Server::actingAs($user)
        ->tool(TermsUpdate::class, ['id' => 'topics::alpha', 'data' => [], 'slug' => 'renamed'])
        ->assertOk()
        ->assertSee('"id":"topics::renamed"');
});
```

Note on the last test: `data` is required but may be an empty object only alongside `slug`; the schema below validates `data` with `present|array` (not `required`, which fails on empty arrays).

- [ ] **Step 2: Run the test and confirm the failure**

```
vendor/bin/pest tests/Feature/TermsUpdateTest.php
```

Expected: all 7 tests error with `Error: Class "Danielgnh\StatamicMcp\Tools\TermsUpdate" not found`.

- [ ] **Step 3: Implement the tool and register it**

Create `src/Tools/TermsUpdate.php`:

```php
<?php

namespace Danielgnh\StatamicMcp\Tools;

use Danielgnh\StatamicMcp\Tools\Concerns\ResolvesSites;
use Danielgnh\StatamicMcp\Tools\Concerns\ValidatesBlueprintData;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Statamic\Facades\Term;

#[Name('terms_update')]
#[Description('Update a taxonomy term with a shallow top-level-key merge of raw field data — nested structures are replaced wholesale; explicit null clears a field. With site, writes that site\'s localized data override, creating it transparently on first write. An update that changes nothing is a no-op. Terms have no draft state. Never send augmented data.')]
#[IsIdempotent]
class TermsUpdate extends Tool
{
    use ResolvesSites;
    use ValidatesBlueprintData;

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('Term id: "{taxonomy}::{slug}", e.g. "tags::php".')->required(),
            'data' => $schema->object()->description('Raw field data to merge, keyed by blueprint field handle. May be empty when only changing the slug.')->required(),
            'slug' => $schema->string()->description('New slug. On the default site this changes the term id; on other sites it stores a localized slug.'),
            'site' => $schema->string()->description('Site handle. Defaults to the default site. Writes that site\'s data override.'),
        ];
    }

    public function shouldRegister(Request $request): bool
    {
        return $this->writesEnabled();
    }

    protected function execute(Request $request): Response
    {
        // Re-check the registration gate: stale client tool caches may still
        // call hidden tools (spec §6 layer 1).
        if (! $this->writesEnabled()) {
            throw new ToolException('this server is read-only (read_only is enabled in config/statamic/mcp.php)');
        }

        $validated = $request->validate(
            [
                'id' => 'required|string',
                'data' => 'present|array',
                'slug' => 'sometimes|string',
                'site' => 'sometimes|string',
            ],
            [
                'id.required' => 'Pass a term id: "{taxonomy}::{slug}", e.g. "tags::php".',
                'data.present' => 'Pass data to merge (may be an empty object when only changing the slug).',
            ],
        );

        $id = $validated['id'];
        $patch = $validated['data'];

        if (! str_contains($id, '::')) {
            throw new ToolException("term ids look like '{taxonomy}::{slug}', e.g. 'tags::php' — got '{$id}'");
        }

        [$taxonomyHandle] = explode('::', $id, 2);

        $this->ensureExposed('taxonomies', $taxonomyHandle);

        $user = $this->user($request);

        $this->ensurePermission($user, "edit {$taxonomyHandle} terms");

        if (! $term = Term::find($id)) {
            throw new ToolException("term '{$id}' not found — use terms_list with taxonomy '{$taxonomyHandle}' to see available terms");
        }

        $site = $this->resolveSite($request, $user, $term->taxonomy()->sites());

        $localized = $term->in($site);
        $defaultSite = $term->taxonomy()->sites()->first();
        $blueprint = $localized->blueprint();

        $this->rejectUnknownKeys($patch, $blueprint);

        $existingLocal = $localized->data()->all();
        $newLocal = array_merge($existingLocal, $patch);

        // Validate the EFFECTIVE values (default-site data under the local
        // override) so a partial localized patch never false-fails required
        // fields — only the local override is stored (globals rule).
        $this->validateAgainstBlueprint(
            $blueprint,
            array_merge($term->in($defaultSite)->data()->all(), $newLocal),
        );

        $newSlug = isset($validated['slug']) ? Str::slug($validated['slug']) : null;
        $slugChanged = $newSlug !== null && $newSlug !== $localized->slug();

        if ($slugChanged && $site === $defaultSite && Term::find("{$taxonomyHandle}::{$newSlug}")) {
            throw new ToolException("term '{$newSlug}' already exists in taxonomy '{$taxonomyHandle}' — pick another slug");
        }

        if (! $slugChanged && $newLocal == $existingLocal) {
            return $this->json([
                'id' => $term->id(),
                'site' => $site,
                'result' => 'no-op — merged data equals current data; nothing saved',
                'cp_edit_url' => $localized->editUrl(),
            ]);
        }

        if ($slugChanged) {
            // v6 LocalizedTerm::slug(): default site renames the term (new id);
            // other sites store a localized 'slug' data override.
            $localized->slug($newSlug);
        }

        $localized->data($newLocal)->updateLastModified($user)->save();

        return $this->json([
            'id' => $term->id(),
            'taxonomy' => $taxonomyHandle,
            'slug' => $localized->slug(),
            'site' => $site,
            'data' => $localized->data()->all(),
            ...$this->liveness($localized, self::LIVENESS_LIVE),
        ]);
    }
}
```

Then in `src/Server.php`, ensure `$tools` contains `Tools\TermsUpdate::class` directly after `Tools\TermsCreate::class` (skip if the full contracts list is already there):

```php
        Tools\TermsCreate::class,
        Tools\TermsUpdate::class,
    ];
```

- [ ] **Step 4: Run the tests and confirm they pass**

```
vendor/bin/pest tests/Feature/TermsUpdateTest.php
```

Expected: `PASS  Tests\Feature\TermsUpdateTest` — `Tests: 7 passed`.

```
vendor/bin/pest
```

Expected: whole suite green, 0 failures.

- [ ] **Step 5: Format**

```
composer format
```

- [ ] **Step 6: Commit**

```
git add src/Tools/TermsUpdate.php src/Server.php tests/Feature/TermsUpdateTest.php
git commit -m "feat: add terms_update tool" -m "Shallow-merge term updates with effective-value validation, no-op detection, slug renames, and transparent per-site localization overrides (globals rule).

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

### Task 21: `terms_delete` tool

**Files:**
- Create: `src/Tools/TermsDelete.php`
- Modify: `src/Server.php` (add the `Tools\TermsDelete::class` line to `$tools` — skip if already present)
- Test: `tests/Feature/TermsDeleteTest.php`

Contract (spec §4 row 12 + §6 layer 4): gated identically to `entries_delete` — not even registered unless `deletes` is enabled (and writes are enabled), `#[IsDestructive]` when on, in-handler re-check for stale client caches, permission `delete {taxonomy} terms`. No `site` parameter (spec §4: deletes never take `site`); deleting a term removes all of its site localizations.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/TermsDeleteTest.php`:

```php
<?php

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tools\TermsDelete;
use Statamic\Facades\Term;

it('deletes a term when deletes are enabled', function () {
    config(['statamic.mcp.deletes' => true]);

    Fixtures::site();
    Fixtures::tags();

    Term::make()->taxonomy('tags')->slug('php')->data(['title' => 'PHP'])->save();

    $user = Fixtures::makeUser('delete tags terms');

    Server::actingAs($user)
        ->tool(TermsDelete::class, ['id' => 'tags::php'])
        ->assertOk()
        ->assertSee('"deleted":true')
        ->assertSee('"id":"tags::php"');

    expect(Term::find('tags::php'))->toBeNull();
});

it('is not registered when deletes are disabled', function () {
    // config default: statamic.mcp.deletes = false
    Fixtures::site();
    Fixtures::tags();

    Term::make()->taxonomy('tags')->slug('php')->data(['title' => 'PHP'])->save();

    Server::actingAs(Fixtures::makeSuper())
        ->tool(TermsDelete::class, ['id' => 'tags::php'])
        ->assertHasErrors(['Tool [terms_delete] not found']);

    expect(Term::find('tags::php'))->not->toBeNull();
});

it('denies deleting without the delete permission', function () {
    config(['statamic.mcp.deletes' => true]);

    Fixtures::site();
    Fixtures::tags();

    Term::make()->taxonomy('tags')->slug('php')->data(['title' => 'PHP'])->save();

    $user = Fixtures::makeUser('edit tags terms'); // can edit, cannot delete

    Server::actingAs($user)
        ->tool(TermsDelete::class, ['id' => 'tags::php'])
        ->assertHasErrors(["requires 'delete tags terms' — grant it to a role of {$user->email()} in the Control Panel"]);

    expect(Term::find('tags::php'))->not->toBeNull();
});
```

- [ ] **Step 2: Run the test and confirm the failure**

```
vendor/bin/pest tests/Feature/TermsDeleteTest.php
```

Expected: all 3 tests error with `Error: Class "Danielgnh\StatamicMcp\Tools\TermsDelete" not found`.

- [ ] **Step 3: Implement the tool and register it**

Create `src/Tools/TermsDelete.php`:

```php
<?php

namespace Danielgnh\StatamicMcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Statamic\Facades\Term;

#[Name('terms_delete')]
#[Description('Permanently delete a taxonomy term, including all of its site localizations. Cannot be undone. Only available when deletes are enabled in config/statamic/mcp.php.')]
#[IsDestructive]
class TermsDelete extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('Term id: "{taxonomy}::{slug}", e.g. "tags::php".')->required(),
        ];
    }

    public function shouldRegister(Request $request): bool
    {
        return $this->deletesEnabled();
    }

    protected function execute(Request $request): Response
    {
        // Re-check the registration gate: stale client tool caches may still
        // call hidden tools (spec §6 layer 1).
        if (! $this->deletesEnabled()) {
            throw new ToolException('deletes are disabled — set "deletes" to true in config/statamic/mcp.php to enable them');
        }

        $validated = $request->validate(
            ['id' => 'required|string'],
            ['id.required' => 'Pass a term id: "{taxonomy}::{slug}", e.g. "tags::php".'],
        );

        $id = $validated['id'];

        if (! str_contains($id, '::')) {
            throw new ToolException("term ids look like '{taxonomy}::{slug}', e.g. 'tags::php' — got '{$id}'");
        }

        [$taxonomyHandle] = explode('::', $id, 2);

        $this->ensureExposed('taxonomies', $taxonomyHandle);

        $user = $this->user($request);

        $this->ensurePermission($user, "delete {$taxonomyHandle} terms");

        if (! $term = Term::find($id)) {
            throw new ToolException("term '{$id}' not found — use terms_list with taxonomy '{$taxonomyHandle}' to see available terms");
        }

        $term->delete();

        return $this->json([
            'deleted' => true,
            'id' => $id,
            'result' => 'term permanently deleted (all site localizations)',
        ]);
    }
}
```

Then in `src/Server.php`, ensure `$tools` contains `Tools\TermsDelete::class` directly after `Tools\TermsUpdate::class` (skip if the full contracts list is already there):

```php
        Tools\TermsUpdate::class,
        Tools\TermsDelete::class,
    ];
```

- [ ] **Step 4: Run the tests and confirm they pass**

```
vendor/bin/pest tests/Feature/TermsDeleteTest.php
```

Expected: `PASS  Tests\Feature\TermsDeleteTest` — `Tests: 3 passed`.

```
vendor/bin/pest
```

Expected: whole suite green, 0 failures.

- [ ] **Step 5: Format**

```
composer format
```

- [ ] **Step 6: Commit**

```
git add src/Tools/TermsDelete.php src/Server.php tests/Feature/TermsDeleteTest.php
git commit -m "feat: add terms_delete tool" -m "Destructive term deletion, registered only when deletes are enabled, with in-handler gate re-check and 'delete {taxonomy} terms' permission.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

### Task 22: `globals_get` + `globals_update` tools

**Files:**
- Create: `src/Tools/GlobalsGet.php`, `src/Tools/GlobalsUpdate.php`
- Modify: `src/Server.php` (add `Tools\GlobalsGet::class` and `Tools\GlobalsUpdate::class` — after this task, `$tools` must equal the contracts §4 list verbatim)
- Test: `tests/Feature/GlobalsGetTest.php`, `tests/Feature/GlobalsUpdateTest.php`

Contracts (spec §4 rows 13–14):
- `globals_get`: `handle` or omit → exposed sets the user may view, silently omitting the rest; an existing-but-unexposed handle is indistinguishable from a missing one (same `notFound()`, listing only exposed handles). **Permission choice (verified facts §3):** v6 has no `view {handle} globals` permission — `edit {handle} globals` is the ONLY per-set permission and the CP itself gates viewing on it, so "may view" = `edit {handle} globals` (supers auto-pass).
- `globals_update`: shallow merge; missing site localization created transparently (v6: `$set->in($site)` returns the existing `Variables` or a fresh unsaved one via `$set->makeLocalization($site)`; saving persists it — verified 6.x source, and `Variables::save()` busts the set's localizations Blink cache). Sets without a blueprint accept free-form variables; with a blueprint, unknown keys are rejected and the merged result is validated via the shared `ValidatesBlueprintData` concern (04-entries Task 13). `Variables` has `editUrl()` (verified facts §4), so the contracts `liveness()` helper applies unchanged.

- [ ] **Step 1: Write the failing globals_get test**

Create `tests/Feature/GlobalsGetTest.php`:

```php
<?php

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tools\GlobalsGet;
use Statamic\Facades\Blueprint;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Site;

it('returns variables for one exposed set', function () {
    Fixtures::site();
    Fixtures::settings();

    $user = Fixtures::makeUser('edit settings globals');

    Server::actingAs($user)
        ->tool(GlobalsGet::class, ['handle' => 'settings'])
        ->assertOk()
        ->assertSee('"handle":"settings"')
        ->assertSee('"site_name":"Acme"')
        ->assertSee('"cp_edit_url"');
});

it('lists every readable set when handle is omitted, silently omitting denied sets', function () {
    Fixtures::site();
    Fixtures::settings();

    Blueprint::makeFromFields(['tagline' => ['type' => 'text']])
        ->setHandle('footer')->setNamespace('globals')->save();
    $footer = GlobalSet::make('footer')->title('Footer');
    $footer->save();
    $footer->makeLocalization(Site::default()->handle())->data(['tagline' => 'Bye'])->save();

    $user = Fixtures::makeUser('edit settings globals'); // no footer permission

    Server::actingAs($user)
        ->tool(GlobalsGet::class, [])
        ->assertOk()
        ->assertSee('"handle":"settings"')
        ->assertDontSee('"handle":"footer"');
});

it('treats an unexposed set as missing, listing only exposed handles', function () {
    Fixtures::site();
    Fixtures::settings();

    $secrets = GlobalSet::make('secrets')->title('Secrets');
    $secrets->save();

    config(['statamic.mcp.resources.globals' => ['settings']]);

    Server::actingAs(Fixtures::makeSuper())
        ->tool(GlobalsGet::class, ['handle' => 'secrets'])
        ->assertHasErrors(["global 'secrets' not found — available: settings"]);
});

it('reports a truly missing handle with the identical error shape', function () {
    Fixtures::site();
    Fixtures::settings();

    Server::actingAs(Fixtures::makeSuper())
        ->tool(GlobalsGet::class, ['handle' => 'nope'])
        ->assertHasErrors(["global 'nope' not found — available: settings"]);
});

it('reads a site localization', function () {
    Fixtures::multisite();
    Fixtures::settings();

    $set = GlobalSet::findByHandle('settings');
    $set->sites(['en', 'de'])->save();
    $set->makeLocalization('de')->data(['site_name' => 'Acme DE'])->save();

    $user = Fixtures::makeUser('edit settings globals', 'access de site');

    Server::actingAs($user)
        ->tool(GlobalsGet::class, ['handle' => 'settings', 'site' => 'de'])
        ->assertOk()
        ->assertSee('"site":"de"')
        ->assertSee('"site_name":"Acme DE"');
});
```

- [ ] **Step 2: Run the test and confirm the failure**

```
vendor/bin/pest tests/Feature/GlobalsGetTest.php
```

Expected: all 5 tests error with `Error: Class "Danielgnh\StatamicMcp\Tools\GlobalsGet" not found`.

- [ ] **Step 3: Implement globals_get and register it**

Create `src/Tools/GlobalsGet.php`:

```php
<?php

namespace Danielgnh\StatamicMcp\Tools;

use Danielgnh\StatamicMcp\Tools\Concerns\ResolvesSites;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Facades\GlobalSet;

#[Name('globals_get')]
#[Description('Read global variables in the raw round-trippable shape for globals_update. Pass handle for one set, or omit it to get every set you can access (others are silently omitted). With site, returns that site\'s localization.')]
#[IsReadOnly]
class GlobalsGet extends Tool
{
    use ResolvesSites;

    public function schema(JsonSchema $schema): array
    {
        return [
            'handle' => $schema->string()->description('Global set handle, e.g. "settings". Omit to list every readable set.'),
            'site' => $schema->string()->description('Site handle. Defaults to the default site.'),
        ];
    }

    protected function execute(Request $request): Response
    {
        $request->validate([
            'handle' => 'sometimes|string',
            'site' => 'sometimes|string',
        ]);

        $user = $this->user($request);

        if ($handle = $request->get('handle')) {
            return $this->one($request, $user, $handle);
        }

        return $this->all($request, $user);
    }

    private function one(Request $request, UserContract $user, string $handle): Response
    {
        // Missing and exists-but-unexposed are indistinguishable by design;
        // the error lists only exposed handles (spec §4 row 13).
        $this->ensureExposed('globals', $handle);

        // v6 has no 'view {handle} globals' permission — edit is the only
        // per-set permission and the CP gates viewing on it too.
        $this->ensurePermission($user, "edit {$handle} globals");

        $set = GlobalSet::findByHandle($handle);

        $site = $this->resolveSite($request, $user, $set->sites());

        $variables = $set->in($site);

        return $this->json([
            'handle' => $handle,
            'title' => $set->title(),
            'site' => $site,
            'data' => $variables->data()->all(),
            'cp_edit_url' => $variables->editUrl(),
        ]);
    }

    private function all(Request $request, UserContract $user): Response
    {
        // No valid-sites argument: with no specific set in play, any
        // configured site is valid (sets not in it are filtered below).
        $site = $this->resolveSite($request, $user);

        $globals = collect($this->exposedHandles('globals'))
            // Exposed but not editable by this user → silently omitted,
            // exactly like statamic_overview (spec §4 row 13).
            ->filter(fn (string $handle) => $user->isSuper() || $user->hasPermission("edit {$handle} globals"))
            ->map(fn (string $handle) => GlobalSet::findByHandle($handle))
            // Sets not configured for the requested site are silently omitted too.
            ->filter(fn ($set) => $set->sites()->contains($site))
            ->map(function ($set) use ($site) {
                $variables = $set->in($site);

                return [
                    'handle' => $set->handle(),
                    'title' => $set->title(),
                    'data' => $variables->data()->all(),
                    'cp_edit_url' => $variables->editUrl(),
                ];
            })
            ->values()
            ->all();

        return $this->json([
            'site' => $site,
            'globals' => $globals,
        ]);
    }
}
```

Then in `src/Server.php`, ensure `$tools` contains `Tools\GlobalsGet::class` directly after `Tools\TermsDelete::class` (skip if the full contracts list is already there):

```php
        Tools\TermsDelete::class,
        Tools\GlobalsGet::class,
    ];
```

- [ ] **Step 4: Run the globals_get tests and confirm they pass**

```
vendor/bin/pest tests/Feature/GlobalsGetTest.php
```

Expected: `PASS  Tests\Feature\GlobalsGetTest` — `Tests: 5 passed`.

- [ ] **Step 5: Commit globals_get**

```
git add src/Tools/GlobalsGet.php src/Server.php tests/Feature/GlobalsGetTest.php
git commit -m "feat: add globals_get tool" -m "Reads global variables per site; exposure allowlist and edit-permission filtering with silent omission, unexposed handles indistinguishable from missing ones.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

- [ ] **Step 6: Write the failing globals_update test**

Create `tests/Feature/GlobalsUpdateTest.php`:

```php
<?php

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tools\GlobalsUpdate;
use Statamic\Facades\GlobalSet;

it('shallow-merges data into the default site localization', function () {
    Fixtures::site();
    Fixtures::settings();

    $user = Fixtures::makeUser('edit settings globals');

    Server::actingAs($user)
        ->tool(GlobalsUpdate::class, [
            'handle' => 'settings',
            'data' => ['footer_text' => 'Hi'],
        ])
        ->assertOk()
        ->assertSee('"site_name":"Acme"')
        ->assertSee('"footer_text":"Hi"')
        ->assertSee('updated — live');

    expect(GlobalSet::findByHandle('settings')->in('en')->data()->all())
        ->toEqual(['site_name' => 'Acme', 'footer_text' => 'Hi']);
});

it('creates a missing site localization transparently on first write', function () {
    Fixtures::multisite();
    Fixtures::settings();

    GlobalSet::findByHandle('settings')->sites(['en', 'de'])->save();

    $user = Fixtures::makeUser('edit settings globals', 'access de site');

    Server::actingAs($user)
        ->tool(GlobalsUpdate::class, [
            'handle' => 'settings',
            'data' => ['site_name' => 'Acme DE'],
            'site' => 'de',
        ])
        ->assertOk()
        ->assertSee('"site":"de"');

    expect(GlobalSet::findByHandle('settings')->in('de')->data()->all())
        ->toEqual(['site_name' => 'Acme DE']);
});

it('rejects unknown variable keys with a did-you-mean hint', function () {
    Fixtures::site();
    Fixtures::settings();

    $user = Fixtures::makeUser('edit settings globals');

    Server::actingAs($user)
        ->tool(GlobalsUpdate::class, [
            'handle' => 'settings',
            'data' => ['site_nam' => 'X'],
        ])
        ->assertHasErrors(["did you mean 'site_name' instead of 'site_nam'?"]);
});

it('is a no-op when the merged result equals current data', function () {
    Fixtures::site();
    Fixtures::settings();

    $user = Fixtures::makeUser('edit settings globals');

    Server::actingAs($user)
        ->tool(GlobalsUpdate::class, [
            'handle' => 'settings',
            'data' => ['site_name' => 'Acme'],
        ])
        ->assertOk()
        ->assertSee('no-op — merged data equals current data; nothing saved');
});

it('denies updating without the edit permission, naming it', function () {
    Fixtures::site();
    Fixtures::settings();

    $user = Fixtures::makeUser(); // 'access mcp' only

    Server::actingAs($user)
        ->tool(GlobalsUpdate::class, [
            'handle' => 'settings',
            'data' => ['site_name' => 'X'],
        ])
        ->assertHasErrors(["requires 'edit settings globals' — grant it to a role of {$user->email()} in the Control Panel"]);
});

it('is hidden when the server is read-only', function () {
    Fixtures::site();
    Fixtures::settings();

    config(['statamic.mcp.read_only' => true]);

    Server::actingAs(Fixtures::makeSuper())
        ->tool(GlobalsUpdate::class, [
            'handle' => 'settings',
            'data' => ['site_name' => 'X'],
        ])
        ->assertHasErrors(['Tool [globals_update] not found']);
});
```

- [ ] **Step 7: Run the test and confirm the failure**

```
vendor/bin/pest tests/Feature/GlobalsUpdateTest.php
```

Expected: all 6 tests error with `Error: Class "Danielgnh\StatamicMcp\Tools\GlobalsUpdate" not found`.

- [ ] **Step 8: Implement globals_update and register it**

Create `src/Tools/GlobalsUpdate.php`:

```php
<?php

namespace Danielgnh\StatamicMcp\Tools;

use Danielgnh\StatamicMcp\Tools\Concerns\ResolvesSites;
use Danielgnh\StatamicMcp\Tools\Concerns\ValidatesBlueprintData;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Statamic\Facades\GlobalSet;

#[Name('globals_update')]
#[Description('Update a global set\'s variables with a shallow top-level-key merge — nested structures are replaced wholesale; explicit null clears a variable. With site, writes that site\'s localization, creating it transparently on first write. Globals have no draft state: saved values are live immediately. Never send augmented data.')]
#[IsIdempotent]
class GlobalsUpdate extends Tool
{
    use ResolvesSites;
    use ValidatesBlueprintData;

    public function schema(JsonSchema $schema): array
    {
        return [
            'handle' => $schema->string()->description('Global set handle, e.g. "settings".')->required(),
            'data' => $schema->object()->description('Raw variables to merge, keyed by field handle.')->required(),
            'site' => $schema->string()->description('Site handle. Defaults to the default site. A missing localization is created on first write.'),
        ];
    }

    public function shouldRegister(Request $request): bool
    {
        return $this->writesEnabled();
    }

    protected function execute(Request $request): Response
    {
        // Re-check the registration gate: stale client tool caches may still
        // call hidden tools (spec §6 layer 1).
        if (! $this->writesEnabled()) {
            throw new ToolException('this server is read-only (read_only is enabled in config/statamic/mcp.php)');
        }

        $validated = $request->validate(
            [
                'handle' => 'required|string',
                'data' => 'required|array',
                'site' => 'sometimes|string',
            ],
            [
                'handle.required' => 'Pass a global set handle, e.g. "settings".',
                'data.required' => 'Pass raw variables to merge, e.g. {"site_name": "Acme"}.',
            ],
        );

        $handle = $validated['handle'];
        $patch = $validated['data'];

        $this->ensureExposed('globals', $handle);

        $user = $this->user($request);

        $this->ensurePermission($user, "edit {$handle} globals");

        $set = GlobalSet::findByHandle($handle);

        $site = $this->resolveSite($request, $user, $set->sites());

        // v6: in() returns the existing localization, or a fresh unsaved one
        // via $set->makeLocalization($site); saving below persists it — the
        // transparent-creation rule (spec §4 row 14). The ?? branch is
        // belt-and-braces: in() only returns null for sites outside
        // $set->sites(), which resolveSite() already rejected.
        $variables = $set->in($site) ?? $set->makeLocalization($site);

        $existing = $variables->data()->all();
        $merged = array_merge($existing, $patch);

        // Sets without a blueprint accept free-form variables (Statamic's own
        // fallback blueprint is generated from current values, so it must not
        // be used to reject new keys); with one, reject unknown keys and
        // validate the merged result (spec §8).
        if ($blueprint = $set->blueprint()) {
            $this->rejectUnknownKeys($patch, $blueprint);
            $this->validateAgainstBlueprint($blueprint, $merged);
        }

        if ($merged == $existing) {
            return $this->json([
                'handle' => $handle,
                'site' => $site,
                'result' => 'no-op — merged data equals current data; nothing saved',
                'cp_edit_url' => $variables->editUrl(),
            ]);
        }

        $variables->data($merged)->save();

        return $this->json([
            'handle' => $handle,
            'site' => $site,
            'data' => $variables->data()->all(),
            ...$this->liveness($variables, self::LIVENESS_LIVE),
        ]);
    }
}
```

Then in `src/Server.php`, add `Tools\GlobalsUpdate::class` after `Tools\GlobalsGet::class`. The `$tools` array must now match the contracts §4 `Server.php` verbatim:

```php
    protected array $tools = [
        Tools\StatamicOverview::class,
        Tools\BlueprintsGet::class,
        Tools\EntriesList::class,
        Tools\EntriesGet::class,
        Tools\EntriesCreate::class,
        Tools\EntriesUpdate::class,
        Tools\EntriesDelete::class,
        Tools\TermsList::class,
        Tools\TermsGet::class,
        Tools\TermsCreate::class,
        Tools\TermsUpdate::class,
        Tools\TermsDelete::class,
        Tools\GlobalsGet::class,
        Tools\GlobalsUpdate::class,
    ];
```

- [ ] **Step 9: Run the tests and confirm they pass**

```
vendor/bin/pest tests/Feature/GlobalsUpdateTest.php
```

Expected: `PASS  Tests\Feature\GlobalsUpdateTest` — `Tests: 6 passed`.

```
vendor/bin/pest
```

Expected: whole suite green, 0 failures — all 14 tools now exist and `Server::$tools` matches the contracts appendix (docs/superpowers/plans/2026-07-09-statamic-mcp-contracts.md).

- [ ] **Step 10: Format**

```
composer format
```

- [ ] **Step 11: Commit**

```
git add src/Tools/GlobalsUpdate.php src/Server.php tests/Feature/GlobalsUpdateTest.php
git commit -m "feat: add globals_update tool" -m "Shallow-merge global variable updates with transparent per-site localization creation, blueprint-aware unknown-key rejection and merged validation, and no-op detection. Completes the 14-tool surface.

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```



### Task 23: OAuth mode — 503 preflight on the MCP route, token-mode isolation, Eloquent-user normalization

The ServiceProvider (Task 3, contracts §3) already switches the route middleware to `[EnsureOAuthConfigured::class, 'auth:api']` when `'auth' => 'oauth'` and calls `Mcp::oauthRoutes()` only when Passport's class exists. This task ships the preflight middleware itself and proves the three spec §5 behaviors: (1) misconfigured OAuth answers 503-with-remedy **on the MCP route only** and never throws in `bootAddon()`; (2) token mode is completely unaffected by OAuth misconfiguration; (3) `Tool::user()` normalizes an Eloquent auth user (what Passport hands us) to the real Statamic user via `User::fromUser()`.

Note on test env: `laravel/passport` is deliberately NOT in `require-dev`, so `class_exists(\Laravel\Passport\Passport::class)` is `false` — the test environment IS the "OAuth prerequisites missing" state. The preflight's second and third remedy messages (Eloquent users, `api` guard) are asserted verbatim through `mcp:doctor` in Task 24, which checks all prerequisites without short-circuiting.

**Files:**
- Create: `src/Middleware/EnsureOAuthConfigured.php`
- Create: `tests/OAuthTestCase.php`
- Create: `tests/Support/FakeEloquentUser.php`
- Test: `tests/Feature/OAuthMisconfigTest.php`
- Test: `tests/Feature/TokenModeUnaffectedTest.php`
- Test: `tests/Feature/EloquentUserNormalizationTest.php`

- [ ] **Step 1: Write the OAuth-mode test case that boots the app in oauth mode**

  Runtime `config()` calls are too late for these tests: the MCP route middleware stack is baked inside `bootAddon()`. `resolveApplicationConfiguration()` runs before providers boot (Orchestra Testbench), and `mergeConfigFrom()` in `bootAddon()` keeps pre-set values (existing config wins over the file), so this mirrors a real `config/statamic/mcp.php` edit.

  Create `tests/OAuthTestCase.php`:

  ```php
  <?php

  namespace Danielgnh\StatamicMcp\Tests;

  /**
   * Boots the application with 'auth' => 'oauth' BEFORE the addon
   * ServiceProvider runs. Runtime config() is too late: the MCP route
   * middleware stack is decided in bootAddon().
   */
  abstract class OAuthTestCase extends TestCase
  {
      protected function resolveApplicationConfiguration($app)
      {
          parent::resolveApplicationConfiguration($app);

          $app['config']->set('statamic.mcp.auth', 'oauth');
      }
  }
  ```

- [ ] **Step 2: Write the failing misconfiguration test**

  Create `tests/Feature/OAuthMisconfigTest.php`:

  ```php
  <?php

  use Danielgnh\StatamicMcp\Tests\OAuthTestCase;
  use Illuminate\Support\Facades\Route;

  uses(OAuthTestCase::class);

  it('answers 503 on the MCP route naming the missing prerequisite', function () {
      // The app booted in setUp() with 'auth' => 'oauth' and zero OAuth
      // prerequisites installed — reaching this line at all proves
      // bootAddon() did not throw (spec §5: misconfig never bricks the site).
      expect(config('statamic.mcp.auth'))->toBe('oauth');

      $response = $this->postJson('/mcp/statamic', [
          'jsonrpc' => '2.0',
          'id' => 1,
          'method' => 'initialize',
      ]);

      $response
          ->assertStatus(503)
          ->assertJsonPath('error', 'MCP OAuth mode is misconfigured.');

      // The 503 body names the missing prerequisite (Passport is checked first).
      expect($response->json('remedy'))
          ->toContain('laravel/passport')
          ->toContain("'auth' => 'token'");
  });

  it('leaves every other route of the site untouched', function () {
      Route::get('/not-mcp', fn () => 'still alive');

      $this->get('/not-mcp')
          ->assertOk()
          ->assertSee('still alive');
  });
  ```

- [ ] **Step 3: Run the test — expect 500, not 503**

  ```
  vendor/bin/pest tests/Feature/OAuthMisconfigTest.php
  ```

  Expected output: `FAILED ... Expected response status code [503] but received 500.` — the route references `EnsureOAuthConfigured::class`, which the container cannot resolve yet. (If an earlier task already created the middleware verbatim from the contracts file, this passes instead — verify the file is byte-identical to Step 4 and skip to Step 5.)

- [ ] **Step 4: Create the preflight middleware (contracts §3, verbatim)**

  Create `src/Middleware/EnsureOAuthConfigured.php`:

  ```php
  <?php

  namespace Danielgnh\StatamicMcp\Middleware;

  use Closure;
  use Illuminate\Http\Request;
  use Symfony\Component\HttpFoundation\Response;

  class EnsureOAuthConfigured
  {
      public function handle(Request $request, Closure $next): Response
      {
          if (! class_exists(\Laravel\Passport\Passport::class)) {
              return $this->unavailable(
                  "OAuth mode requires Laravel Passport. Run 'composer require laravel/passport' and follow the OAuth setup in the statamic-mcp README, or switch to token mode ('auth' => 'token')."
              );
          }

          if (config('statamic.users.repository') === 'file') {
              return $this->unavailable(
                  "OAuth mode requires database (Eloquent) users — a Passport constraint, not ours. Run 'php please auth:migration' then 'php please eloquent:import-users', or switch to token mode ('auth' => 'token')."
              );
          }

          if (! config('auth.guards.api')) {
              return $this->unavailable(
                  "OAuth mode requires an 'api' guard. In config/auth.php add 'api' => ['driver' => 'passport', 'provider' => 'users'] under 'guards'."
              );
          }

          return $next($request);
      }

      protected function unavailable(string $remedy): Response
      {
          return response()->json([
              'error' => 'MCP OAuth mode is misconfigured.',
              'remedy' => $remedy,
          ], 503);
      }
  }
  ```

- [ ] **Step 5: Run the misconfiguration tests — expect pass**

  ```
  vendor/bin/pest tests/Feature/OAuthMisconfigTest.php
  ```

  Expected output: `PASS  Tests\Feature\OAuthMisconfigTest` ... `Tests: 2 passed`.

- [ ] **Step 6: Write the token-mode isolation test**

  This locks in "token mode unaffected by oauth misconfig": the default test environment has no Passport, file-based users, and no `api` guard — and token auth must work end-to-end anyway.

  Create `tests/Feature/TokenModeUnaffectedTest.php`:

  ```php
  <?php

  use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
  use Danielgnh\StatamicMcp\Tokens\TokenRepository;

  it('serves token-mode requests while every oauth prerequisite is missing', function () {
      // This environment IS the oauth-broken state:
      expect(class_exists(\Laravel\Passport\Passport::class))->toBeFalse()
          ->and(config('statamic.users.repository'))->toBe('file')
          ->and(config('auth.guards.api'))->toBeNull();

      $user = Fixtures::makeUser();
      $token = app(TokenRepository::class)->issue($user, 'isolation-test')->token;

      $this->withHeaders([
          'Authorization' => 'Bearer '.$token,
          'Accept' => 'application/json, text/event-stream',
      ])->postJson('/mcp/statamic', [
          'jsonrpc' => '2.0',
          'id' => 1,
          'method' => 'initialize',
          'params' => [
              'protocolVersion' => '2025-11-25',
              'capabilities' => (object) [],
              'clientInfo' => ['name' => 'pest', 'version' => '1.0.0'],
          ],
      ])
          ->assertOk()
          ->assertSee('Statamic');
  });
  ```

- [ ] **Step 7: Write the Eloquent-user normalization tests**

  Under Passport, `$request->user()` is an Eloquent model, not a Statamic user. `Tool::user()` (contracts §5) normalizes via `User::fromUser()`, which resolves any `Authenticatable` by its auth identifier. We simulate the Passport case with a bare Eloquent model carrying a real Statamic user's id — no Passport needed.

  Create `tests/Support/FakeEloquentUser.php`:

  ```php
  <?php

  namespace Danielgnh\StatamicMcp\Tests\Support;

  use Illuminate\Foundation\Auth\User as AuthUser;

  /**
   * Simulates what Passport hands laravel/mcp in oauth mode: an Eloquent
   * Authenticatable, NOT a Statamic user. Never persisted or queried.
   */
  class FakeEloquentUser extends AuthUser
  {
      protected $guarded = [];

      public $incrementing = false;

      protected $keyType = 'string';
  }
  ```

  Create `tests/Feature/EloquentUserNormalizationTest.php`:

  ```php
  <?php

  use Danielgnh\StatamicMcp\Server;
  use Danielgnh\StatamicMcp\Tests\Support\FakeEloquentUser;
  use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
  use Danielgnh\StatamicMcp\Tools\EntriesList;
  use Statamic\Facades\Entry;

  it('normalizes an Eloquent auth user to the Statamic user inside tools', function () {
      Fixtures::site();
      Fixtures::tags();
      Fixtures::blog();

      Entry::make()
          ->collection('blog')
          ->slug('hello-world')
          ->data(['title' => 'Hello World'])
          ->published(true)
          ->save();

      $statamicUser = Fixtures::makeUser('view blog entries');

      $eloquent = new FakeEloquentUser(['id' => $statamicUser->id()]);

      Server::actingAs($eloquent)
          ->tool(EntriesList::class, ['collection' => 'blog'])
          ->assertOk()
          ->assertSee('hello-world');
  });

  it('enforces the normalized Statamic user\'s real permissions', function () {
      Fixtures::site();
      Fixtures::tags();
      Fixtures::blog();

      $statamicUser = Fixtures::makeUser(); // 'access mcp' only — no view permission

      $eloquent = new FakeEloquentUser(['id' => $statamicUser->id()]);

      Server::actingAs($eloquent)
          ->tool(EntriesList::class, ['collection' => 'blog'])
          ->assertHasErrors(["requires 'view blog entries' — grant it to a role of {$statamicUser->email()} in the Control Panel"]);
  });
  ```

- [ ] **Step 8: Run the new tests — expect pass**

  Both files exercise plumbing built in earlier tasks (token middleware, `Tool::user()`), so they pass immediately and lock the behavior in:

  ```
  vendor/bin/pest tests/Feature/TokenModeUnaffectedTest.php tests/Feature/EloquentUserNormalizationTest.php
  ```

  Expected output: `Tests: 3 passed`. If the normalization tests fail with a null-user error, the base `Tool::user()` is not using `User::fromUser($request->user())` — fix the base Tool to match contracts §5 verbatim, not these tests.

- [ ] **Step 9: Run the full suite, format, commit**

  ```
  vendor/bin/pest
  ```

  Expected output: all tests pass, zero failures.

  ```
  composer format
  git add src/Middleware/EnsureOAuthConfigured.php tests/
  git commit -m "feat: oauth mode preflight answers 503 with remedy on the MCP route only

  Token mode is untouched by oauth misconfiguration, bootAddon never throws,
  and Tool::user() normalizes Eloquent auth users via User::fromUser().

  Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
  ```

### Task 24: `php please mcp:doctor` — configuration health check

One command that answers "why doesn't my MCP endpoint work?". This task fully replaces the minimal Doctor from Task 7 with the complete version: prints endpoint + auth mode, warns on `enabled=false`, checks token-store writability and the zero-token "locked door" in token mode, and in oauth mode checks ALL prerequisites without short-circuiting — Passport installed, Eloquent users, the `HasApiTokens` trait on the user model, and the `api` guard (Laravel 12/13 ship none; `auth:api` throws without it). Warnings exit 0; failures exit 1. The command class is already listed in the ServiceProvider's `$commands` (contracts §3).

**Files:**
- Modify: `src/Console/Doctor.php` (fully replaces the minimal Doctor from Task 7)
- Test: `tests/Console/DoctorTest.php`

- [ ] **Step 1: Write the failing tests**

  Create `tests/Console/DoctorTest.php`:

  ```php
  <?php

  use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
  use Danielgnh\StatamicMcp\Tokens\TokenRepository;
  use Illuminate\Support\Facades\File;

  it('reports a healthy token-mode setup with exit code 0', function () {
      $user = Fixtures::makeUser();
      app(TokenRepository::class)->issue($user, 'doctor-test');

      $this->artisan('statamic:mcp:doctor')
          ->expectsOutputToContain('Endpoint:  http://localhost/mcp/statamic')
          ->expectsOutputToContain('Auth mode: token')
          ->expectsOutputToContain('[ OK ] MCP is enabled.')
          ->expectsOutputToContain('[ OK ] Token store is writable')
          ->expectsOutputToContain('token(s) issued.')
          ->assertExitCode(0);
  });

  it('warns about the locked door when zero tokens exist', function () {
      // Other tests in the run may have issued tokens into the shared
      // Testbench storage path — make the zero-token state deterministic.
      File::delete(storage_path('statamic/mcp/tokens.yaml'));

      $this->artisan('statamic:mcp:doctor')
          ->expectsOutputToContain('[WARN] No tokens issued — the endpoint is a locked door. Run: php please mcp:token you@site.com')
          ->assertExitCode(0);
  });

  it('warns when the server is disabled', function () {
      config(['statamic.mcp.enabled' => false]);

      $this->artisan('statamic:mcp:doctor')
          ->expectsOutputToContain('[WARN] MCP is disabled')
          ->assertExitCode(0);
  });

  it('fails oauth mode naming every missing prerequisite', function () {
      config(['statamic.mcp.auth' => 'oauth']);

      // No short-circuiting: all three prerequisites are reported at once.
      $this->artisan('statamic:mcp:doctor')
          ->expectsOutputToContain('Auth mode: oauth')
          ->expectsOutputToContain('Laravel Passport is not installed')
          ->expectsOutputToContain('Users are file-based')
          ->expectsOutputToContain("No 'api' guard is defined — Laravel 12 and 13 ship none")
          ->assertExitCode(1);
  });

  it('names the exact api guard config to add', function () {
      config(['statamic.mcp.auth' => 'oauth']);

      $this->artisan('statamic:mcp:doctor')
          ->expectsOutputToContain("'api' => ['driver' => 'passport', 'provider' => 'users']")
          ->assertExitCode(1);
  });
  ```

- [ ] **Step 2: Run the tests — expect failure**

  ```
  vendor/bin/pest tests/Console/DoctorTest.php
  ```

  Expected output: the two oauth-mode tests fail (e.g. `Output "Laravel Passport is not installed" was not printed.`) — the minimal Doctor from Task 7 has no oauth branch. The three token-mode tests already pass, because the minimal Doctor prints the exact same output lines as the full version. Output ends with `Tests: 2 failed, 3 passed`.

- [ ] **Step 3: Implement the command**

  Fully replace the minimal Doctor from Task 7 with the complete implementation in `src/Console/Doctor.php`. `RunsInPlease` + the `statamic:` signature prefix expose it as `php please mcp:doctor` — the same pattern as the token commands from the token task:

  ```php
  <?php

  namespace Danielgnh\StatamicMcp\Console;

  use Danielgnh\StatamicMcp\Tokens\TokenRepository;
  use Illuminate\Console\Command;
  use Statamic\Console\RunsInPlease;

  class Doctor extends Command
  {
      use RunsInPlease;

      protected $signature = 'statamic:mcp:doctor';

      protected $description = 'Check the MCP server configuration and print remedies for every problem found.';

      protected bool $failed = false;

      public function handle(TokenRepository $tokens): int
      {
          $mode = config('statamic.mcp.auth', 'token');

          $this->line('Statamic MCP doctor');
          $this->line('');
          $this->line('  Endpoint:  '.url(config('statamic.mcp.route', 'mcp/statamic')));
          $this->line('  Auth mode: '.$mode);
          $this->line('');

          if (config('statamic.mcp.enabled')) {
              $this->info('[ OK ] MCP is enabled.');
          } else {
              $this->warn("[WARN] MCP is disabled ('enabled' => false) — the endpoint is not registered. Set STATAMIC_MCP_ENABLED=true to serve requests.");
          }

          if ($mode === 'oauth') {
              $this->checkOAuth();
          } else {
              $this->checkTokens($tokens);
          }

          $this->line('');

          if ($this->failed) {
              $this->error('Problems found. Fix the [FAIL] items above.');

              return self::FAILURE;
          }

          $this->info('No blocking problems found.');

          return self::SUCCESS;
      }

      protected function checkTokens(TokenRepository $tokens): void
      {
          $dir = storage_path('statamic/mcp');

          // The directory may not exist before the first token is issued —
          // probe the closest existing ancestor for writability.
          $probe = $dir;

          while (! is_dir($probe)) {
              $probe = dirname($probe);
          }

          if (is_writable($probe)) {
              $this->info('[ OK ] Token store is writable ('.$dir.').');
          } else {
              $this->problem('Token store is not writable — fix permissions on '.$probe.' so tokens can be saved to '.$dir.'/tokens.yaml.');
          }

          $count = count($tokens->all());

          if ($count === 0) {
              $this->warn('[WARN] No tokens issued — the endpoint is a locked door. Run: php please mcp:token you@site.com');
          } else {
              $this->info('[ OK ] '.$count.' token(s) issued.');
          }
      }

      protected function checkOAuth(): void
      {
          if (class_exists(\Laravel\Passport\Passport::class)) {
              $this->info('[ OK ] Laravel Passport is installed.');
          } else {
              $this->problem("Laravel Passport is not installed — OAuth mode requires it. Run 'composer require laravel/passport', or switch to token mode ('auth' => 'token').");
          }

          if (config('statamic.users.repository') === 'file') {
              $this->problem("Users are file-based — OAuth mode requires database (Eloquent) users, a Passport constraint, not ours. Run 'php please auth:migration' then 'php please eloquent:import-users'.");
          } else {
              $this->info('[ OK ] Users are database-backed (repository: '.config('statamic.users.repository').').');

              $model = config('auth.providers.users.model');

              if ($model && class_exists($model) && in_array('Laravel\\Passport\\HasApiTokens', class_uses_recursive($model), true)) {
                  $this->info('[ OK ] User model '.$model.' uses the HasApiTokens trait.');
              } else {
                  $this->problem('User model '.($model ?: '(none configured in auth.providers.users.model)').' is missing the Laravel\\Passport\\HasApiTokens trait — add it per step 2 of the README OAuth guide.');
              }
          }

          if (config('auth.guards.api')) {
              $this->info("[ OK ] The 'api' guard is defined.");
          } else {
              $this->problem("No 'api' guard is defined — Laravel 12 and 13 ship none, and 'auth:api' throws without it. In config/auth.php add: 'api' => ['driver' => 'passport', 'provider' => 'users'] under 'guards'.");
          }
      }

      protected function problem(string $message): void
      {
          $this->failed = true;

          $this->error('[FAIL] '.$message);
      }
  }
  ```

- [ ] **Step 4: Run the tests — expect pass**

  ```
  vendor/bin/pest tests/Console/DoctorTest.php
  ```

  Expected output: `PASS  Tests\Console\DoctorTest` ... `Tests: 5 passed`.

- [ ] **Step 5: Run the full suite, format, commit**

  ```
  vendor/bin/pest
  ```

  Expected output: all tests pass.

  ```
  composer format
  git add src/Console/Doctor.php tests/Console/DoctorTest.php
  git commit -m "feat: mcp:doctor health check with remedies for every failure

  Covers endpoint/auth-mode summary, enabled=false and zero-token warnings,
  token-store writability, and all oauth prerequisites including the missing
  'api' guard gotcha on Laravel 12/13.

  Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
  ```

### Task 25: `read_only` end-to-end — tools/list hides every write tool AND handlers re-check

Spec §6 layer 1 has two halves and both must be asserted: with `'read_only' => true`, (a) `tools/list` over real HTTP exposes ONLY the seven read tools (every write/delete tool hidden via `shouldRegister()`), and (b) a write handler invoked anyway (a stale client tool cache) refuses in-handler and changes nothing. `shouldRegister()` and the in-handler gates were implemented with the tools themselves; this task is the end-to-end lock across the whole surface. Runtime `config()` works here because laravel/mcp evaluates `shouldRegister()` per request, not at boot.

**Files:**
- Test: `tests/Feature/ReadOnlyModeTest.php`

- [ ] **Step 1: Write the end-to-end tests**

  Create `tests/Feature/ReadOnlyModeTest.php`:

  ```php
  <?php

  use Danielgnh\StatamicMcp\Server;
  use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
  use Danielgnh\StatamicMcp\Tokens\TokenRepository;
  use Danielgnh\StatamicMcp\Tools\EntriesDelete;
  use Danielgnh\StatamicMcp\Tools\EntriesUpdate;
  use Danielgnh\StatamicMcp\Tools\GlobalsUpdate;
  use Illuminate\Testing\TestResponse;
  use Statamic\Facades\Entry;
  use Statamic\Facades\GlobalSet;

  function readOnlyHeaders(string $token, ?string $sessionId = null): array
  {
      return array_filter([
          'Authorization' => 'Bearer '.$token,
          'Accept' => 'application/json, text/event-stream',
          'Mcp-Session-Id' => $sessionId,
      ]);
  }

  function readOnlyPost(array $payload, string $token, ?string $sessionId = null): TestResponse
  {
      return test()
          ->withHeaders(readOnlyHeaders($token, $sessionId))
          ->postJson('/mcp/statamic', $payload);
  }

  function readOnlyInitialize(string $token): ?string
  {
      $response = readOnlyPost([
          'jsonrpc' => '2.0',
          'id' => 1,
          'method' => 'initialize',
          'params' => [
              'protocolVersion' => '2025-11-25',
              'capabilities' => (object) [],
              'clientInfo' => ['name' => 'pest', 'version' => '1.0.0'],
          ],
      ], $token);

      $response->assertOk();

      // laravel/mcp's web transport is stateless, but pass the session id
      // along if the server issued one — protocol-correct either way.
      return $response->headers->get('Mcp-Session-Id');
  }

  function readOnlyToolNames(string $token): array
  {
      $sessionId = readOnlyInitialize($token);

      $response = readOnlyPost([
          'jsonrpc' => '2.0',
          'id' => 2,
          'method' => 'tools/list',
      ], $token, $sessionId);

      $response->assertOk();

      return collect($response->json('result.tools'))->pluck('name')->sort()->values()->all();
  }

  it('hides every write and delete tool from tools/list in read_only mode', function () {
      config(['statamic.mcp.read_only' => true]);

      $user = Fixtures::makeUser();
      $token = app(TokenRepository::class)->issue($user, 'ro')->token;

      // Exact set equality: ONLY the seven read tools remain.
      expect(readOnlyToolNames($token))->toBe([
          'blueprints_get',
          'entries_get',
          'entries_list',
          'globals_get',
          'statamic_overview',
          'terms_get',
          'terms_list',
      ]);
  });

  it('lists write tools but never delete tools with the default config', function () {
      $user = Fixtures::makeUser();
      $token = app(TokenRepository::class)->issue($user, 'rw')->token;

      $names = readOnlyToolNames($token);

      expect($names)->toContain('entries_create', 'entries_update', 'terms_create', 'terms_update', 'globals_update');
      expect($names)->not->toContain('entries_delete', 'terms_delete');
  });

  it('re-checks read_only inside entries_update and refuses the write', function () {
      config(['statamic.mcp.read_only' => true]);

      Fixtures::site();
      Fixtures::tags();
      Fixtures::blog();

      $entry = tap(
          Entry::make()->collection('blog')->slug('hello-world')->data(['title' => 'Hello World'])->published(true)
      )->save();

      // A super user: the refusal below can only come from the read_only gate,
      // never from a permission denial.
      $super = Fixtures::makeSuper();

      Server::actingAs($super)
          ->tool(EntriesUpdate::class, ['id' => $entry->id(), 'data' => ['title' => 'Hacked']])
          ->assertHasErrors();

      expect(Entry::find($entry->id())->get('title'))->toBe('Hello World');
  });

  it('re-checks read_only inside globals_update and refuses the write', function () {
      config(['statamic.mcp.read_only' => true]);

      Fixtures::site();
      Fixtures::settings();

      $super = Fixtures::makeSuper();

      Server::actingAs($super)
          ->tool(GlobalsUpdate::class, ['handle' => 'settings', 'data' => ['site_name' => 'Hacked']])
          ->assertHasErrors();

      expect(GlobalSet::findByHandle('settings')->in('en')->get('site_name'))->toBe('Acme');
  });

  it('re-checks the deletes gate inside entries_delete and refuses', function () {
      // Default config: 'deletes' => false.
      Fixtures::site();
      Fixtures::tags();
      Fixtures::blog();

      $entry = tap(
          Entry::make()->collection('blog')->slug('hello-world')->data(['title' => 'Hello World'])->published(true)
      )->save();

      $super = Fixtures::makeSuper();

      Server::actingAs($super)
          ->tool(EntriesDelete::class, ['id' => $entry->id()])
          ->assertHasErrors();

      expect(Entry::find($entry->id()))->not->toBeNull();
  });

  it('refuses a stale-cached write tool call over HTTP in read_only mode', function () {
      config(['statamic.mcp.read_only' => true]);

      Fixtures::site();
      Fixtures::tags();
      Fixtures::blog();

      $entry = tap(
          Entry::make()->collection('blog')->slug('hello-world')->data(['title' => 'Hello World'])->published(true)
      )->save();

      $super = Fixtures::makeSuper();
      $token = app(TokenRepository::class)->issue($super, 'stale-cache')->token;

      $sessionId = readOnlyInitialize($token);

      // Simulates a client whose cached tool list still contains entries_update.
      $call = readOnlyPost([
          'jsonrpc' => '2.0',
          'id' => 3,
          'method' => 'tools/call',
          'params' => [
              'name' => 'entries_update',
              'arguments' => ['id' => $entry->id(), 'data' => ['title' => 'Hacked']],
          ],
      ], $token, $sessionId);

      $call->assertOk(); // JSON-RPC errors still ride on HTTP 200

      // Whether laravel/mcp rejects the unregistered tool at dispatch (JSON-RPC
      // 'error') or the handler's own re-check fires (tool result isError),
      // the refusal must happen and the write must not.
      $refused = $call->json('error') !== null
          || data_get($call->json(), 'result.isError') === true;

      expect($refused)->toBeTrue();
      expect(Entry::find($entry->id())->get('title'))->toBe('Hello World');
  });
  ```

- [ ] **Step 2: Run the tests — expect pass**

  These assert behavior implemented with the tools themselves (`shouldRegister()` + in-handler gates from the entries/terms/globals tasks), so they pass immediately:

  ```
  vendor/bin/pest tests/Feature/ReadOnlyModeTest.php
  ```

  Expected output: `PASS  Tests\Feature\ReadOnlyModeTest` ... `Tests: 6 passed`. If the first test fails with extra tool names in the list, a write tool is missing its `shouldRegister()` gate — fix that tool (`return $this->writesEnabled();` / `return $this->deletesEnabled();` per contracts §9), not this test. If a "refuses" test fails with changed data, the tool is missing its in-handler re-check — fix the tool's `execute()`.

- [ ] **Step 3: Run the full suite, format, commit**

  ```
  vendor/bin/pest
  ```

  Expected output: all tests pass.

  ```
  composer format
  git add tests/Feature/ReadOnlyModeTest.php
  git commit -m "test: read_only hides all write tools from tools/list and re-checks in-handler

  Asserts exact read-only tool set over HTTP, the deletes gate, and that
  stale-cached tools/call writes are refused with data unchanged.

  Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
  ```

### Task 26: README — quickstart, honest client matrix, OAuth guide, security model, permission cookbook

The README is the product page (Packagist and the Statamic Marketplace both render it) and the only OAuth documentation we ship — spec §5 says we ship zero OAuth code, only this documented path. It must state client limitations honestly (spec §2) and teach the permission model ("the token IS the user").

**Files:**
- Create: `README.md`

- [ ] **Step 1: Write the complete README**

  Create `README.md` with exactly this content:

  ````markdown
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
  | `statamic_overview` | Sites, exposed collections/taxonomies/global sets, your permission flags per resource, server flags. Call this first. |
  | `blueprints_get` | Fields (handle, type, rules, required, options) + a valid example payload for a blueprint. |
  | `entries_list` / `entries_get` | Paginated summaries; full raw or augmented reads with localization annotations. |
  | `entries_create` / `entries_update` | Raw-data writes through Statamic's own validation. Drafts by default. |
  | `entries_delete` | Only registered when `deletes` is enabled. |
  | `terms_list` / `terms_get` / `terms_create` / `terms_update` / `terms_delete` | Same contracts for taxonomy terms. |
  | `globals_get` / `globals_update` | Global set variables per site, merge-patch updates. |

  Every write response states the resulting liveness ("saved as draft — not live",
  "published", "working copy created — live entry unchanged") and links the CP edit page.
  Collections with revisions enabled get working copies through the same mechanism the
  CP uses — the live entry is never mutated, publishing stays in the Control Panel.

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
  > user installs must migrate first (step 1).

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
  `api` guard**, and `auth:api` throws without one. In `config/auth.php`:

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

  Publish laravel/mcp's OAuth consent view: `php artisan vendor:publish` → select the
  **Laravel MCP** views.

  Now `php please mcp:doctor` should be all green, and connector clients can add
  `https://your-site.com/mcp/statamic` with no manual credentials — they discover the
  OAuth server, register themselves, and send your users through a normal Statamic
  login + consent screen. The resulting OAuth token maps to that real Statamic user,
  so permission enforcement is identical to token mode.

  If any prerequisite is missing, the MCP endpoint answers **503 with the exact remedy**
  — the rest of your site is untouched, and token mode keeps working if you switch back.

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
     (and term/global equivalents) via the user's roles. Publishing additionally requires
     `publish {handle} entries`, exactly like the CP. Multi-site writes require
     `access {site} site`. Denials name the missing permission and the remedy.
  4. **Deletes off by default** — delete tools aren't registered unless you opt in.

  Creates and updates save **drafts by default**: agents draft, humans publish (unless
  you explicitly pass `published: true` and the user holds the publish permission).

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
  `published: true` now succeed.

  **A cleanup agent that may delete:** set `'deletes' => true` in the config **and**
  add `Delete blog entries` to the role. Both gates must open.

  **Scoping to one site of a multi-site install:** grant `Access {site} site` for only
  that site — writes to other sites are denied with the exact missing permission named.

  ## Troubleshooting

  ```bash
  php please mcp:doctor
  ```

  It prints the endpoint and auth mode, then checks: kill switch, token-store
  writability, whether any token exists ("locked door"), and in OAuth mode: Passport
  installed, Eloquent users, `HasApiTokens` on the user model, and the `api` guard —
  each failure with its exact remedy. You can also point the MCP Inspector at your
  endpoint: `php artisan mcp:inspector` (from laravel/mcp).

  ## Testing

  ```bash
  composer test     # Pest
  composer format   # Pint
  ```

  ## License

  MIT — see [LICENSE.md](LICENSE.md).
  ````

- [ ] **Step 2: Verify the README matches the code**

  ```
  grep -n "'route' => 'mcp/statamic'" config/mcp.php
  grep -rn "statamic:mcp:doctor\|statamic:mcp:token" src/Console/ | head -5
  grep -n "statamic-mcp-config" src/ServiceProvider.php
  ```

  Expected: the route default, the four command signatures (`statamic:mcp:token`, `statamic:mcp:tokens`, `statamic:mcp:token:revoke`, `statamic:mcp:doctor`), and the `statamic-mcp-config` publish tag all exist exactly as the README claims. If any grep comes back empty, fix the README to match the code — never the reverse.

- [ ] **Step 3: Format and commit**

  ```
  composer format
  git add README.md
  git commit -m "docs: README with quickstart, client matrix, oauth guide, and permission cookbook

  Honest client-compatibility matrix per spec §2, 4-step OAuth setup including
  the Laravel 12/13 missing 'api' guard gotcha, config reference, and the
  'token IS the user' security model.

  Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
  ```

### Task 27: CI — GitHub Actions matrix, Pint, PHPStan with Larastan

Spec §9: PHP 8.3/8.4 × Laravel 12/13 × prefer-lowest/prefer-stable, plus `pint --test` and PHPStan. Larastan supplies the Laravel/Statamic-aware PHPStan extensions. Everything must pass locally before the workflow lands.

**Files:**
- Modify: `composer.json` (add `larastan/larastan` to require-dev)
- Create: `phpstan.neon`
- Create: `.github/workflows/tests.yml`

- [ ] **Step 1: Add Larastan**

  ```
  composer require --dev "larastan/larastan:^3.0"
  ```

  Expected output: `Using version ^3.0 for larastan/larastan` and a successful install. The `require-dev` block in `composer.json` now reads:

  ```json
  "require-dev": {
      "larastan/larastan": "^3.0",
      "laravel/pint": "^1.13",
      "orchestra/testbench": "^10.0 || ^11.0",
      "pestphp/pest": "^4.0",
      "phpstan/phpstan": "^2.0"
  },
  ```

  (Larastan 3.x requires phpstan ^2.0 — the existing constraint is compatible.)

- [ ] **Step 2: Create the PHPStan configuration**

  Create `phpstan.neon`:

  ```neon
  includes:
      - vendor/larastan/larastan/extension.neon

  parameters:
      paths:
          - src
      level: 5
  ```

- [ ] **Step 3: Run PHPStan locally — expect a clean pass**

  ```
  vendor/bin/phpstan analyse --no-progress
  ```

  Expected output: `[OK] No errors`. If findings appear, fix the flagged code (typically a missing return type or an impossible-null access) until the run is clean — do not raise `ignoreErrors`.

- [ ] **Step 4: Create the workflow**

  Create `.github/workflows/tests.yml`:

  ```yaml
  name: Tests

  on:
    push:
      branches: [main]
    pull_request:

  jobs:
    tests:
      runs-on: ubuntu-latest
      strategy:
        fail-fast: false
        matrix:
          php: ['8.3', '8.4']
          laravel: ['12.*', '13.*']
          stability: [prefer-lowest, prefer-stable]

      name: PHP ${{ matrix.php }} - Laravel ${{ matrix.laravel }} - ${{ matrix.stability }}

      steps:
        - name: Checkout code
          uses: actions/checkout@v4

        - name: Setup PHP
          uses: shivammathur/setup-php@v2
          with:
            php-version: ${{ matrix.php }}
            extensions: dom, curl, libxml, mbstring, zip, intl, iconv
            coverage: none

        - name: Install dependencies
          run: |
            composer require "laravel/framework:${{ matrix.laravel }}" --no-interaction --no-update
            composer update --${{ matrix.stability }} --prefer-dist --no-interaction

        - name: Run tests
          run: vendor/bin/pest --ci

    pint:
      runs-on: ubuntu-latest
      name: Pint (code style)

      steps:
        - name: Checkout code
          uses: actions/checkout@v4

        - name: Setup PHP
          uses: shivammathur/setup-php@v2
          with:
            php-version: '8.3'
            coverage: none

        - name: Install dependencies
          run: composer install --no-interaction --prefer-dist

        - name: Check code style
          run: vendor/bin/pint --test

    phpstan:
      runs-on: ubuntu-latest
      name: PHPStan (static analysis)

      steps:
        - name: Checkout code
          uses: actions/checkout@v4

        - name: Setup PHP
          uses: shivammathur/setup-php@v2
          with:
            php-version: '8.3'
            coverage: none

        - name: Install dependencies
          run: composer install --no-interaction --prefer-dist

        - name: Run static analysis
          run: vendor/bin/phpstan analyse --no-progress
  ```

  How the matrix pins Laravel: `composer require "laravel/framework:12.*" --no-update` constrains the resolver, then `composer update` picks the matching `orchestra/testbench` (^10 for Laravel 12, ^11 for Laravel 13) automatically. With `statamic/cms ^6.0` requiring `^12.40 || ^13.0`, prefer-lowest lands on 12.40 / 13.0 and `laravel/mcp 0.8.0` — exactly the floor we claim to support.

- [ ] **Step 5: Run the three CI gates locally**

  ```
  vendor/bin/pest --ci
  vendor/bin/pint --test
  vendor/bin/phpstan analyse --no-progress
  ```

  Expected output: all tests pass, `PASS ... files` from Pint (no style violations), `[OK] No errors` from PHPStan.

- [ ] **Step 6: Format and commit**

  ```
  composer format
  git add composer.json composer.lock phpstan.neon .github/workflows/tests.yml
  git commit -m "chore: CI matrix (PHP 8.3/8.4 x Laravel 12/13 x lowest/stable) with pint and larastan

  Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
  ```

  After the first push, open the Actions tab and confirm all 8 matrix jobs plus the pint and phpstan jobs are green before proceeding to release.

### Task 28: Release v1.0.0 — changelog, license, tag, Packagist, Statamic Marketplace

**Files:**
- Create: `CHANGELOG.md`
- Create: `LICENSE.md`

- [ ] **Step 1: Write the changelog**

  Create `CHANGELOG.md` (adjust the date to the actual release day):

  ```markdown
  # Changelog

  All notable changes to `danielgnh/statamic-mcp` are documented here. The format
  follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project
  adheres to [Semantic Versioning](https://semver.org).

  ## [1.0.0] - 2026-07-09

  ### Added

  - Remote (streamable-HTTP) MCP server for Statamic v6, built on `laravel/mcp`,
    mounted at `mcp/statamic` (configurable).
  - 14 tools: `statamic_overview`, `blueprints_get`, `entries_list/get/create/update/delete`,
    `terms_list/get/create/update/delete`, `globals_get/update`.
  - Token auth mode (default): `mcp_{tokenId}_{secret}` tokens hashed into
    `storage/statamic/mcp/tokens.yaml` — works on file-based and Eloquent user installs.
    Commands: `mcp:token`, `mcp:tokens`, `mcp:token:revoke`.
  - OAuth auth mode (opt-in): delegates entirely to `laravel/mcp` + Laravel Passport;
    misconfiguration answers 503 with the exact remedy on the MCP route only.
  - `php please mcp:doctor` configuration health check with remedies.
  - Authorization via Statamic's native permission system on every call — one addon
    permission (`access mcp`), drafts by default, publish permission-gated, revision
    working copies for revision-enabled collections.
  - Config: kill switch, route, auth mode, extra middleware, `read_only`, `deletes`
    (off by default), per-type resource exposure allowlists, `per_page`.
  ```

- [ ] **Step 2: Write the license**

  Create `LICENSE.md`:

  ```markdown
  MIT License

  Copyright (c) 2026 Daniel Goncharov

  Permission is hereby granted, free of charge, to any person obtaining a copy
  of this software and associated documentation files (the "Software"), to deal
  in the Software without restriction, including without limitation the rights
  to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
  copies of the Software, and to permit persons to whom the Software is
  furnished to do so, subject to the following conditions:

  The above copyright notice and this permission notice shall be included in all
  copies or substantial portions of the Software.

  THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
  IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
  FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
  AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
  LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
  OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
  SOFTWARE.
  ```

- [ ] **Step 3: Final verification — all three gates green**

  ```
  vendor/bin/pest
  vendor/bin/pint --test
  vendor/bin/phpstan analyse --no-progress
  ```

  Expected output: every test passes, no style violations, `[OK] No errors`. Do not tag until all three are green.

- [ ] **Step 4: Smoke-test the endpoint with the MCP Inspector — before tagging**

  On a local Statamic install with the addon installed and a token issued (`php please mcp:token you@site.com`), point laravel/mcp's Inspector at the endpoint:

  ```
  php artisan mcp:inspector
  ```

  Expected: the Inspector connects to `http://localhost/mcp/statamic` (add the `Authorization: Bearer <token>` header), `initialize` completes with serverInfo name `Statamic`, `tools/list` returns 14 tools (fewer if gating applies: the two delete tools are hidden under the default `'deletes' => false`, and only the seven read tools appear under `read_only`), and one `statamic_overview` call returns sites, resources, capability flags, and server flags. Do not tag v1.0.0 until this passes.

- [ ] **Step 5: Commit and tag**

  ```
  composer format
  git add CHANGELOG.md LICENSE.md
  git commit -m "chore: prepare v1.0.0 release

  Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
  ```

  Create the GitHub repo if it does not exist yet, then push and tag:

  ```
  gh repo create danielgnh/statamic-mcp --public --source=. --push
  git tag v1.0.0
  git push origin main --tags
  ```

  Expected: the Actions run for the tag is green (all 10 jobs). Then create the GitHub release:

  ```
  gh release create v1.0.0 --title "v1.0.0" --notes "First stable release. See CHANGELOG.md for the full feature list."
  ```

- [ ] **Step 6: Publish on Packagist**

  1. Log in at https://packagist.org (GitHub login as `danielgnh`).
  2. Go to https://packagist.org/packages/submit, enter `https://github.com/danielgnh/statamic-mcp`, click **Check** → **Submit**.
  3. Confirm the package page shows `v1.0.0` and the `statamic/cms ^6.0`, `laravel/mcp ^0.8` requirements.
  4. Enable auto-updates: Packagist → the package page shows a warning if the GitHub hook is missing — follow its link, or on GitHub go to Settings → Webhooks and confirm the Packagist hook Packagist installed via the GitHub App. Push a README typo fix later to verify the hook fires.
  5. Smoke-test the install path on any Statamic 6 site: `composer require danielgnh/statamic-mcp` → `php please mcp:doctor` prints the endpoint.

- [ ] **Step 7: List on the Statamic Marketplace**

  1. Log in at https://statamic.com and open the **Seller dashboard** (create the seller account `danielgnh` first if none exists: statamic.com → Sell → create seller profile).
  2. **New product** → type **Addon** → Packagist package: `danielgnh/statamic-mcp`. The Marketplace pulls the name/description from `composer.json` `extra.statamic` ("Statamic MCP") and renders the README as the listing body.
  3. Set price **Free**, Statamic version compatibility **6.x**, add the GitHub repo URL and tags (e.g. "MCP", "AI", "API").
  4. Submit for review. The Statamic team reviews new listings manually — respond to any feedback, then confirm the listing is live at statamic.com/addons.
  5. After it is live: install once from the Marketplace listing's instructions verbatim (fresh site, copy-paste the quickstart) — the listing, README, and reality must agree.

