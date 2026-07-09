# Execution notes — deviations and carry-forwards from review findings

Controller-maintained log of approved deviations from the plan text discovered during execution. Every task dispatch must consult this file; where it conflicts with the plan, THIS FILE WINS.

## Approved deviations from plan text

1. **(Task 3, from Pest 4.7.4 reality)** `DisabledMcpTestCase` class is impossible (Pest forbids two class bindings per file). Shipped as trait `tests/DisablesMcp.php` instead. Same pattern applies to any future per-file test-case override: use a trait (e.g. `tests/UsesOAuthMode.php`).
2. **(Task 3 quality review, CRITICAL)** ServiceProvider builds + validates the full middleware stack BEFORE calling `Mcp::web()` (fail-closed: a config-shape throw must leave zero routes registered, never an unauthenticated route), and `Arr::wrap()`s the configured middleware.
3. **(Task 3 quality review, IMPORTANT)** OAuth branch uses a single wrapper middleware `Danielgnh\StatamicMcp\Middleware\AuthenticateOAuth::class` — NOT the plan's `[EnsureOAuthConfigured::class, 'auth:api']` pair. Reason: Laravel's middleware priority hoists `auth:api` (AuthenticatesRequests) above the preflight at runtime, 500ing on missing api guard and disabling pre-auth throttle.
4. **(Task 3 quality review)** Middleware-order tests must assert the RESOLVED pipeline (`app('router')->gatherRouteMiddleware($route)`), never the declared array.

## Carry-forward instructions for future tasks

- **Task 6 (AuthenticateMcpToken):** the class must NEVER implement `Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests` — Laravel's priority sorter would hoist it above the configured throttle. The Task 3 resolved-pipeline test pins this; leave a comment on the class.
- **Task 23 (OAuth mode):** implement `src/Middleware/AuthenticateOAuth.php` (single class: oauth preflight → 503-with-remedy, then delegate to the `api` guard, e.g. via `auth()->shouldUse('api')` + manual Authenticate invocation or guard check) INSTEAD of the plan's separate `EnsureOAuthConfigured` + `'auth:api'`. Must not implement `AuthenticatesRequests`. Adapt the plan's T23 tests: assert resolved pipeline has `AuthenticateOAuth` before `EnsureMcpPermission` and does NOT contain `auth:api`/`Authenticate`. The provider already forward-references this class (Task 3 fix commit).
- **Task 24/26:** mcp:doctor + README should mention the "enabled but failed to mount" state (ServiceProvider logs `Statamic MCP failed to mount; run php please mcp:doctor` via Log::warning on caught boot failure).
- **Task 27 (CI):** add a `<source><include><directory>src</directory></include></source>` block to phpunit.xml (T1 quality suggestion — deferred here); PHPStan as planned (also catches the provider's forward class references).
- **Task 28 (release):** add composer.json `keywords` (statamic, mcp, addon, ai) before Packagist submit (T1 quality suggestion).
- **All tasks:** never commit `composer-integrity.lock` (machine-local soak-time plugin artifact, gitignored since 81bf2f8). Never run `pest --parallel` (shared dev-null sandbox).
