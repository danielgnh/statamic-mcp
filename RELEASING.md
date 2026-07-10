# Releasing v1.0.0 — human checklist

Everything below requires accounts, credentials, or a browser and is deliberately
NOT automated. The last commit on this branch is the release-ready state: all
local gates were green at commit time (`vendor/bin/pest` — 339 tests,
`vendor/bin/pint --test`, `vendor/bin/phpstan analyse --no-progress --memory-limit=1G`).

## 0. Inspector smoke test (before tagging)

The MCP Inspector is an interactive browser UI (`npx @modelcontextprotocol/inspector`),
so it cannot run in this package repo headlessly. What is already verified:

- `mcp:inspector` command registration and web-server handle resolution for
  `mcp/statamic` were confirmed through the test harness's console kernel.
- The full protocol path over real HTTP — `initialize`, `tools/list` (7 tools
  in `read_only`, 12 by default, 14 with deletes enabled), and `tools/call`
  through the complete auth middleware pipeline — is pinned by
  `tests/Feature/ReadOnlyModeTest.php` and `tests/Feature/TokenModeUnaffectedTest.php`.

Do the interactive pass on a host Statamic 6 site with the addon installed:

```bash
composer require danielgnh/statamic-mcp   # or a path repository pre-Packagist
php please mcp:token you@site.com         # copy the printed token
php artisan mcp:inspector mcp/statamic
```

In the Inspector UI: transport **Streamable HTTP**, URL `http://<host>/mcp/statamic`,
add header `Authorization: Bearer <token>`. Expected: `initialize` completes with
serverInfo name `Statamic`; `tools/list` returns 12 tools (default config — the two
delete tools are hidden under `'deletes' => false`; only 7 read tools under
`read_only`); one `statamic_overview` call returns sites, resources, capability
flags, and server flags. Do not tag until this passes.

## 1. Push, tag, GitHub release

Create the GitHub repo if it does not exist yet, then merge/push this branch to
`main`:

```bash
gh repo create danielgnh/statamic-mcp --public --source=. --push
git tag v1.0.0
git push origin main --tags
```

Verify the Actions run for the push/tag is green — **10 jobs**: 8 test-matrix legs
(PHP 8.3/8.4 x Laravel 12/13 x prefer-lowest/prefer-stable) + Pint + PHPStan.
Then:

```bash
gh release create v1.0.0 --title "v1.0.0" --notes "First stable release. See CHANGELOG.md for the full feature list."
```

## 2. Publish on Packagist

1. Log in at https://packagist.org (GitHub login as `danielgnh`).
2. Go to https://packagist.org/packages/submit, enter
   `https://github.com/danielgnh/statamic-mcp`, click **Check** → **Submit**.
3. Confirm the package page shows `v1.0.0` and the `statamic/cms ^6.0`,
   `laravel/mcp ^0.8` requirements.
4. Enable auto-updates: the package page warns if the GitHub hook is missing —
   follow its link, or on GitHub check Settings → Webhooks for the Packagist
   hook. Push a README typo fix later to verify the hook fires.
5. Smoke-test the install path on any Statamic 6 site:
   `composer require danielgnh/statamic-mcp` → `php please mcp:doctor` prints
   the endpoint.

## 3. List on the Statamic Marketplace

1. Log in at https://statamic.com and open the **Seller dashboard** (create the
   seller account `danielgnh` first if none exists: statamic.com → Sell →
   create seller profile).
2. **New product** → type **Addon** → Packagist package: `danielgnh/statamic-mcp`.
   The Marketplace pulls the name/description from `composer.json`
   `extra.statamic` ("Statamic MCP") and renders the README as the listing body.
3. Set price **Free**, Statamic version compatibility **6.x**, add the GitHub
   repo URL and tags (e.g. "MCP", "AI", "API").
4. Submit for review. The Statamic team reviews new listings manually — respond
   to any feedback, then confirm the listing is live at statamic.com/addons.
5. After it is live: install once following the Marketplace listing's
   instructions verbatim (fresh site, copy-paste the quickstart) — the listing,
   README, and reality must agree.
