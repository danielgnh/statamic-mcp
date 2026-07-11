# Assets Tools Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Five MCP tools (`assets_list`, `assets_get`, `assets_upload`, `assets_update`, `assets_delete`) so an agent can manage Statamic assets end-to-end — find images, upload from URL/base64, set alt text, delete — under the connected user's real Statamic permissions.

**Architecture:** Every tool extends the existing base `Tool` (exposure via config, native permissions, `ToolException` errors, compact JSON). One new support class (`SourceDownloader`) owns SSRF-guarded URL fetching. One new concern (`ResolvesAssets`) owns container/asset/folder resolution shared by all five tools. Uploads go through Statamic's own `Asset::upload()` for full CP parity (events, SVG sanitization, meta files).

**Tech Stack:** PHP 8.3, statamic/cms ^6, laravel/mcp ^0.8, Pest (feature-first), Pint, PHPStan. Spec: `docs/superpowers/specs/2026-07-11-assets-tools-design.md`.

**Conventions you must follow (read these files first):**
- `src/Tools/Tool.php` — base class every tool extends. Guards throw `ToolException`; `handle()` converts to `Response::error`.
- `src/Tools/EntriesList.php` / `src/Tools/GlobalsUpdate.php` / `src/Tools/TermsDelete.php` — the list/update/delete house style being mirrored.
- `tests/Support/Fixtures.php` + `tests/Feature/TermsListTest.php` — test conventions: `Server::actingAs($user)->tool(X::class, [...])`, `assertOk()`/`assertSee()`/`assertHasErrors([...])`, throwaway roles via `Fixtures::makeUser('permission …')`.
- Run `composer format` (Pint) before every commit. Full checks: `composer test`, `vendor/bin/phpstan analyse`.
- Do not run pest with `--parallel` (shared dev-null sandbox — see `tests/TestCase.php`).

**File map:**

| File | Action | Responsibility |
|---|---|---|
| `config/mcp.php` | Modify | `resources.asset_containers` + `uploads` block |
| `src/Tools/Tool.php` | Modify | `asset_containers` exposure arm, `LIVENESS_UPLOADED`, `liveness()` union |
| `src/Support/SourceDownloader.php` | Create | SSRF-guarded URL → bytes |
| `src/Tools/Concerns/ResolvesAssets.php` | Create | container/asset/folder resolution + shared summary row |
| `src/Tools/AssetsList.php` | Create | paginated listing |
| `src/Tools/AssetsGet.php` | Create | full detail read |
| `src/Tools/AssetsUpload.php` | Create | upload (base64 + URL) |
| `src/Tools/AssetsUpdate.php` | Create | metadata merge-update |
| `src/Tools/AssetsDelete.php` | Create | gated delete |
| `src/Server.php` | Modify | register the five tools |
| `src/Tools/StatamicOverview.php` | Modify | `asset_containers` section |
| `src/Tools/BlueprintsGet.php` | Modify | actionable `assets` fieldtype example |
| `tests/Support/Fixtures.php` | Modify | `assetContainer()` + `tinyPng()` |
| `tests/Feature/Assets*.php`, `tests/Feature/SourceDownloaderTest.php` | Create | coverage per spec §6 |
| `README.md`, `CHANGELOG.md` | Modify | docs |

---

### Task 1: Foundation — config, base Tool exposure, fixtures

**Files:**
- Modify: `config/mcp.php`
- Modify: `src/Tools/Tool.php`
- Modify: `tests/Support/Fixtures.php`
- Test: `tests/Feature/AssetExposureTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/AssetExposureTest.php`:

```php
<?php

use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tools\Tool;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

it('exposes asset containers through exposedHandles honoring config', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');
    Fixtures::assetContainer('docs');

    $probe = new class extends Tool
    {
        protected function execute(Request $request): Response
        {
            throw new RuntimeException('unused');
        }

        public function handles(string $type): array
        {
            return $this->exposedHandles($type);
        }
    };

    // Package default: true = all handles.
    expect($probe->handles('asset_containers'))->toEqualCanonicalizing(['images', 'docs']);

    // Array = intersection with existing handles.
    config(['statamic.mcp.resources.asset_containers' => ['images', 'ghost']]);
    expect($probe->handles('asset_containers'))->toBe(['images']);

    // Upgrade safety: a published config WITHOUT the key exposes nothing.
    config(['statamic.mcp.resources' => ['collections' => true, 'taxonomies' => true, 'globals' => true]]);
    expect($probe->handles('asset_containers'))->toBe([]);
});
```

Add to `tests/Support/Fixtures.php` (new imports: `Illuminate\Support\Facades\Storage`, `Statamic\Contracts\Assets\AssetContainer as AssetContainerContract`, `Statamic\Facades\AssetContainer`):

```php
/**
 * A container on a fake disk (with url so $asset->url() works) plus an
 * asset blueprint with an 'alt' field, mirroring a default install.
 */
public static function assetContainer(string $handle = 'images'): AssetContainerContract
{
    Storage::fake($handle, ['url' => "/assets/{$handle}"]);

    $container = tap(AssetContainer::make($handle)->disk($handle)->title(Str::title($handle)))->save();

    Blueprint::makeFromFields([
        'alt' => ['type' => 'text'],
    ])->setHandle($handle)->setNamespace('assets')->save();

    return $container;
}

/**
 * A real 1x1 transparent PNG (68 bytes) — valid image bytes without
 * requiring GD in the test suite.
 */
public static function tinyPng(): string
{
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/pest tests/Feature/AssetExposureTest.php`
Expected: FAIL — `UnhandledMatchError` (no `asset_containers` arm in `Tool::exposedHandles()`).

- [ ] **Step 3: Implement the base changes**

In `config/mcp.php`, extend `resources` and add `uploads` after `per_page`:

```php
    // What exists as far as MCP is concerned. true = all handles, or an
    // array of handles: 'collections' => ['blog', 'pages'].
    // NOTE: this controls EXPOSURE only. Who may read/write what is decided
    // by the connected user's Statamic roles & permissions — nothing here.
    'resources' => [
        'collections' => true,
        'taxonomies' => true,
        'globals' => true,
        'asset_containers' => true,
    ],

    // Default page size for list tools (hard-capped at 100 in code).
    'per_page' => 25,

    'uploads' => [
        // Hard per-upload cap in kilobytes, for both source_url downloads
        // and decoded content_base64. Container validation rules still
        // apply on top.
        'max_size' => 10240,

        // Exact-host allowlist for assets_upload source_url. null = any
        // public host. Private/reserved/loopback IPs are ALWAYS blocked.
        'source_allowlist' => null,
    ],
```

In `src/Tools/Tool.php`:

1. Add import: `use Statamic\Facades\AssetContainer;` and `use Statamic\Contracts\Assets\Asset as AssetContract;`
2. Add a constant below `LIVENESS_CREATED`:

```php
    public const LIVENESS_UPLOADED = 'uploaded — live'; // assets have no draft state
```

3. In `exposedHandles()`, add a match arm and widen both `@param` docblocks (on `ensureExposed()` and `exposedHandles()`) to `'collections'|'taxonomies'|'globals'|'asset_containers'`:

```php
        $all = match ($type) {
            'collections' => Collection::handles()->all(),
            'taxonomies' => Taxonomy::handles()->all(),
            'globals' => GlobalSet::all()->map->handle()->values()->all(),
            'asset_containers' => AssetContainer::all()->map->handle()->values()->all(),
        };
```

4. Widen `liveness()`'s union (Asset::editUrl() verified in 6.x source):

```php
    protected function liveness(EntryContract|LocalizedTerm|Variables|AssetContract $saved, string $state): array
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/pest tests/Feature/AssetExposureTest.php`
Expected: PASS (1 test, 4 assertions).

- [ ] **Step 5: Full suite, format, commit**

```bash
composer format && composer test && vendor/bin/phpstan analyse
git add -A && git commit -m "feat: expose asset containers in config and base tool plumbing"
```

---

### Task 2: SourceDownloader — SSRF-guarded URL fetching

**Files:**
- Create: `src/Support/SourceDownloader.php`
- Test: `tests/Feature/SourceDownloaderTest.php`

The one place this server makes outbound requests on agent input (spec §5). Fail-closed: scheme + allowlist + public-IP checks per redirect hop, connection pinned to the validated IP, byte cap enforced during transfer and on the final body. Body is buffered in memory deliberately — `uploads.max_size` (default 10 MB) bounds it, and `Http::fake()` cannot stream to a sink, so this keeps the class fully testable.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/SourceDownloaderTest.php`:

```php
<?php

use Danielgnh\StatamicMcp\Support\SourceDownloader;
use Danielgnh\StatamicMcp\Tools\ToolException;
use Illuminate\Support\Facades\Http;

function downloaderWithPublicDns(): SourceDownloader
{
    // Resolver injected so tests never touch real DNS; 93.184.216.34 is public.
    return new SourceDownloader(fn (string $host) => ['93.184.216.34']);
}

it('downloads a file and derives the basename from the final url', function () {
    Http::fake(['https://images.example.com/*' => Http::response('PNGBYTES', 200)]);

    [$contents, $basename] = downloaderWithPublicDns()->download('https://images.example.com/photos/hero.png');

    expect($contents)->toBe('PNGBYTES');
    expect($basename)->toBe('hero.png');
});

it('rejects non-http schemes', function () {
    downloaderWithPublicDns()->download('ftp://images.example.com/hero.png');
})->throws(ToolException::class, 'source_url must use http or https');

it('rejects unparseable urls', function () {
    downloaderWithPublicDns()->download('not a url');
})->throws(ToolException::class, 'not a valid absolute URL');

it('rejects literal private, loopback, link-local, CGN and IPv6-internal addresses', function (string $url) {
    Http::fake();

    expect(fn () => (new SourceDownloader)->download($url))
        ->toThrow(ToolException::class, 'private or reserved address');

    Http::assertNothingSent();
})->with([
    'http://127.0.0.1/x.png',
    'http://10.0.0.5/x.png',
    'http://172.16.0.1/x.png',
    'http://192.168.1.1/x.png',
    'http://169.254.169.254/latest/meta-data', // cloud metadata endpoint
    'http://100.64.0.1/x.png',                 // carrier-grade NAT
    'http://[::1]/x.png',
    'http://[fc00::1]/x.png',
    'http://[fe80::1]/x.png',
]);

