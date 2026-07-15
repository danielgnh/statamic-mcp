# Releasing — human checklist

Everything below requires accounts, credentials, or a browser and is deliberately
NOT automated. Applies to every release; the one-time setup steps live at the
bottom.

Versioning while pre-1.0: new features bump the minor (`0.x.0`), fixes bump the
patch (`0.x.y`). Breaking changes may land in minors but must be called out in
the CHANGELOG. `v1.0.0` marks the first stable release.

## 0. Preflight

- All local gates green: `composer test` (Rector dry-run, Pint, PHPStan, Pest —
  the full suite, currently ~520 tests, must pass with zero failures).
- CHANGELOG updated: move the `[Unreleased]` entries under a new
  `## [X.Y.Z] - YYYY-MM-DD` heading and add the compare link in the footer.
- For OAuth-affecting changes, `php please mcp:doctor` on a host site is green.

## 1. Inspector smoke test (before tagging)

The MCP Inspector is an interactive browser UI (`npx @modelcontextprotocol/inspector`),
so it cannot run in this package repo headlessly. What is already verified by the
suite: the full protocol path over real HTTP — `initialize`, `tools/list`
(**9** tools in `read_only`, **16** by default, **19** with deletes enabled), and
`tools/call` through the complete auth middleware pipeline — is pinned by
`tests/Feature/ReadOnlyModeTest.php` and `tests/Feature/TokenModeUnaffectedTest.php`.

Do the interactive pass on a host Statamic 6 site with the addon installed:

```bash
composer require danielgnh/statamic-mcp
php please mcp:token you@site.com         # copy the printed token
php artisan mcp:inspector mcp/statamic
```

In the Inspector UI: transport **Streamable HTTP**, URL `http://<host>/mcp/statamic`,
add header `Authorization: Bearer <token>`. Expected: `initialize` completes with
serverInfo name `Statamic`; `tools/list` returns 16 tools (default config — the
three delete tools are hidden under `'deletes' => false`; only 9 read tools under
`read_only`); one `statamic_overview` call returns sites, resources, capability
flags, and server flags. Do not tag until this passes.

## 2. Tag and GitHub release

```bash
git tag vX.Y.Z
git push origin main vX.Y.Z
```

Verify the Actions run for the push/tag is green — **11 jobs**: 8 test-matrix legs
(PHP 8.3/8.4 × Laravel 12/13 × prefer-lowest/prefer-stable) + Pint + PHPStan +
Rector. Every leg runs the full OAuth coverage (Passport is a dev dependency).
Then:

```bash
gh release create vX.Y.Z --title "vX.Y.Z" --notes "<the CHANGELOG section for this version>"
```

## 3. Verify propagation

- Packagist picks the tag up via the GitHub webhook — confirm the new version
  appears on https://packagist.org/packages/danielgnh/statamic-mcp within a few
  minutes (re-trigger with **Update** on the package page if not).
- Smoke-test the install path on any Statamic 6 site:
  `composer require danielgnh/statamic-mcp` → `php please mcp:doctor` prints
  the endpoint.

## One-time setup (first release only)

1. **Packagist**: log in at https://packagist.org (GitHub login as `danielgnh`),
   submit `https://github.com/danielgnh/statamic-mcp`, and enable auto-updates —
   the package page warns if the GitHub hook is missing; follow its link, or on
   GitHub check Settings → Webhooks for the Packagist hook.
2. **Statamic Marketplace**: log in at https://statamic.com, open the **Seller
   dashboard** (create the seller account `danielgnh` first if none exists) →
   **New product** → type **Addon** → Packagist package `danielgnh/statamic-mcp`.
   The Marketplace pulls the name/description from `composer.json`
   `extra.statamic` ("Statamic MCP") and renders the README as the listing body.
   Set price **Free**, Statamic compatibility **6.x**, add the GitHub repo URL
   and tags (e.g. "MCP", "AI", "API"). Submit for review; after it is live,
   install once following the listing's instructions verbatim — the listing,
   README, and reality must agree.
