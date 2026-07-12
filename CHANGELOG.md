# Changelog

All notable changes to `danielgnh/statamic-mcp` are documented here. The format
follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project
adheres to [Semantic Versioning](https://semver.org).

## [Unreleased]

### Added

- Assets tools: `assets_list`, `assets_get`, `assets_upload`, `assets_update`,
  and `assets_delete` (delete gated behind `'deletes' => true` like the other
  delete tools). Uploads accept a `source_url` — downloaded server-side behind
  a fail-closed SSRF guard (public hosts only, DNS pinning, per-hop redirect
  revalidation, streaming size cap) — or inline `content_base64`; collisions
  are refused, never overwritten. `assets_update` merges blueprint-validated
  metadata (alt text etc.) and passes the CP's `focus` key through for
  lossless round-trips.
- `resources.asset_containers` exposure key and an `uploads` config block
  (`max_size` in KB, `source_allowlist`). A previously published config
  without the new key exposes no containers — add it or re-publish.
- `statamic_overview` now lists exposed asset containers with `can_upload` /
  `can_edit` / `can_delete` flags; `blueprints_get` gives assets fields an
  actionable example naming the container and the assets tools.
- `tools/list` now returns the full tool set in one page (the default
  15-per-page cut-off would hide tools from clients that never paginate).
- MCP Tokens utility — issue and revoke your own tokens from the Control Panel
  (Tools → Utilities), gated by the "Access MCP Tokens utility" permission.
  Super admins see all tokens.

### Changed

- Token-store writes are serialized behind an atomic lock, making concurrent
  CLI + CP issuance/revocation safe.
- The MCP Tokens utility is rebuilt on Statamic v6's UI component kit
  (`ui-panel`, `ui-card`, `ui-alert`, `ui-badge`, `ui-field`, `ui-input`,
  `ui-button`) — native CP look including dark mode, a copy-to-clipboard
  button on the one-time token reveal, and toast notifications instead of
  inline flash banners.

### Fixed

- User-controlled strings on the MCP Tokens utility page (token names, owner
  emails) are rendered inertly for the CP's runtime Vue template compiler
  (`v-pre`) — previously a token name containing Vue interpolation syntax
  would execute as an expression in the viewing admin's session.

## [1.0.0] - 2026-07-10

### Added

- Remote (streamable-HTTP) MCP server for Statamic v6, built on `laravel/mcp`,
  mounted at `mcp/statamic` (configurable).
- 14 tools: `statamic_overview`, `blueprints_get`,
  `entries_list` / `entries_get` / `entries_create` / `entries_update` / `entries_delete`,
  `terms_list` / `terms_get` / `terms_create` / `terms_update` / `terms_delete`,
  `globals_get`, `globals_update`. Under the zero-config default 12 are
  advertised (the two delete tools require `'deletes' => true`); under
  `read_only` only the seven read tools appear.
- Token auth mode (default): `mcp_{tokenId}_{secret}` tokens hashed (SHA-256)
  into `storage/statamic/mcp/tokens.yaml` — works on file-based and Eloquent
  user installs. Commands: `mcp:token`, `mcp:tokens`, `mcp:token:revoke`.
  Every authentication failure answers one indistinguishable 401 — including
  hand-edited records that lost their hash key (which always reject, never
  degrading to a guessable password).
- OAuth auth mode (opt-in): delegates entirely to `laravel/mcp` + Laravel
  Passport; misconfiguration answers 503 with the exact remedy on the MCP
  route only — token mode never touches Passport.
- `php please mcp:doctor` configuration health check with remedies, covering
  both auth modes and the "enabled but failed to mount" state.
- Authorization via Statamic's native permission system on every call — one
  addon permission (`access mcp`), then the connected user's regular
  collection/taxonomy/global/site permissions decide everything else. Writes
  save drafts by default; any publish-state transition is gated on the
  publish permission; revision-enabled collections stage working copies
  instead of touching live entries (publish from the Control Panel).
- Raw-data write model: writes accept raw field data validated against the
  blueprint (CP-parity validation replacements), never augmented output.
  Long Bard/rich-text values are truncated to preview objects on read and
  rejected if round-tripped into a write.
- Origin-cascade entry deletes with per-localization site gating and full
  enumeration of what was removed; term deletes update entry references
  (single and multi-term fields) like the CP does.
- Config: kill switch, route, auth mode, extra middleware (default
  `throttle:60,1`), `read_only`, `deletes` (off by default), per-type
  resource exposure allowlists, `per_page`.

### Known caveats

- `laravel/mcp` is required at `^0.8` — a pre-1.0 release line. Its API may
  change in 0.x minors; this addon pins `^0.8` and will track upstream in
  its own minor releases.
- Under multisite, the default site is never gated by `access {site} site`
  (documented in the README's security model; CP-parity review is a v1.1
  candidate).
- Deleting an entry from a revision-enabled collection leaves its revision
  and working-copy files on disk as orphans — the Control Panel behaves the
  same way.
- OAuth mode ships without a Passport-installed CI leg in this release; the
  token mode pipeline is fully integration-tested. See the workflow notes in
  `.github/workflows/tests.yml`.

[Unreleased]: https://github.com/danielgnh/statamic-mcp/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/danielgnh/statamic-mcp/releases/tag/v1.0.0