it('rejects hosts that RESOLVE to a private address', function () {
    Http::fake();

    $downloader = new SourceDownloader(fn (string $host) => ['10.0.0.7']);

    expect(fn () => $downloader->download('https://innocent-looking.example.com/x.png'))
        ->toThrow(ToolException::class, 'private or reserved address');

    Http::assertNothingSent();
});

it('rejects unresolvable hosts', function () {
    Http::fake();

    $downloader = new SourceDownloader(fn (string $host) => []);

    expect(fn () => $downloader->download('https://nope.example.com/x.png'))
        ->toThrow(ToolException::class, "could not resolve host 'nope.example.com'");
});

it('enforces the source allowlist when configured', function () {
    config(['statamic.mcp.uploads.source_allowlist' => ['images.example.com']]);
    Http::fake(['https://images.example.com/*' => Http::response('OK', 200)]);

    [$contents] = downloaderWithPublicDns()->download('https://images.example.com/a.png');
    expect($contents)->toBe('OK');

    expect(fn () => downloaderWithPublicDns()->download('https://evil.example.com/a.png'))
        ->toThrow(ToolException::class, "host 'evil.example.com' is not in the configured source allowlist");
});

it('revalidates every redirect hop and refuses a redirect to a private address', function () {
    Http::fake([
        'https://images.example.com/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data']),
    ]);

    downloaderWithPublicDns()->download('https://images.example.com/a.png');
})->throws(ToolException::class, 'private or reserved address');

it('follows at most 3 redirects', function () {
    Http::fake([
        'https://images.example.com/*' => Http::response('', 302, ['Location' => 'https://images.example.com/again.png']),
    ]);

    downloaderWithPublicDns()->download('https://images.example.com/a.png');
})->throws(ToolException::class, 'redirected more than 3 times');

it('reports non-2xx responses as tool errors', function () {
    Http::fake(['https://images.example.com/*' => Http::response('gone', 404)]);

    downloaderWithPublicDns()->download('https://images.example.com/a.png');
})->throws(ToolException::class, 'responded with HTTP 404');

it('rejects bodies over the configured max_size', function () {
    config(['statamic.mcp.uploads.max_size' => 1]); // 1 KB
    Http::fake(['https://images.example.com/*' => Http::response(str_repeat('x', 2048), 200)]);

    downloaderWithPublicDns()->download('https://images.example.com/big.png');
})->throws(ToolException::class, 'exceeds the 1 KB limit');

it('rejects empty bodies', function () {
    Http::fake(['https://images.example.com/*' => Http::response('', 200)]);

    downloaderWithPublicDns()->download('https://images.example.com/empty.png');
})->throws(ToolException::class, 'empty body');
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/SourceDownloaderTest.php`
Expected: FAIL — `Class "Danielgnh\StatamicMcp\Support\SourceDownloader" not found`.

- [ ] **Step 3: Implement SourceDownloader**

Create `src/Support/SourceDownloader.php`:

```php
<?php

namespace Danielgnh\StatamicMcp\Support;

use Closure;
use Danielgnh\StatamicMcp\Tools\ToolException;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * SSRF-guarded download of an agent-supplied source_url (spec §5) — the one
 * place this server makes outbound requests on model input. Fail-closed:
 * scheme, allowlist, and public-IP checks run per redirect hop, the
 * connection is pinned to the validated IP, and the byte cap is enforced
 * both mid-transfer and on the final body (Content-Length is advisory).
 */
class SourceDownloader
{
    private const MAX_REDIRECTS = 3;

    private const TIMEOUT_SECONDS = 15;

    /**
     * @param  null|Closure(string): list<string>  $resolver  host => IPs; injectable so tests never hit real DNS
     */
    public function __construct(private readonly ?Closure $resolver = null) {}

    /**
     * @return array{0: string, 1: string} [binary contents, basename derived from the final URL ('' when it has none)]
     */
    public function download(string $url): array
    {
        $maxBytes = $this->maxKilobytes() * 1024;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $ip = $this->validated($url);

            $response = $this->fetch($url, $ip, $maxBytes);

            if ($response->redirect()) {
                $location = $response->header('Location');

                if ($location === '') {
                    throw new ToolException('source_url redirected without a Location header — nothing was uploaded');
                }

                // Relative Location headers resolve against the current URL;
                // the next loop iteration re-runs every check on the result.
                $url = (string) UriResolver::resolve(new Uri($url), new Uri($location));

                continue;
            }

            if (! $response->successful()) {
                throw new ToolException(sprintf('source_url responded with HTTP %d — nothing was uploaded', $response->status()));
            }

            $body = $response->body();

            if (strlen($body) > $maxBytes) {
                throw new ToolException(sprintf('source_url file exceeds the %d KB limit (statamic.mcp.uploads.max_size)', $this->maxKilobytes()));
            }

            if ($body === '') {
                throw new ToolException('source_url returned an empty body — nothing was uploaded');
            }

            return [$body, basename((string) parse_url($url, PHP_URL_PATH))];
        }

        throw new ToolException(sprintf('source_url redirected more than %d times — aborted', self::MAX_REDIRECTS));
    }

    /**
     * Scheme, allowlist, and DNS checks for one URL. Returns the IP the
     * connection must be pinned to.
     */
    private function validated(string $url): string
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['host'])) {
            throw new ToolException(sprintf("source_url is not a valid absolute URL: '%s'", $url));
        }

        if (! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            throw new ToolException('source_url must use http or https');
        }

        $host = strtolower($parts['host']);

        $allowlist = config('statamic.mcp.uploads.source_allowlist');

        if (is_array($allowlist) && ! in_array($host, array_map(strtolower(...), $allowlist), true)) {
            throw new ToolException(sprintf(
                "host '%s' is not in the configured source allowlist (statamic.mcp.uploads.source_allowlist): %s",
                $host,
                implode(', ', $allowlist),
            ));
        }

        $ips = $this->resolve($host);

        if ($ips === []) {
            throw new ToolException(sprintf("could not resolve host '%s'", $host));
        }

        // EVERY resolved address must be public — a single private A record
        // on a multi-record host is an SSRF vector, not an edge case.
        foreach ($ips as $ip) {
            if (! $this->isPublicIp($ip)) {
                throw new ToolException(sprintf(
                    "source_url host '%s' resolves to a private or reserved address — refusing to fetch",
                    $host,
                ));
            }
        }

        return $ips[0];
    }

    /** @return list<string> */
    private function resolve(string $host): array
    {
        // Literal IPs (including bracketed IPv6) skip DNS entirely.
        $literal = trim($host, '[]');

        if (filter_var($literal, FILTER_VALIDATE_IP) !== false) {
            return [$literal];
        }

        if ($this->resolver !== null) {
            return ($this->resolver)($host);
        }

        return array_values(array_filter(array_merge(
            array_column(dns_get_record($host, DNS_A) ?: [], 'ip'),
            array_column(dns_get_record($host, DNS_AAAA) ?: [], 'ipv6'),
        )));
    }

    private function isPublicIp(string $ip): bool
    {
        // NO_PRIV_RANGE: 10/8, 172.16/12, 192.168/16, fc00::/7.
        // NO_RES_RANGE: 0/8, 127/8, 169.254/16, 240/4, ::1, ::, ::ffff:0:0/96, fe80::/10.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        // 100.64.0.0/10 (carrier-grade NAT) is not covered by PHP's flags.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $long = ip2long($ip);

            if ($long >= ip2long('100.64.0.0') && $long <= ip2long('100.127.255.255')) {
                return false;
            }
        }

        return true;
    }

    private function fetch(string $url, string $ip, int $maxBytes): Response
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $port = parse_url($url, PHP_URL_PORT)
            ?? (strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https' ? 443 : 80);

        try {
            return Http::withOptions([
                // Hops are revalidated by download()'s loop, never by curl.
                'allow_redirects' => false,
                // Pin the connection to the validated IP — closes the DNS
                // rebinding window between check and use (spec §5). curl's
                // RESOLVE syntax is IPv4/IPv6-agnostic but pinning a
                // bracketed host is not; IPv6 literals were validated above.
                'curl' => str_contains($ip, ':') ? [] : [CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"]],
                // Content-Length is advisory — abort mid-transfer at the cap.
                // (Guzzle wraps callback throws; unwrapped in the catch below.
                // Http::fake() never runs these — the body-length check in
                // download() is the layer tests exercise.)
                'on_headers' => function ($response) use ($maxBytes): void {
                    if ((int) $response->getHeaderLine('Content-Length') > $maxBytes) {
                        throw new ToolException(sprintf('source_url file exceeds the %d KB limit (statamic.mcp.uploads.max_size)', $this->maxKilobytes()));
                    }
                },
                'progress' => function ($downloadTotal, int $downloaded) use ($maxBytes): void {
                    if ($downloaded > $maxBytes) {
                        throw new ToolException(sprintf('source_url file exceeds the %d KB limit (statamic.mcp.uploads.max_size)', $this->maxKilobytes()));
                    }
                },
            ])->timeout(self::TIMEOUT_SECONDS)->get($url);
        } catch (Throwable $e) {
            // Surface our own cap/guard exceptions from inside Guzzle wrappers.
            for ($previous = $e; $previous !== null; $previous = $previous->getPrevious()) {
                if ($previous instanceof ToolException) {
                    throw $previous;
                }
            }

            throw new ToolException(sprintf('could not download source_url: %s', $e->getMessage()));
        }
    }

    private function maxKilobytes(): int
    {
        return (int) config('statamic.mcp.uploads.max_size', 10240);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/SourceDownloaderTest.php`
Expected: PASS (11 tests; the private-address case is a dataset of 9).

- [ ] **Step 5: Format, static analysis, commit**

```bash
composer format && vendor/bin/phpstan analyse && vendor/bin/pest tests/Feature/SourceDownloaderTest.php
git add -A && git commit -m "feat: add SSRF-guarded SourceDownloader for asset uploads"
```

---

### Task 3: ResolvesAssets concern + assets_list

**Files:**
- Create: `src/Tools/Concerns/ResolvesAssets.php`
- Create: `src/Tools/AssetsList.php`
- Modify: `src/Server.php`
- Test: `tests/Feature/AssetsListTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/AssetsListTest.php`:

```php
<?php

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tools\AssetsList;
use Illuminate\Support\Facades\Storage;
use Statamic\Facades\Asset;

it('lists assets with summary columns', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');

    Storage::disk('images')->put('hero.png', Fixtures::tinyPng());
    Storage::disk('images')->put('notes.txt', 'plain text');

    Asset::find('images::hero.png')->data(['alt' => 'A hero image'])->save();

    $user = Fixtures::makeUser('view images assets');

    Server::actingAs($user)
        ->tool(AssetsList::class, ['container' => 'images'])
        ->assertOk()
        ->assertSee('"id":"images::hero.png"')
        ->assertSee('"path":"hero.png"')
        ->assertSee('"basename":"hero.png"')
        ->assertSee('"is_image":true')
        ->assertSee('"alt":"A hero image"')
        ->assertSee('"url":"/assets/images/hero.png"')
        ->assertSee('"id":"images::notes.txt"')
        ->assertSee('"is_image":false')
        ->assertSee('"total":2');
});

it('paginates ordered by path and reports the next page, capping per_page at 100', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');

    // Saved out of order on purpose — the listing must sort by path.
    Storage::disk('images')->put('c.txt', 'c');
    Storage::disk('images')->put('a.txt', 'a');
    Storage::disk('images')->put('b.txt', 'b');

    $user = Fixtures::makeUser('view images assets');

    Server::actingAs($user)
        ->tool(AssetsList::class, ['container' => 'images', 'limit' => 2, 'page' => 1])
        ->assertOk()
        ->assertSee('"total":3')
        ->assertSee('"next_page":2')
        ->assertSee('"path":"a.txt"')
        ->assertSee('"path":"b.txt"')
        ->assertDontSee('"path":"c.txt"');

    Server::actingAs($user)
        ->tool(AssetsList::class, ['container' => 'images', 'limit' => 2, 'page' => 2])
        ->assertOk()
        ->assertSee('"path":"c.txt"')
        ->assertDontSee('"path":"a.txt"')
        ->assertSee('"next_page":null');

    Server::actingAs($user)
        ->tool(AssetsList::class, ['container' => 'images', 'limit' => 500])
        ->assertOk()
        ->assertSee('"per_page":100');
});

