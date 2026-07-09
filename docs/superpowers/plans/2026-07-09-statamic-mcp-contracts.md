# 00 — Shared Code Contracts (danielgnh/statamic-mcp)

Complete final code. Downstream plan sections copy-paste from here verbatim — do not restyle.
All laravel/mcp APIs verified against v0.8.2 source; all Statamic APIs against statamic/cms 6.x source (see 00-verified-facts.md).

## 1. composer.json

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

## 2. config/mcp.php (published to config/statamic/mcp.php — spec §7, verbatim)

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

## 3. src/ServiceProvider.php (+ the two route middleware it wires)

Note: `protected $config = false` disables Statamic's slug-based config boot (`statamic-mcp` key / `config/statamic-mcp.php`); we merge under `statamic.mcp` and publish to `config/statamic/mcp.php` ourselves — the first-party `config/statamic/api.php` idiom. Statamic's `AddonServiceProvider` (verified 6.x) defines `protected $config = true`, `protected $commands = []`, and no `register()` method.

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

### src/Middleware/EnsureOAuthConfigured.php (oauth preflight — 503 with remedy, spec §5)

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

### src/Middleware/EnsureMcpPermission.php (the one addon permission — both auth modes)

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

## 4. src/Server.php

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
}
```

## 5. src/Tools/Tool.php — the ONE abstract base (+ src/Tools/ToolException.php)

Note: laravel/mcp v0.8.2 `CallTool implements Errable` catches every Throwable, but masks generic exception messages as "An internal server error occurred." when `app.debug` is off (verified: `InteractsWithResponses::toErrorMessage`) — so the base catches `ToolException` itself, guaranteeing the message reaches the model. `ValidationException` from `$request->validate()` is deliberately NOT caught here: CallTool renders it as a tool error with full field messages.

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

    public const LIVENESS_CREATED = 'created — live';

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

### src/Tools/ToolException.php

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

## 6. src/Tokens/TokenRepository.php (+ src/Tokens/PlainToken.php)

Token format `mcp_{tokenId}_{secret}`; `Str::random()` is alphanumeric so neither part ever contains `_` — positional parsing is safe. SHA-256 only; plaintext exists only inside the returned `PlainToken`.

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

### src/Tokens/PlainToken.php

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

## 7. src/Middleware/AuthenticateMcpToken.php

Check order is load-bearing (spec §5): length cap → positional parse → hash_equals → expiry → User::find → auth manager. `Auth::shouldUse()` + `Auth::setUser()` (never merely `$request->setUserResolver()`) so `Statamic\Facades\User::current()`, revision authorship, and event listeners all see the acting user — laravel/mcp's `Request::user()` resolves through the auth manager's userResolver (verified v0.8.2).

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

## 8. Test harness

Commit an empty keep file at `tests/__fixtures__/dev-null/.gitkeep` — AddonTestCase redirects all Stache saves there (verified facts §1), so every `->save()` in fixtures is disk-safe and per-test isolated.

### tests/TestCase.php

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

### tests/Pest.php

```php
<?php

use Danielgnh\StatamicMcp\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);
```

### tests/Support/Fixtures.php

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

### Example test — tests/Feature/EntriesListTest.php (the pattern every tool test copies)

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
```

## 9. Tool naming table (14 tools — spec §4)

`#[Name]` is mandatory on every tool: laravel/mcp's default derivation is kebab-case, our names are snake_case. `#[Description]` per tool comes from the spec §4 contracts (written in the per-tool plan sections).

| # | Class | File | `#[Name]` | Annotations | shouldRegister() gate |
|---|---|---|---|---|---|
| 1 | `StatamicOverview` | `src/Tools/StatamicOverview.php` | `statamic_overview` | `#[IsReadOnly]` `#[IsIdempotent]` | always |
| 2 | `BlueprintsGet` | `src/Tools/BlueprintsGet.php` | `blueprints_get` | `#[IsReadOnly]` | always |
| 3 | `EntriesList` | `src/Tools/EntriesList.php` | `entries_list` | `#[IsReadOnly]` | always |
| 4 | `EntriesGet` | `src/Tools/EntriesGet.php` | `entries_get` | `#[IsReadOnly]` | always |
| 5 | `EntriesCreate` | `src/Tools/EntriesCreate.php` | `entries_create` | — (write) | `writesEnabled()` |
| 6 | `EntriesUpdate` | `src/Tools/EntriesUpdate.php` | `entries_update` | `#[IsIdempotent]` (write) | `writesEnabled()` |
| 7 | `EntriesDelete` | `src/Tools/EntriesDelete.php` | `entries_delete` | `#[IsDestructive]` | `deletesEnabled()` |
| 8 | `TermsList` | `src/Tools/TermsList.php` | `terms_list` | `#[IsReadOnly]` | always |
| 9 | `TermsGet` | `src/Tools/TermsGet.php` | `terms_get` | `#[IsReadOnly]` | always |
| 10 | `TermsCreate` | `src/Tools/TermsCreate.php` | `terms_create` | — (write) | `writesEnabled()` |
| 11 | `TermsUpdate` | `src/Tools/TermsUpdate.php` | `terms_update` | `#[IsIdempotent]` (write) | `writesEnabled()` |
| 12 | `TermsDelete` | `src/Tools/TermsDelete.php` | `terms_delete` | `#[IsDestructive]` | `deletesEnabled()` |
| 13 | `GlobalsGet` | `src/Tools/GlobalsGet.php` | `globals_get` | `#[IsReadOnly]` | always |
| 14 | `GlobalsUpdate` | `src/Tools/GlobalsUpdate.php` | `globals_update` | `#[IsIdempotent]` (write) | `writesEnabled()` |

Annotation imports: `Laravel\Mcp\Server\Tools\Annotations\{IsReadOnly, IsIdempotent, IsDestructive}`. Every write/delete tool re-checks its gate inside `execute()` too (stale client tool caches, spec §6 layer 1). Console command classes referenced by the ServiceProvider: `src/Console/IssueToken.php` (`mcp:token`), `src/Console/ListTokens.php` (`mcp:tokens`), `src/Console/RevokeToken.php` (`mcp:token:revoke`), `src/Console/Doctor.php` (`mcp:doctor`) — full code in their own plan section.