it('filters to a folder subtree', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');

    Storage::disk('images')->put('root.txt', 'r');
    Storage::disk('images')->put('blog/hero.png', Fixtures::tinyPng());
    Storage::disk('images')->put('blog/2026/nested.png', Fixtures::tinyPng());

    Server::actingAs(Fixtures::makeUser('view images assets'))
        ->tool(AssetsList::class, ['container' => 'images', 'folder' => '/blog/'])
        ->assertOk()
        ->assertSee('"path":"blog/hero.png"')
        ->assertSee('"path":"blog/2026/nested.png"')
        ->assertSee('"folder":"blog"')
        ->assertDontSee('"path":"root.txt"')
        ->assertSee('"total":2');
});

it('rejects folder traversal', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');

    Server::actingAs(Fixtures::makeSuper())
        ->tool(AssetsList::class, ['container' => 'images', 'folder' => '../secrets'])
        ->assertHasErrors(["folder may not contain '..' or backslashes — pass a path like 'blog/2026'"]);
});

it('denies listing without the view permission', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');

    $user = Fixtures::makeUser(); // 'access mcp' only

    Server::actingAs($user)
        ->tool(AssetsList::class, ['container' => 'images'])
        ->assertHasErrors(["requires 'view images assets' — grant it to a role of {$user->email()} in the Control Panel"]);
});

it('treats an unexposed container as missing, listing only exposed handles', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');
    Fixtures::assetContainer('secrets');

    config(['statamic.mcp.resources.asset_containers' => ['images']]);

    Server::actingAs(Fixtures::makeSuper())
        ->tool(AssetsList::class, ['container' => 'secrets'])
        ->assertHasErrors(["asset container 'secrets' not found — available: images"]);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/AssetsListTest.php`
Expected: FAIL — `Class "Danielgnh\StatamicMcp\Tools\AssetsList" not found`.

- [ ] **Step 3: Implement the concern and the tool**

Create `src/Tools/Concerns/ResolvesAssets.php`:

```php
<?php

namespace Danielgnh\StatamicMcp\Tools\Concerns;

use Danielgnh\StatamicMcp\Tools\ToolException;
use Statamic\Contracts\Assets\Asset as AssetContract;
use Statamic\Contracts\Assets\AssetContainer as AssetContainerContract;
use Statamic\Facades\AssetContainer;

trait ResolvesAssets
{
    /**
     * Exposure check + fetch in one step. ensureExposed() checked the handle
     * against the index, but index and item fetch can drift (a deploy under a
     * warm Stache) — same guard as globals_update, thrown here because these
     * call sites need the container, not a Response.
     */
    protected function resolveContainer(string $handle): AssetContainerContract
    {
        $this->ensureExposed('asset_containers', $handle);

        $container = AssetContainer::findByHandle($handle);

        if ($container === null) {
            $available = $this->exposedHandles('asset_containers');
            sort($available);

            throw new ToolException(sprintf(
                "asset container '%s' not found — available: %s",
                $handle,
                $available === [] ? '(none exposed)' : implode(', ', $available),
            ));
        }

        return $container;
    }

    protected function resolveAsset(AssetContainerContract $container, string $path): AssetContract
    {
        $asset = $container->asset(ltrim($path, '/'));

        if (! $asset) {
            throw new ToolException(sprintf(
                "asset '%s' not found in container '%s' — use assets_list to see available paths",
                $path,
                $container->handle(),
            ));
        }

        return $asset;
    }

    /**
     * Normalized folder path or null for the container root. Forward slashes
     * only, no traversal; nested folders ('blog/2026') are fine — Statamic
     * folders are implicit, created by writing into them.
     */
    protected function normalizeFolder(?string $folder): ?string
    {
        if ($folder === null) {
            return null;
        }

        $folder = trim($folder, "/ \t");

        if ($folder === '' || $folder === '.') {
            return null;
        }

        if (str_contains($folder, '..') || str_contains($folder, '\\')) {
            throw new ToolException("folder may not contain '..' or backslashes — pass a path like 'blog/2026'");
        }

        return $folder;
    }

    /**
     * The summary row shared by assets_list, assets_get, and assets_upload
     * responses — enough to pick or reference an image without another call.
     *
     * @return array<string, mixed>
     */
    protected function assetSummary(AssetContract $asset): array
    {
        $folder = $asset->folder();
        $dimensions = $asset->dimensions();

        return [
            'id' => $asset->id(),
            'path' => $asset->path(),
            'basename' => $asset->basename(),
            'folder' => in_array($folder, ['.', '/', ''], true) ? null : $folder,
            'url' => $asset->url(),
            'is_image' => $asset->isImage(),
            'size' => $asset->size(),
            'dimensions' => array_filter($dimensions) === [] ? null : $dimensions,
            'alt' => $asset->data()->get('alt'),
        ];
    }
}
```

Create `src/Tools/AssetsList.php`:

```php
<?php

namespace Danielgnh\StatamicMcp\Tools;

use Danielgnh\StatamicMcp\Tools\Concerns\ResolvesAssets;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Statamic\Facades\Asset;

#[Name('assets_list')]
#[Description('List assets in a container — summary columns only (id, path, basename, folder, url, is_image, size, dimensions, alt); use assets_get for full metadata. Optionally filter to a folder subtree. Paginated: the response carries total, total_pages, and next_page (null on the last page); ordered by path.')]
#[IsReadOnly]
class AssetsList extends Tool
{
    use ResolvesAssets;

    public function schema(JsonSchema $schema): array
    {
        return [
            'container' => $schema->string()->description('Asset container handle — see statamic_overview for what is available.')->required(),
            'folder' => $schema->string()->description("Only assets under this folder (subtree), e.g. 'blog' or 'blog/2026'."),
            'limit' => $schema->integer()->description('Page size. Defaults to the server default (25); hard-capped at 100.'),
            'page' => $schema->integer()->default(1)->description('Page number, starting at 1.'),
        ];
    }

    protected function execute(Request $request): Response
    {
        // laravel/mcp doesn't enforce the JSON schema server-side (T10) —
        // validate shapes before touching them.
        $validated = $request->validate(
            [
                'container' => 'required|string',
                'folder' => 'nullable|string',
                'limit' => 'nullable|integer|min:1',
                'page' => 'nullable|integer|min:1',
            ],
            ['container.required' => 'Pass an asset container handle, e.g. "images" — see statamic_overview.'],
        );

        $handle = $validated['container'];
        $this->resolveContainer($handle);

        $user = $this->user($request);
        $this->ensurePermission($user, "view {$handle} assets");

        $folder = $this->normalizeFolder($validated['folder'] ?? null);

        $perPage = min((int) ($validated['limit'] ?? config('statamic.mcp.per_page', 25)), 100);
        $perPage = max($perPage, 1);
        $page = max((int) ($validated['page'] ?? 1), 1);

        $query = Asset::query()->where('container', $handle);

        if ($folder !== null) {
            // Subtree filter: the folder and everything nested below it.
            $query->where('path', 'like', $folder.'/%');
        }

        $total = (clone $query)->count();
        $totalPages = max((int) ceil($total / $perPage), 1);

        // Deterministic order — offset pagination without a stable order
        // repeats/skips items between calls (same rule as entries_list).
        $assets = $query->orderBy('path')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        return $this->json([
            'assets' => $assets->map(fn ($asset) => $this->assetSummary($asset))->values()->all(),
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

In `src/Server.php`, append to the `$tools` array after `Tools\GlobalsUpdate::class`:

```php
        Tools\AssetsList::class,
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/AssetsListTest.php`
Expected: PASS (6 tests).

- [ ] **Step 5: Format, full suite, commit**

```bash
composer format && composer test && vendor/bin/phpstan analyse
git add -A && git commit -m "feat: add assets_list tool with folder filtering and pagination"
```

---

### Task 4: assets_get

**Files:**
- Create: `src/Tools/AssetsGet.php`
- Modify: `src/Server.php`
- Test: `tests/Feature/AssetsGetTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/AssetsGetTest.php`:

```php
<?php

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tools\AssetsGet;
use Illuminate\Support\Facades\Storage;
use Statamic\Facades\Asset;

it('returns full asset detail including raw blueprint data', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');

    Storage::disk('images')->put('blog/hero.png', Fixtures::tinyPng());
    Asset::find('images::blog/hero.png')->data(['alt' => 'A hero image'])->save();

    Server::actingAs(Fixtures::makeUser('view images assets'))
        ->tool(AssetsGet::class, ['container' => 'images', 'path' => 'blog/hero.png'])
        ->assertOk()
        ->assertSee('"id":"images::blog/hero.png"')
        ->assertSee('"folder":"blog"')
        ->assertSee('"is_image":true')
        ->assertSee('"data":{"alt":"A hero image"}')
        ->assertSee('"mime_type":"image/png"')
        ->assertSee('"last_modified"')
        ->assertSee('"cp_edit_url"');
});

it('reports a missing path with a pointer to assets_list', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');

    Server::actingAs(Fixtures::makeSuper())
        ->tool(AssetsGet::class, ['container' => 'images', 'path' => 'nope.png'])
        ->assertHasErrors(["asset 'nope.png' not found in container 'images' — use assets_list to see available paths"]);
});

it('denies reading without the view permission', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');
    Storage::disk('images')->put('hero.png', Fixtures::tinyPng());

    $user = Fixtures::makeUser(); // 'access mcp' only

    Server::actingAs($user)
        ->tool(AssetsGet::class, ['container' => 'images', 'path' => 'hero.png'])
        ->assertHasErrors(["requires 'view images assets' — grant it to a role of {$user->email()} in the Control Panel"]);
});

it('treats an unexposed container as missing', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');
    Fixtures::assetContainer('secrets');
    Storage::disk('secrets')->put('x.txt', 'x');

    config(['statamic.mcp.resources.asset_containers' => ['images']]);

    Server::actingAs(Fixtures::makeSuper())
        ->tool(AssetsGet::class, ['container' => 'secrets', 'path' => 'x.txt'])
        ->assertHasErrors(["asset container 'secrets' not found — available: images"]);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/AssetsGetTest.php`
Expected: FAIL — `Class "Danielgnh\StatamicMcp\Tools\AssetsGet" not found`.

- [ ] **Step 3: Implement the tool**

Create `src/Tools/AssetsGet.php`:

```php
<?php

namespace Danielgnh\StatamicMcp\Tools;

use Danielgnh\StatamicMcp\Tools\Concerns\ResolvesAssets;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('assets_get')]
#[Description("Read one asset's full detail: the assets_list summary columns plus raw blueprint data (alt text and custom fields — the shape assets_update accepts), mime_type, last_modified, and cp_edit_url. Raw values only, never augmented.")]
#[IsReadOnly]
class AssetsGet extends Tool
{
    use ResolvesAssets;

    public function schema(JsonSchema $schema): array
    {
        return [
            'container' => $schema->string()->description('Asset container handle — see statamic_overview.')->required(),
            'path' => $schema->string()->description("Asset path relative to the container root, e.g. 'blog/hero.jpg'.")->required(),
        ];
    }

    protected function execute(Request $request): Response
    {
        $validated = $request->validate(
            [
                'container' => 'required|string',
                'path' => 'required|string',
            ],
            [
                'container.required' => 'Pass an asset container handle, e.g. "images" — see statamic_overview.',
                'path.required' => "Pass an asset path relative to the container root, e.g. 'blog/hero.jpg'.",
            ],
        );

        $handle = $validated['container'];
        $container = $this->resolveContainer($handle);

        $user = $this->user($request);
        $this->ensurePermission($user, "view {$handle} assets");

        $asset = $this->resolveAsset($container, $validated['path']);

        return $this->json([
            ...$this->assetSummary($asset),
            'data' => $asset->data()->all(),
            'mime_type' => $asset->mimeType(),
            'last_modified' => $asset->lastModified()?->toIso8601String(),
            'cp_edit_url' => $asset->editUrl(),
        ]);
    }
}
```

In `src/Server.php`, append after `Tools\AssetsList::class`:

```php
        Tools\AssetsGet::class,
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/AssetsGetTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Format, full suite, commit**

```bash
composer format && composer test && vendor/bin/phpstan analyse
git add -A && git commit -m "feat: add assets_get tool"
```

---

### Task 5: assets_upload — core pipeline with content_base64

**Files:**
- Create: `src/Tools/AssetsUpload.php`
- Modify: `src/Server.php`
- Test: `tests/Feature/AssetsUploadTest.php`

The full pipeline (spec §3): gates → bytes → filename/extension → collision refusal → CP-parity validation (`AllowedFile` + container `validationRules()`) → `makeAsset()->upload()` with honest `AssetCreating`-cancellation reporting. The `source_url` branch is wired in Task 6; this task builds everything else against `content_base64`.

> **Errata (2026-07-11, found during execution):** the `allowUploads()` gate and its test were dropped — the method doesn't exist in Statamic v6 (a v5-ism; the only CP gate is `AssetPolicy::store` = the upload permission). Spec §2/§3 corrected. Ignore `allowUploads` fragments in the verbatim blocks below.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/AssetsUploadTest.php`:

```php
<?php

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tools\AssetsUpload;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Statamic\Events\AssetCreating;
use Statamic\Facades\AssetContainer;

it('uploads base64 content into the container root and reports liveness', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');

    Server::actingAs(Fixtures::makeUser('upload images assets'))
        ->tool(AssetsUpload::class, [
            'container' => 'images',
            'content_base64' => base64_encode(Fixtures::tinyPng()),
            'filename' => 'hero.png',
        ])
        ->assertOk()
        ->assertSee('"id":"images::hero.png"')
        ->assertSee('"is_image":true')
        ->assertSee('"result":"uploaded — live"')
        ->assertSee('"cp_edit_url"');

    expect(Storage::disk('images')->exists('hero.png'))->toBeTrue();
    // CP parity: the upload path writes the asset's .meta.yaml too.
    expect(Storage::disk('images')->exists('.meta/hero.png.yaml'))->toBeTrue();
});

it('uploads into a folder, creating it on demand', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');

    Server::actingAs(Fixtures::makeUser('upload images assets'))
        ->tool(AssetsUpload::class, [
            'container' => 'images',
            'content_base64' => base64_encode(Fixtures::tinyPng()),
            'filename' => 'hero.png',
            'folder' => '/blog/2026/',
        ])
        ->assertOk()
        ->assertSee('"id":"images::blog/2026/hero.png"')
        ->assertSee('"folder":"blog/2026"');

    expect(Storage::disk('images')->exists('blog/2026/hero.png'))->toBeTrue();
});

it('requires exactly one of source_url and content_base64', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');

    $super = Fixtures::makeSuper();

    Server::actingAs($super)
        ->tool(AssetsUpload::class, ['container' => 'images', 'filename' => 'a.png'])
        ->assertHasErrors(['pass exactly one of source_url or content_base64']);

    Server::actingAs($super)
        ->tool(AssetsUpload::class, [
            'container' => 'images',
            'source_url' => 'https://images.example.com/a.png',
            'content_base64' => base64_encode('x'),
            'filename' => 'a.png',
        ])
        ->assertHasErrors(['pass exactly one of source_url or content_base64']);
});

it('rejects invalid base64 and requires a filename with an extension', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');

    $super = Fixtures::makeSuper();

    Server::actingAs($super)
        ->tool(AssetsUpload::class, ['container' => 'images', 'content_base64' => 'not base64!!!', 'filename' => 'a.png'])
        ->assertHasErrors(['content_base64 is not valid base64 — encode the raw file bytes']);

    Server::actingAs($super)
        ->tool(AssetsUpload::class, ['container' => 'images', 'content_base64' => base64_encode('x')])
        ->assertHasErrors(['pass filename — it could not be derived from the source_url']);

    Server::actingAs($super)
        ->tool(AssetsUpload::class, ['container' => 'images', 'content_base64' => base64_encode('x'), 'filename' => 'noextension'])
        ->assertHasErrors(["filename needs an extension, e.g. 'hero.jpg' — the container's rules and Statamic's file guards key off it"]);

    Server::actingAs($super)
        ->tool(AssetsUpload::class, ['container' => 'images', 'content_base64' => base64_encode('x'), 'filename' => '../evil.png'])
        ->assertHasErrors(["filename must be a bare name like 'hero.jpg' — use folder for the destination path"]);
});

it('enforces the max_size cap on decoded base64', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');
    config(['statamic.mcp.uploads.max_size' => 1]); // 1 KB

    Server::actingAs(Fixtures::makeSuper())
        ->tool(AssetsUpload::class, [
            'container' => 'images',
            'content_base64' => base64_encode(str_repeat('x', 2048)),
            'filename' => 'big.txt',
        ])
        ->assertHasErrors(['decoded file exceeds the 1 KB limit (statamic.mcp.uploads.max_size)']);
});

it("blocks files Statamic's AllowedFile guard forbids", function () {
    Fixtures::site();
    Fixtures::assetContainer('images');

    Server::actingAs(Fixtures::makeSuper())
        ->tool(AssetsUpload::class, [
            'container' => 'images',
            'content_base64' => base64_encode('<?php evil();'),
            'filename' => 'shell.php',
        ])
        // The exact message comes from Statamic's validation translations —
        // assert our wrapper prefix, not the translated rule text.
        ->assertSee('upload validation failed');

    expect(Storage::disk('images')->exists('shell.php'))->toBeFalse();
});

it("applies the container's own validation rules like the CP does", function () {
    Fixtures::site();
    Fixtures::assetContainer('images');
    AssetContainer::findByHandle('images')->validationRules(['mimes:jpg,png'])->save();

    Server::actingAs(Fixtures::makeSuper())
        ->tool(AssetsUpload::class, [
            'container' => 'images',
            'content_base64' => base64_encode('plain text'),
            'filename' => 'notes.txt',
        ])
        ->assertSee('upload validation failed');

    expect(Storage::disk('images')->exists('notes.txt'))->toBeFalse();
});

it('refuses to overwrite an existing path, naming the existing asset', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');
    Storage::disk('images')->put('hero.png', Fixtures::tinyPng());

    Server::actingAs(Fixtures::makeSuper())
        ->tool(AssetsUpload::class, [
            'container' => 'images',
            'content_base64' => base64_encode(Fixtures::tinyPng()),
            'filename' => 'hero.png',
        ])
        ->assertHasErrors(["asset 'hero.png' already exists in container 'images' (id 'images::hero.png') — pick another filename, or delete it first if it should be replaced"]);
});

it('refuses containers with uploads disabled', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');
    AssetContainer::findByHandle('images')->allowUploads(false)->save();

    Server::actingAs(Fixtures::makeSuper())
        ->tool(AssetsUpload::class, [
            'container' => 'images',
            'content_base64' => base64_encode('x'),
            'filename' => 'a.txt',
        ])
        ->assertHasErrors(["container 'images' does not allow uploads (allow_uploads is disabled on the container)"]);
});

it('reports an AssetCreating listener cancellation honestly', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');

    Event::listen(AssetCreating::class, fn () => false);

    Server::actingAs(Fixtures::makeSuper())
        ->tool(AssetsUpload::class, [
            'container' => 'images',
            'content_base64' => base64_encode(Fixtures::tinyPng()),
            'filename' => 'hero.png',
        ])
        ->assertHasErrors(['the upload was cancelled by a listener on this site — nothing was created']);

    expect(Storage::disk('images')->exists('hero.png'))->toBeFalse();
});

it('denies uploading without the upload permission', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');

    $user = Fixtures::makeUser('view images assets'); // view is not upload

    Server::actingAs($user)
        ->tool(AssetsUpload::class, [
            'container' => 'images',
            'content_base64' => base64_encode('x'),
            'filename' => 'a.txt',
        ])
        ->assertHasErrors(["requires 'upload images assets' — grant it to a role of {$user->email()} in the Control Panel"]);
});

it('blocks uploads in read-only mode', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');
    config(['statamic.mcp.read_only' => true]);

    Server::actingAs(Fixtures::makeSuper())
        ->tool(AssetsUpload::class, [
            'container' => 'images',
            'content_base64' => base64_encode('x'),
            'filename' => 'a.txt',
        ])
        ->assertHasErrors(['writes are disabled on this server (statamic.mcp.read_only) — reads remain available']);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/AssetsUploadTest.php`
Expected: FAIL — `Class "Danielgnh\StatamicMcp\Tools\AssetsUpload" not found`.

- [ ] **Step 3: Implement the tool**

Create `src/Tools/AssetsUpload.php` (the `source_url` branch already calls `SourceDownloader` — Task 6 only adds its tests):

```php
<?php

namespace Danielgnh\StatamicMcp\Tools;

use Danielgnh\StatamicMcp\Support\SourceDownloader;
use Danielgnh\StatamicMcp\Tools\Concerns\ResolvesAssets;
use Facades\Statamic\Fields\Validator as FieldValidator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Statamic\Assets\AssetUploader;
use Statamic\Contracts\Assets\AssetContainer as AssetContainerContract;
use Statamic\Rules\AllowedFile;

#[Name('assets_upload')]
#[Description('Upload a file into an asset container, from a source_url (the server downloads it — http/https, public hosts only) or inline content_base64 (small files only). filename is required with content_base64, optional with source_url (derived from the URL). Optional folder (e.g. "blog/2026") is created on demand. Existing paths are never overwritten — a collision is an error. Uploads are live immediately; set alt text afterwards with assets_update.')]
class AssetsUpload extends Tool
{
    use ResolvesAssets;

    public function schema(JsonSchema $schema): array
    {
        return [
            'container' => $schema->string()->description('Asset container handle — see statamic_overview.')->required(),
            'source_url' => $schema->string()->description('Public http(s) URL to download. Exactly one of source_url / content_base64.'),
            'content_base64' => $schema->string()->description('Base64-encoded file contents, for small files. Exactly one of source_url / content_base64.'),
            'filename' => $schema->string()->description("Target filename with extension, e.g. 'hero.jpg'. Required with content_base64; with source_url it defaults to the URL's basename."),
            'folder' => $schema->string()->description("Destination folder inside the container, e.g. 'blog/2026'. Defaults to the container root; created on demand."),
        ];
    }

    public function shouldRegister(Request $request): bool
    {
        return $this->writesEnabled();
    }

    protected function execute(Request $request): Response
    {
        // Re-check the registration gate: stale client tool caches are a
        // documented UX wart, not a security hole (spec §6 layer 1).
        $this->ensureWritesEnabled();

        // laravel/mcp doesn't enforce the JSON schema server-side (T10) —
        // validate shapes before touching them.
        $validated = $request->validate(
            [
                'container' => 'required|string',
                'source_url' => 'nullable|string',
                'content_base64' => 'nullable|string',
                'filename' => 'nullable|string',
                'folder' => 'nullable|string',
            ],
            ['container.required' => 'Pass an asset container handle, e.g. "images" — see statamic_overview.'],
        );

        $sourceUrl = $validated['source_url'] ?? null;
        $base64 = $validated['content_base64'] ?? null;

        if (($sourceUrl === null) === ($base64 === null)) {
            throw new ToolException('pass exactly one of source_url or content_base64');
        }

        $handle = $validated['container'];
        $container = $this->resolveContainer($handle);

        $user = $this->user($request);
        $this->ensurePermission($user, "upload {$handle} assets");

        if (! $container->allowUploads()) {
            throw new ToolException("container '{$handle}' does not allow uploads (allow_uploads is disabled on the container)");
        }

        $folder = $this->normalizeFolder($validated['folder'] ?? null);

        [$contents, $derivedName] = $sourceUrl !== null
            ? app(SourceDownloader::class)->download($sourceUrl)
            : [$this->decodeBase64($base64), null];

        // CP parity: the same filename sanitizer the CP's store path applies.
        $basename = AssetUploader::getSafeFilename(
            $this->resolveBasename($validated['filename'] ?? null, $derivedName),
        );

        $path = ltrim(($folder === null ? '' : $folder.'/').$basename, '/');

        // No silent overwrite: the CP 409s here and asks a human; we refuse
        // and tell the agent its options (spec §3). Also keeps Statamic's
        // exists-therefore-timestamp rename fallback from ever firing.
        if ($existing = $container->asset($path)) {
            throw new ToolException(sprintf(
                "asset '%s' already exists in container '%s' (id '%s') — pick another filename, or delete it first if it should be replaced",
                $path,
                $handle,
                $existing->id(),
            ));
        }

        $file = $this->makeUploadedFile($contents, $basename);

        try {
            $this->validateAgainstContainerRules($container, $file);

            // upload() is the CP path: cancellable AssetCreating, SVG
            // sanitization, meta generation, AssetUploaded/AssetCreated.
            // false = a listener cancelled — never report success for it.
            $asset = $container->makeAsset($path)->upload($file);

            if ($asset === false) {
                throw new ToolException('the upload was cancelled by a listener on this site — nothing was created');
            }
        } finally {
            @unlink($file->getPathname());
        }

        return $this->json([
            ...$this->assetSummary($asset),
            ...$this->liveness($asset, self::LIVENESS_UPLOADED),
        ]);
    }

    private function decodeBase64(string $encoded): string
    {
        $contents = base64_decode($encoded, strict: true);

        if ($contents === false || $contents === '') {
            throw new ToolException('content_base64 is not valid base64 — encode the raw file bytes');
        }

        $maxKb = (int) config('statamic.mcp.uploads.max_size', 10240);

        if (strlen($contents) > $maxKb * 1024) {
            throw new ToolException(sprintf('decoded file exceeds the %d KB limit (statamic.mcp.uploads.max_size)', $maxKb));
        }

        return $contents;
    }

    private function resolveBasename(?string $filename, ?string $derived): string
    {
        $basename = $filename !== null && $filename !== '' ? $filename : $derived;

        if ($basename === null || $basename === '') {
            throw new ToolException('pass filename — it could not be derived from the source_url');
        }

        if (str_contains($basename, '/') || str_contains($basename, '\\') || str_contains($basename, '..')) {
            throw new ToolException("filename must be a bare name like 'hero.jpg' — use folder for the destination path");
        }

        if (pathinfo($basename, PATHINFO_EXTENSION) === '') {
            throw new ToolException("filename needs an extension, e.g. 'hero.jpg' — the container's rules and Statamic's file guards key off it");
        }

        return $basename;
    }

    private function makeUploadedFile(string $contents, string $basename): UploadedFile
    {
        $temp = tempnam(sys_get_temp_dir(), 'statamic-mcp-upload-');

        file_put_contents($temp, $contents);

        // test: true because these bytes never arrived via PHP's upload
        // machinery — without it isValid() fails and Statamic refuses them.
        return new UploadedFile($temp, $basename, test: true);
    }

    /**
     * The CP's own upload gate (AssetsController@store, 6.x): Statamic's
     * global AllowedFile denylist plus the container's configured rules.
     */
    private function validateAgainstContainerRules(AssetContainerContract $container, UploadedFile $file): void
    {
        $rules = collect($container->validationRules())
            ->map(fn ($rule) => FieldValidator::parse($rule))
            ->all();

        try {
            Validator::make(
                ['file' => $file],
                ['file' => array_merge(['file', new AllowedFile], $rules)],
            )->validate();
        } catch (ValidationException $e) {
            throw new ToolException('upload validation failed: '.json_encode(
                $e->errors(),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
        }
    }
}
```

In `src/Server.php`, append after `Tools\AssetsGet::class`:

```php
        Tools\AssetsUpload::class,
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/AssetsUploadTest.php`
Expected: PASS (12 tests).

- [ ] **Step 5: Format, full suite, commit**

```bash
composer format && composer test && vendor/bin/phpstan analyse
git add -A && git commit -m "feat: add assets_upload tool with base64 transport and CP-parity validation"
```

---

### Task 6: assets_upload — source_url transport

**Files:**
- Test: `tests/Feature/AssetsUploadUrlTest.php`

The tool code already branches to `SourceDownloader` (Task 5) and the downloader is fully unit-tested (Task 2) — this task proves the integration: container-bound download, filename derivation, SSRF errors surfacing as tool errors, and the size cap crossing both layers.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/AssetsUploadUrlTest.php`:

```php
<?php

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Support\SourceDownloader;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tools\AssetsUpload;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    // Public-IP resolver so no test touches real DNS.
    app()->instance(SourceDownloader::class, new SourceDownloader(fn (string $host) => ['93.184.216.34']));
});

it('downloads from a url and derives the filename from it', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');

    Http::fake(['https://images.example.com/*' => Http::response(Fixtures::tinyPng(), 200)]);

    Server::actingAs(Fixtures::makeUser('upload images assets'))
        ->tool(AssetsUpload::class, [
            'container' => 'images',
            'source_url' => 'https://images.example.com/photos/hero.png',
            'folder' => 'blog',
        ])
        ->assertOk()
        ->assertSee('"id":"images::blog/hero.png"')
        ->assertSee('"result":"uploaded — live"');

    expect(Storage::disk('images')->exists('blog/hero.png'))->toBeTrue();
});

it('lets an explicit filename override the url basename', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');

    Http::fake(['https://images.example.com/*' => Http::response(Fixtures::tinyPng(), 200)]);

    Server::actingAs(Fixtures::makeSuper())
        ->tool(AssetsUpload::class, [
            'container' => 'images',
            'source_url' => 'https://images.example.com/download?id=42',
            'filename' => 'hero.png',
        ])
        ->assertOk()
        ->assertSee('"id":"images::hero.png"');
});

it('asks for a filename when the url has no usable basename', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');

    Http::fake(['https://images.example.com/*' => Http::response(Fixtures::tinyPng(), 200)]);

    Server::actingAs(Fixtures::makeSuper())
        ->tool(AssetsUpload::class, [
            'container' => 'images',
            'source_url' => 'https://images.example.com/',
        ])
        ->assertHasErrors(['pass filename — it could not be derived from the source_url']);
});

it('surfaces SSRF refusals as tool errors and uploads nothing', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');

    Http::fake();

    Server::actingAs(Fixtures::makeSuper())
        ->tool(AssetsUpload::class, [
            'container' => 'images',
            'source_url' => 'http://169.254.169.254/latest/meta-data',
            'filename' => 'meta.txt',
        ])
        ->assertHasErrors(["source_url host '169.254.169.254' resolves to a private or reserved address — refusing to fetch"]);

    Http::assertNothingSent();
});

it('enforces max_size on downloads end-to-end', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');
    config(['statamic.mcp.uploads.max_size' => 1]); // 1 KB

    Http::fake(['https://images.example.com/*' => Http::response(str_repeat('x', 2048), 200)]);

    Server::actingAs(Fixtures::makeSuper())
        ->tool(AssetsUpload::class, [
            'container' => 'images',
            'source_url' => 'https://images.example.com/big.png',
        ])
        ->assertHasErrors(['source_url file exceeds the 1 KB limit (statamic.mcp.uploads.max_size)']);
});
```

- [ ] **Step 2: Run tests to verify they pass already or fail for a real reason**

Run: `vendor/bin/pest tests/Feature/AssetsUploadUrlTest.php`
Expected: PASS — the branch was implemented in Task 5. If anything fails, the failure is a genuine integration bug (e.g. the container binding not being used); fix the tool, not the test. One likely trip-wire: the literal-IP case must bypass the injected resolver (literal IPs never hit `$this->resolver` — see `SourceDownloader::resolve()`).

- [ ] **Step 3: Format, full suite, commit**

```bash
composer format && composer test && vendor/bin/phpstan analyse
git add -A && git commit -m "test: cover assets_upload source_url transport end-to-end"
```

---

### Task 7: assets_update

**Files:**
- Create: `src/Tools/AssetsUpdate.php`
- Modify: `src/Server.php`
- Test: `tests/Feature/AssetsUpdateTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/AssetsUpdateTest.php`:

```php
<?php

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tools\AssetsUpdate;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Statamic\Events\AssetSaving;
use Statamic\Facades\Asset;

beforeEach(function () {
    Fixtures::site();
    Fixtures::assetContainer('images');
    Storage::disk('images')->put('hero.png', Fixtures::tinyPng());
});

it('merges raw metadata and reports it live', function () {
    Asset::find('images::hero.png')->data(['alt' => 'Old alt'])->save();

    Server::actingAs(Fixtures::makeUser('edit images assets'))
        ->tool(AssetsUpdate::class, [
            'container' => 'images',
            'path' => 'hero.png',
            'data' => ['alt' => 'A better alt text'],
        ])
        ->assertOk()
        ->assertSee('"data":{"alt":"A better alt text"}')
        ->assertSee('"result":"updated — live"')
        ->assertSee('"cp_edit_url"');

    expect(Asset::find('images::hero.png')->data()->get('alt'))->toBe('A better alt text');
});

it('rejects unknown fields naming valid handles', function () {
    Server::actingAs(Fixtures::makeSuper())
        ->tool(AssetsUpdate::class, [
            'container' => 'images',
            'path' => 'hero.png',
            'data' => ['allt' => 'typo'],
        ])
        ->assertHasErrors(["unknown field allt — valid handles: alt — did you mean 'alt' instead of 'allt'?"]);
});

it('is a no-op when the merged data equals the current data', function () {
    Asset::find('images::hero.png')->data(['alt' => 'Same'])->save();

    Server::actingAs(Fixtures::makeSuper())
        ->tool(AssetsUpdate::class, [
            'container' => 'images',
            'path' => 'hero.png',
            'data' => ['alt' => 'Same'],
        ])
        ->assertOk()
        ->assertSee('no-op — merged data equals current data; nothing saved');
});

it('reports an AssetSaving listener cancellation honestly', function () {
    Event::listen(AssetSaving::class, fn () => false);

    Server::actingAs(Fixtures::makeSuper())
        ->tool(AssetsUpdate::class, [
            'container' => 'images',
            'path' => 'hero.png',
            'data' => ['alt' => 'New'],
        ])
        ->assertHasErrors(['the save was cancelled by a listener — the asset metadata was not updated']);
});

it('denies updating without the edit permission', function () {
    $user = Fixtures::makeUser('view images assets');

    Server::actingAs($user)
        ->tool(AssetsUpdate::class, [
            'container' => 'images',
            'path' => 'hero.png',
            'data' => ['alt' => 'New'],
        ])
        ->assertHasErrors(["requires 'edit images assets' — grant it to a role of {$user->email()} in the Control Panel"]);
});

it('blocks updates in read-only mode', function () {
    config(['statamic.mcp.read_only' => true]);

    Server::actingAs(Fixtures::makeSuper())
        ->tool(AssetsUpdate::class, [
            'container' => 'images',
            'path' => 'hero.png',
            'data' => ['alt' => 'New'],
        ])
        ->assertHasErrors(['writes are disabled on this server (statamic.mcp.read_only) — reads remain available']);
});

it('reports a missing path with a pointer to assets_list', function () {
    Server::actingAs(Fixtures::makeSuper())
        ->tool(AssetsUpdate::class, [
            'container' => 'images',
            'path' => 'nope.png',
            'data' => ['alt' => 'x'],
        ])
        ->assertHasErrors(["asset 'nope.png' not found in container 'images' — use assets_list to see available paths"]);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/AssetsUpdateTest.php`
Expected: FAIL — `Class "Danielgnh\StatamicMcp\Tools\AssetsUpdate" not found`.

- [ ] **Step 3: Implement the tool**

Create `src/Tools/AssetsUpdate.php`:

```php
<?php

namespace Danielgnh\StatamicMcp\Tools;

use Danielgnh\StatamicMcp\Tools\Concerns\ComparesPatchData;
use Danielgnh\StatamicMcp\Tools\Concerns\ResolvesAssets;
use Danielgnh\StatamicMcp\Tools\Concerns\ValidatesBlueprintData;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('assets_update')]
#[Description("Update an asset's metadata (alt text and any custom fields on the container's asset blueprint) with a shallow top-level-key merge of raw data (the assets_get raw shape) — nested structures are replaced wholesale; explicit null clears a field. The file itself is untouched. An update that changes nothing is a no-op. Assets have no draft state: saved metadata is live immediately. Never send augmented data.")]
#[IsIdempotent]
class AssetsUpdate extends Tool
{
    use ComparesPatchData;
    use ResolvesAssets;
    use ValidatesBlueprintData;

    public function schema(JsonSchema $schema): array
    {
        return [
            'container' => $schema->string()->description('Asset container handle — see statamic_overview.')->required(),
            'path' => $schema->string()->description("Asset path relative to the container root, e.g. 'blog/hero.jpg'.")->required(),
            'data' => $schema->object()->description('Raw metadata to merge, keyed by blueprint field handle, e.g. {"alt": "…"}.')->required(),
        ];
    }

    public function shouldRegister(Request $request): bool
    {
        return $this->writesEnabled();
    }

    protected function execute(Request $request): Response
    {
        // Re-check the registration gate: stale client tool caches are a
        // documented UX wart, not a security hole (spec §6 layer 1).
        $this->ensureWritesEnabled();

        // laravel/mcp doesn't enforce the JSON schema server-side (T10) —
        // validate shapes before touching them.
        $validated = $request->validate(
            [
                'container' => 'required|string',
                'path' => 'required|string',
                'data' => 'required|array',
            ],
            [
                'container.required' => 'Pass an asset container handle, e.g. "images" — see statamic_overview.',
                'data.required' => 'Pass raw metadata to merge, e.g. {"alt": "A description of the image"}.',
            ],
        );

        $handle = $validated['container'];
        $patch = $validated['data'];

        $container = $this->resolveContainer($handle);

        $user = $this->user($request);
        $this->ensurePermission($user, "edit {$handle} assets");

        $asset = $this->resolveAsset($container, $validated['path']);

        // supportsFields: false — assets_get has no fields parameter, the
        // remediation text must not invent one.
        $this->rejectPreviewObjects($patch, 'assets_get', supportsFields: false);

        // Assets always have a blueprint (Statamic falls back to a default
        // one with alt) — unknown keys are rejected, typos never become meta.
        $blueprint = $asset->blueprint();
        $this->rejectUnknownKeys($blueprint, $patch);

        $existing = $asset->data()->all();
        $merged = array_merge($existing, $patch);

        // Strict compare over normalized values (T14 pattern): assoc key
        // order is irrelevant, but types matter — loose == would turn an
        // explicit null-clear of a falsy value into a false no-op.
        if ($this->normalize($merged) === $this->normalize($existing)) {
            return $this->json([
                'id' => $asset->id(),
                'path' => $asset->path(),
                'result' => 'no-op — merged data equals current data; nothing saved',
                'cp_edit_url' => $asset->editUrl(),
            ]);
        }

        $this->validateAgainstBlueprint($blueprint, $merged);

        // save() returns false when an AssetSaving listener cancels
        // (approval addons do this) — never report success for it.
        if (! $asset->data($merged)->save()) {
            throw new ToolException('the save was cancelled by a listener — the asset metadata was not updated');
        }

        return $this->json([
            'id' => $asset->id(),
            'path' => $asset->path(),
            'data' => $asset->data()->all(),
            ...$this->liveness($asset, self::LIVENESS_LIVE),
        ]);
    }
}
```

In `src/Server.php`, append after `Tools\AssetsUpload::class`:

```php
        Tools\AssetsUpdate::class,
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/AssetsUpdateTest.php`
Expected: PASS (7 tests).

- [ ] **Step 5: Format, full suite, commit**

```bash
composer format && composer test && vendor/bin/phpstan analyse
git add -A && git commit -m "feat: add assets_update tool for blueprint-validated metadata"
```

---

### Task 8: assets_delete

**Files:**
- Create: `src/Tools/AssetsDelete.php`
- Modify: `src/Server.php`
- Test: `tests/Feature/AssetsDeleteTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/AssetsDeleteTest.php`:

```php
<?php

use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\Tests\Support\Fixtures;
use Danielgnh\StatamicMcp\Tools\AssetsDelete;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Statamic\Events\AssetDeleting;
use Statamic\Facades\Asset;

beforeEach(function () {
    Fixtures::site();
    Fixtures::assetContainer('images');
    Storage::disk('images')->put('hero.png', Fixtures::tinyPng());
    config(['statamic.mcp.deletes' => true]);
});

it('deletes the file and its metadata', function () {
    // Force a .meta.yaml onto disk so the test proves both files go.
    Asset::find('images::hero.png')->data(['alt' => 'x'])->save();
    expect(Storage::disk('images')->exists('.meta/hero.png.yaml'))->toBeTrue();

    Server::actingAs(Fixtures::makeUser('delete images assets'))
        ->tool(AssetsDelete::class, ['container' => 'images', 'path' => 'hero.png'])
        ->assertOk()
        ->assertSee('"deleted":true')
        ->assertSee('"id":"images::hero.png"')
        ->assertSee('asset permanently deleted');

    expect(Storage::disk('images')->exists('hero.png'))->toBeFalse();
    expect(Storage::disk('images')->exists('.meta/hero.png.yaml'))->toBeFalse();
});

it('is hidden and blocked when deletes are disabled', function () {
    config(['statamic.mcp.deletes' => false]);

    Server::actingAs(Fixtures::makeSuper())
        ->tool(AssetsDelete::class, ['container' => 'images', 'path' => 'hero.png'])
        ->assertHasErrors(['delete tools are disabled on this server (statamic.mcp.deletes)']);

    expect(Storage::disk('images')->exists('hero.png'))->toBeTrue();
});

it('is blocked by read_only even with deletes enabled', function () {
    config(['statamic.mcp.read_only' => true]);

    Server::actingAs(Fixtures::makeSuper())
        ->tool(AssetsDelete::class, ['container' => 'images', 'path' => 'hero.png'])
        ->assertHasErrors(['writes are disabled on this server (statamic.mcp.read_only) — reads remain available']);
});

it('reports an AssetDeleting listener cancellation honestly', function () {
    Event::listen(AssetDeleting::class, fn () => false);

    Server::actingAs(Fixtures::makeSuper())
        ->tool(AssetsDelete::class, ['container' => 'images', 'path' => 'hero.png'])
        ->assertHasErrors(['the delete was cancelled by a listener — the asset was not deleted']);

    expect(Storage::disk('images')->exists('hero.png'))->toBeTrue();
});

it('denies deleting without the delete permission', function () {
    $user = Fixtures::makeUser('edit images assets');

    Server::actingAs($user)
        ->tool(AssetsDelete::class, ['container' => 'images', 'path' => 'hero.png'])
        ->assertHasErrors(["requires 'delete images assets' — grant it to a role of {$user->email()} in the Control Panel"]);
});

it('reports a missing path with a pointer to assets_list', function () {
    Server::actingAs(Fixtures::makeSuper())
        ->tool(AssetsDelete::class, ['container' => 'images', 'path' => 'nope.png'])
        ->assertHasErrors(["asset 'nope.png' not found in container 'images' — use assets_list to see available paths"]);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/AssetsDeleteTest.php`
Expected: FAIL — `Class "Danielgnh\StatamicMcp\Tools\AssetsDelete" not found`.

- [ ] **Step 3: Implement the tool**

Create `src/Tools/AssetsDelete.php`:

```php
<?php

namespace Danielgnh\StatamicMcp\Tools;

use Danielgnh\StatamicMcp\Tools\Concerns\ResolvesAssets;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('assets_delete')]
#[Description("Permanently delete an asset — the file AND its metadata. References to it in entry fields are removed by Statamic's reference updater (runs on the queue; skipped when statamic.system.update_references is false). This cannot be undone. Only available when deletes are enabled in config/statamic/mcp.php.")]
#[IsDestructive]
class AssetsDelete extends Tool
{
    use ResolvesAssets;

    public function schema(JsonSchema $schema): array
    {
        return [
            'container' => $schema->string()->description('Asset container handle — see statamic_overview.')->required(),
            'path' => $schema->string()->description("Asset path relative to the container root, e.g. 'blog/hero.jpg'.")->required(),
        ];
    }

    public function shouldRegister(Request $request): bool
    {
        return $this->deletesEnabled();
    }

    protected function execute(Request $request): Response
    {
        // Re-check the registration gate: stale client tool caches are a
        // documented UX wart, not a security hole (spec §6 layer 1).
        $this->ensureDeletesEnabled();

        $validated = $request->validate(
            [
                'container' => 'required|string',
                'path' => 'required|string',
            ],
            [
                'container.required' => 'Pass an asset container handle, e.g. "images" — see statamic_overview.',
                'path.required' => "Pass an asset path relative to the container root, e.g. 'blog/hero.jpg'.",
            ],
        );

        $handle = $validated['container'];
        $container = $this->resolveContainer($handle);

        $user = $this->user($request);
        $this->ensurePermission($user, "delete {$handle} assets");

        $asset = $this->resolveAsset($container, $validated['path']);

        $id = $asset->id();

        // delete() returns false when an AssetDeleting listener cancels
        // (approval addons do this) — never report success for it.
        if (! $asset->delete()) {
            throw new ToolException('the delete was cancelled by a listener — the asset was not deleted');
        }

        // Outcome statement only — deliberately NO cp_edit_url: the deleted
        // asset's CP page would 404 (amended spec exception, same as deletes
        // elsewhere).
        return $this->json([
            'deleted' => true,
            'id' => $id,
            'container' => $handle,
            'path' => $asset->path(),
            'result' => 'asset permanently deleted — file and metadata removed; this cannot be undone',
            'note' => "references to this asset in entry fields are removed by Statamic's reference updater (runs on the queue; skipped when statamic.system.update_references is false) — an immediate re-read may still show the path in entry fields; do not rewrite references manually",
        ]);
    }
}
```

In `src/Server.php`, append after `Tools\AssetsUpdate::class`:

```php
        Tools\AssetsDelete::class,
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/AssetsDeleteTest.php`
Expected: PASS (6 tests).

- [ ] **Step 5: Format, full suite, commit**

```bash
composer format && composer test && vendor/bin/phpstan analyse
git add -A && git commit -m "feat: add config-gated assets_delete tool"
```

---

### Task 9: statamic_overview — asset_containers section

**Files:**
- Modify: `src/Tools/StatamicOverview.php`
- Test: `tests/Feature/StatamicOverviewTest.php` (extend, don't rewrite)

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/StatamicOverviewTest.php` (match its existing imports; add `Danielgnh\StatamicMcp\Tools\StatamicOverview` / `Fixtures` if missing):

```php
it('lists exposed asset containers with capability flags', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');

    $user = Fixtures::makeUser('view images assets', 'upload images assets');

    Server::actingAs($user)
        ->tool(StatamicOverview::class, [])
        ->assertOk()
        ->assertSee('"asset_containers"')
        ->assertSee('"handle":"images"')
        ->assertSee('"can_upload":true')
        ->assertSee('"can_edit":false')
        ->assertDontSee('"can_delete"'); // deletes disabled by default
});

it('hides asset containers the user cannot view and includes can_delete when deletes are enabled', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');
    Fixtures::assetContainer('private');
    config(['statamic.mcp.deletes' => true]);

    $user = Fixtures::makeUser('view images assets', 'delete images assets');

    Server::actingAs($user)
        ->tool(StatamicOverview::class, [])
        ->assertOk()
        ->assertSee('"handle":"images"')
        ->assertSee('"can_delete":true')
        ->assertDontSee('"handle":"private"');
});

it('omits unexposed asset containers entirely', function () {
    Fixtures::site();
    Fixtures::assetContainer('images');
    config(['statamic.mcp.resources.asset_containers' => []]);

    Server::actingAs(Fixtures::makeSuper())
        ->tool(StatamicOverview::class, [])
        ->assertOk()
        ->assertSee('"asset_containers":[]');
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/StatamicOverviewTest.php`
Expected: the three new tests FAIL (no `asset_containers` key in the response); existing tests still pass.

- [ ] **Step 3: Implement**

In `src/Tools/StatamicOverview.php`:

1. Add import: `use Statamic\Facades\AssetContainer;`
2. Update the `#[Description]` to mention containers — replace the existing string with:

```php
#[Description('Start here — zero parameters. Returns the sites; the collections, taxonomies, global sets, and asset containers exposed to MCP and visible to you; your capability flags per resource (can_create, can_edit, can_publish, can_upload, can_delete — delete flags appear only when deletes are enabled); the acting user (email, roles, is_super); and server flags (read_only, deletes).')]
```

3. In `execute()`, add after the `'globals'` line:

```php
            'asset_containers' => $this->assetContainers($user),
```

4. Add the private method after `globals()`:

```php
    private function assetContainers(UserContract $user): array
    {
        $containers = AssetContainer::all()->keyBy->handle();

        return $this->sortedExposed('asset_containers')
            ->filter(fn (string $handle) => $this->can($user, "view {$handle} assets"))
            ->map(function (string $handle) use ($containers, $user) {
                $container = $containers->get($handle);

                $resource = [
                    'handle' => $handle,
                    'title' => $container->title(),
                    'can_upload' => $this->can($user, "upload {$handle} assets"),
                    'can_edit' => $this->can($user, "edit {$handle} assets"),
                ];

                if ($this->deletesEnabled()) {
                    $resource['can_delete'] = $this->can($user, "delete {$handle} assets");
                }

                return $resource;
            })
            ->values()
            ->all();
    }
```

5. In `src/Server.php`, extend the `#[Instructions]` string — replace it with:

```php
#[Instructions('MCP server for this Statamic site. Call statamic_overview first: it returns the sites, collections, taxonomies, global sets, and asset containers you can work with, plus your own permission flags per resource. Before creating or updating content, call blueprints_get for the target blueprint — writes accept raw field data only (never augmented data). Writes save drafts by default; publishing requires an explicit published: true and the matching Statamic permission. Asset uploads are live immediately — set alt text with assets_update after uploading.')]
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/StatamicOverviewTest.php`
Expected: PASS (all, including the three new tests).

- [ ] **Step 5: Format, full suite, commit**

```bash
composer format && composer test && vendor/bin/phpstan analyse
git add -A && git commit -m "feat: surface asset containers and capability flags in statamic_overview"
```

---

### Task 10: blueprints_get — actionable assets fieldtype example

**Files:**
- Modify: `src/Tools/BlueprintsGet.php`
- Test: `tests/Feature/BlueprintsGetTest.php` (extend, don't rewrite)

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/BlueprintsGetTest.php` (reuse its existing imports/fixture style; it already builds blueprints via `Statamic\Facades\Blueprint::makeFromFields`):

```php
it('gives assets fields an actionable example pointing at the assets tools', function () {
    Fixtures::site();
    Fixtures::blog();

    Statamic\Facades\Blueprint::makeFromFields([
        'title' => ['type' => 'text'],
        'hero' => ['type' => 'assets', 'container' => 'images', 'max_files' => 1],
        'gallery' => ['type' => 'assets', 'container' => 'images'],
    ])->setHandle('article')->setNamespace('collections.blog')->save();

    Server::actingAs(Fixtures::makeSuper())
        ->tool(BlueprintsGet::class, ['type' => 'collection', 'handle' => 'blog'])
        ->assertOk()
        // max_files 1 stores a single string; multi stores a list.
        ->assertSee('"hero":"REPLACE-WITH-REAL-ASSET-PATH"')
        ->assertSee('"gallery":["REPLACE-WITH-REAL-ASSET-PATH"]')
        ->assertSee("container 'images'")
        ->assertSee('assets_list')
        ->assertSee('assets_upload');
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/pest tests/Feature/BlueprintsGetTest.php`
Expected: the new test FAILS (assets currently falls to the null-plus-note default); existing tests pass.

- [ ] **Step 3: Implement**

In `src/Tools/BlueprintsGet.php`, add a match arm in `exampleFor()` directly above the `default` arm:

```php
            'assets' => $this->assetsFieldExample($field),
```

Add the private method after `firstOption()`:

```php
    /**
     * Assets fields store paths relative to the field's container root —
     * a single string when max_files is 1, a list otherwise (vendor
     * Fieldtypes\Assets::process). Point the agent at the assets tools
     * instead of the generic null fallback.
     *
     * @return array{0: mixed, 1: string}
     */
    private function assetsFieldExample(Field $field): array
    {
        $container = $field->config()['container'] ?? null;
        $single = (int) ($field->config()['max_files'] ?? 0) === 1;

        $note = sprintf(
            'stores asset paths relative to the container root%s — %s. Find existing paths with assets_list, or upload new files with assets_upload, then use the returned path (not the id or url).',
            $container ? sprintf(" (container '%s')", $container) : ' (no container configured on the field — statamic_overview lists the available ones)',
            $single ? 'max_files is 1, so pass a single string path' : 'pass a list of path strings',
        );

        return [$single ? 'REPLACE-WITH-REAL-ASSET-PATH' : ['REPLACE-WITH-REAL-ASSET-PATH'], $note];
    }
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/pest tests/Feature/BlueprintsGetTest.php`
Expected: PASS.

- [ ] **Step 5: Format, full suite, commit**

```bash
composer format && composer test && vendor/bin/phpstan analyse
git add -A && git commit -m "feat: actionable assets fieldtype example in blueprints_get"
```

---

### Task 11: Docs and final verification

**Files:**
- Modify: `README.md`
- Modify: `CHANGELOG.md`

- [ ] **Step 1: README**

Follow the README's existing structure (tools table + config docs). Add the five tools to the tools table with one-line descriptions matching their `#[Description]` texts. Add a section after the existing config documentation:

```markdown
### Asset uploads

`assets_upload` accepts a `source_url` (the server downloads the file) or inline `content_base64` (small files). Two config keys govern uploads:

```php
'uploads' => [
    // Hard per-upload cap in kilobytes, for both transports.
    'max_size' => 10240,

    // Exact-host allowlist for source_url. null = any public host.
    'source_allowlist' => null, // e.g. ['images.unsplash.com']
],
```

**SSRF policy (fail-closed).** `source_url` fetching only allows `http`/`https`, resolves DNS itself and refuses any host with a private, loopback, link-local, CGN, or otherwise reserved address (IPv4 and IPv6), pins the connection to the validated IP, revalidates every redirect hop (max 3), and aborts downloads that exceed `max_size` — `Content-Length` is not trusted. Set `source_allowlist` to pin uploads to known hosts; container-level validation rules apply on top, exactly as in the Control Panel.

Container exposure works like every other resource: `'resources' => ['asset_containers' => true]` (or an array of handles). Upgrading from v1.0? Re-publish the config or add the key — a missing `asset_containers` key exposes nothing.
```

- [ ] **Step 2: CHANGELOG**

Add under a new `## Unreleased` heading (or the repo's current pattern):

```markdown
### Added
- Assets tools: `assets_list`, `assets_get`, `assets_upload` (source_url with fail-closed SSRF guards, or content_base64), `assets_update` (blueprint-validated metadata), and `assets_delete` (config-gated).
- `resources.asset_containers` and `uploads` (`max_size`, `source_allowlist`) config keys.
- `statamic_overview` now reports exposed asset containers with capability flags; `blueprints_get` gives assets fields an actionable example.
```

- [ ] **Step 3: Full verification**

```bash
composer format
vendor/bin/phpstan analyse
composer test
```

Expected: Pint clean, PHPStan clean, full suite green. If anything fails, fix before committing.

- [ ] **Step 4: Commit**

```bash
git add -A && git commit -m "docs: document assets tools, uploads config, and SSRF policy"
```

---

## Plan self-review notes (already applied)

- **Spec coverage:** §3 tools → Tasks 3–8; §4 config → Task 1; §5 SSRF → Task 2 (+ Task 6 integration); statamic_overview → Task 9; blueprints_get → Task 10; §6 tests distributed per task; §7 docs → Task 11. `liveness()` union + `LIVENESS_UPLOADED` → Task 1.
- **Spec §3 delete note** was corrected in the spec on 2026-07-11: Statamic's `UpdateAssetReferences` DOES clean references on `AssetDeleted` — Task 8's description/note reflect vendor reality.
- **Type consistency:** `resolveContainer()`/`resolveAsset()`/`normalizeFolder()`/`assetSummary()` defined once in Task 3's concern and used with those exact names in Tasks 4–8; `SourceDownloader::download()` returns `[string $contents, string $basename]` in both Task 2 and Task 5's call site.
- **Known judgment calls:** body buffered in memory (bounded by `max_size`, keeps `Http::fake()` testability); upload requires only `upload {container} assets` even for new folders (folders are implicit on flysystem; the CP's folder-create gate is a CP tree concern); `assets_list` has no `search` param (paths are the search surface; YAGNI until pressure).
