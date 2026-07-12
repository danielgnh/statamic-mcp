# Design: Assets Tools for danielgnh/statamic-mcp (v1.1)

**Date:** 2026-07-11
**Status:** Approved by owner (design gate passed); pending spec review
**Parent spec:** `2026-07-08-statamic-mcp-design.md` — assets were an explicit v1 non-goal (§1); this spec brings them in as the first v1.1 feature. Everything in the parent spec (auth, exposure model, permission model, error conventions, response conventions) applies unchanged; this document only specifies the delta.

## 1. What this is

Five new tools — `assets_list`, `assets_get`, `assets_upload`, `assets_update`, `assets_delete` — so an agent can run a blog workflow end-to-end: find existing images, upload new ones, set alt text, and reference them from an entry's assets field. Same guiding principle as v1: small boring surface, one config file, the connected user's real Statamic permissions decide everything.

### Decision log (owner-approved, 2026-07-11)

| Decision | Choice |
|---|---|
| Upload transport | Both `source_url` (server-side download with SSRF guards) and `content_base64` (small files) |
| Tool surface | Full set: list, get, upload, update (metadata), delete |
| Folders | Optional `folder` param on upload, folder filter on list; created on demand |
| Rename / move | **Non-goal for v1.1** — distinct Statamic permissions, no blog-flow pressure |
| Upload metadata | Not accepted on `assets_upload` — upload then `assets_update`, matching the CP's own two-step flow |
| Overwrite on collision | Refused with a targeted error naming the existing asset — never silent |

### Explicit non-goals

Rename, move, folder CRUD as first-class tools, `reupload` (replace file contents), focal-point editing, Glide preset generation, downloading asset bytes back to the client. All fine as v1.2+ candidates under pressure.

## 2. Verified foundation facts (vendored statamic/cms v6 source, 2026-07-11)

- **Permission tree** (`Auth/CorePermissions.php`): `view {container} assets` → `upload {container} assets`, `edit {container} assets` → children `move/rename/delete {container} assets`. Registered per container handle, same shape as collections — `ensurePermission()` works as-is.
- **Upload path** (`Assets/Asset.php::upload(UploadedFile)`): dispatches cancellable `AssetCreating` (returns `false` on cancellation — must be reported honestly, same as `entries_create`'s listener-cancelled save), runs the `Uploader` (filename sanitization, SVG sanitization per `statamic.assets.svg_sanitization_on_upload`), saves, writes `.meta.yaml`, dispatches `AssetUploaded` + `AssetCreated`. Using it = full CP parity.
- **Container API** (`Assets/AssetContainer.php`): `validationRules()` (site-configured Laravel rules applied to CP uploads — e.g. `mimes:jpg,png`, `max:...`), `makeAsset($path)`, `editUrl()`. `Asset::editUrl()` also exists → liveness blocks work unchanged. **Correction (2026-07-11, Task 5):** `allowUploads()` does NOT exist in v6 — it survives only as a string in `queryableMethods()`; the CP's sole upload gate is `AssetPolicy::store` = the `upload {container} assets` permission. No per-container toggle exists or is needed.
- **Asset identity**: `container::path` is the canonical id; the assets *fieldtype* stores paths **relative to the container root** (e.g. `['blog/hero.jpg']`) since a field is bound to one container.
- **No draft state**: assets are live on save — liveness follows the terms/globals convention (`LIVENESS_LIVE`-style constants).

## 3. Tool surface

All five extend the existing base `Tool`: `ensureExposed()`, `user()`, `ensurePermission()`, `ensureWritesEnabled()`/`ensureDeletesEnabled()`, `ToolException` → `Response::error`, compact JSON via `json()`. Handles are parameters; total server tool count rises to 19 — still under the ~15-per-registration guidance once `read_only`/`deletes` gating hides write tools on locked-down servers, and acceptable regardless (the ceiling is a soft accuracy heuristic; symmetric naming keeps selection reliable).

| Tool | Params | Permission | Registration gate | Annotation |
|---|---|---|---|---|
| `assets_list` | `container` (req), `folder`, `page`, `per_page` | `view {container} assets` | always | `#[IsReadOnly]` |
| `assets_get` | `container` (req), `path` (req) | `view {container} assets` | always | `#[IsReadOnly]` |
| `assets_upload` | `container` (req), `source_url` XOR `content_base64`, `filename`, `folder` | `upload {container} assets` | `writesEnabled()` | — |
| `assets_update` | `container` (req), `path` (req), `data` (req) | `edit {container} assets` | `writesEnabled()` | — |
| `assets_delete` | `container` (req), `path` (req) | `delete {container} assets` | `deletesEnabled()` | `#[IsDestructive]` |

### assets_list

Paginated (existing pattern: config `per_page` default, hard cap 100). `folder` filters to that folder's subtree (normalized: trimmed slashes, no `..`). Each row: `id` (`container::path`), `path`, `basename`, `folder`, `url`, `is_image`, `size` (bytes), `dimensions` (`[w, h]` or null), `alt` (null when unset). Enough to pick an image without a follow-up call.

### assets_get

Everything from the list row plus: raw blueprint `data` (alt, title, custom fields — raw, never augmented, per parent spec §4), `mime_type`, `last_modified` (ISO-8601), `cp_edit_url`.

### assets_upload

Exactly one of `source_url` / `content_base64` (both or neither → targeted error). `filename` required with base64; optional with URL (default: basename of the final URL path, sanitized). `folder` optional, normalized like list, created on demand (native Statamic behavior — folders are implicit).

Pipeline (order matters; every failure is a `ToolException` naming the fix):

1. Gates: `ensureWritesEnabled()` → `ensureExposed('asset_containers', …)` → `ensurePermission(upload)`.
2. ~~Container must report `allowUploads()`~~ — dropped (see §2 correction): v6 has no per-container upload toggle; the `upload {container} assets` permission is the whole gate, matching `AssetPolicy::store`.
3. Acquire bytes → temp file (§5 for the URL path; strict base64 decode for the inline path — invalid input rejected, size cap applied post-decode).
4. Wrap as `UploadedFile`; require an extension; collision check on the destination path — existing asset → error naming it (`use assets_delete first or pick another filename`).
5. Validate against `['file', ...$container->validationRules()]` — the same rules the CP applies.
6. `$container->makeAsset($path)->upload($file)`; `false` (cancelled `AssetCreating`) → "the upload was cancelled by a listener on this site — nothing was created".
7. Respond: `id`, `path`, `basename`, `folder`, `url`, `size`, `dimensions`, `result: 'uploaded — live'`, `cp_edit_url`.

No `data` param by design: alt text is `assets_update`'s job, the tool description says so, and the CP itself works this way.

### assets_update

`data` = raw values validated against the **container's asset blueprint** through the existing `ValidatesBlueprintData` concern — unknown keys rejected with the known-fields error, exactly like entries. Merges into the asset's data, saves, responds with the updated raw data + `result: 'updated — live'` + `cp_edit_url`. (New `LIVENESS_UPLOADED = 'uploaded — live'` constant; reuse `LIVENESS_LIVE` for update. `liveness()`'s union type gains the Asset contract.)

**`focus` exception (decided 2026-07-11, Task 4 review):** the CP's focal-point editor writes a `focus` key into asset data *outside* the blueprint (`AssetsController@update` merges it around field processing), so `assets_get` legitimately returns it on CP-touched assets. `assets_update` therefore accepts `focus` as a pass-through key exempt from the unknown-key rejection — CP parity, and it keeps the get → edit → update round-trip lossless. It is still merged and saved like any other key.

### assets_delete

CP parity: deletes file + metadata via `Asset::delete()` (cancellable `AssetDeleting` reported honestly). References in entry fields are cleaned by Statamic's own reference updater (`UpdateAssetReferences` on `AssetDeleted` — queued, skipped when `statamic.system.update_references` is false); the tool notes this the same way `terms_delete` does. Response: `deleted: true`, `id`, `result`, `note`.

### statamic_overview

Gains `asset_containers`: per exposed container `handle`, `title`, and capability flags `can_view`, `can_upload`, `can_edit`, `can_delete` from the same `can()` predicate collections use. (No `allow_uploads` flag — see §2 correction.)

### blueprints_get

The `assets` fieldtype's `example_notes` fallback becomes actionable: name the field's configured container, point at `assets_list`/`assets_upload`, and state the stored value shape — paths relative to the container root. **Correction (2026-07-12, final review):** the fieldtype stores a **single string** when `max_files: 1` and a list otherwise (vendor `Fieldtypes\Assets::process()`), not "list even when max_files: 1" as this spec originally said; the example mirrors that.

## 4. Config delta

```php
'resources' => [
    'collections' => true,
    'taxonomies' => true,
    'globals' => true,
    'asset_containers' => true,   // true = all handles, or ['blog_images']
],

'uploads' => [
    // Hard per-upload cap in kilobytes. Enforced while streaming source_url
    // downloads (abort mid-stream) and after base64 decode. Container
    // validation rules still apply on top.
    'max_size' => 10240,

    // Exact-host allowlist for source_url. null = any public host.
    // Private/reserved/loopback IPs are ALWAYS blocked regardless.
    'source_allowlist' => null,
],
```

`Tool::exposedHandles()` gains an `'asset_containers'` match arm (`AssetContainer::all()->map->handle()`); its `@param` doc union and `ensureExposed()` callers extend accordingly. Upgrading users who published the old config get safe behavior: a missing `resources.asset_containers` key must read as **not exposed** (`config(..., false)` default already does this) — zero-surprise upgrades, opt-in by republishing or adding the key.

## 5. source_url fetching — SSRF policy (fail-closed)

The only place this server makes outbound requests on agent-supplied input. Rules, in order, per request and **per redirect hop** (max 3, not auto-followed — each hop revalidates):

1. Scheme must be `http` or `https`.
2. Host must pass `uploads.source_allowlist` when configured (exact host match).
3. Resolve DNS; **every** resolved address must be public — reject loopback, private (RFC 1918), link-local, CGN (100.64/10), reserved, and their IPv6 equivalents (::1, fc00::/7, fe80::/10, IPv4-mapped). To close the DNS-rebinding window between check and use, pin the connection to a validated IP via Guzzle's `curl` resolve override (`CURLOPT_RESOLVE`) — never re-resolve after validation.
4. Timeout ~15s; stream to a temp file with a running byte counter capped at `uploads.max_size` — `Content-Length` is advisory only; abort mid-stream on overflow.
5. The temp file then enters the exact same validation pipeline as base64 (§3 steps 4–6) — no trust distinction downstream.

## 6. Testing (Pest, feature-first, mirroring existing suites)

- Per-tool permission matrix (denied without the permission, super auto-pass) and exposure filtering (`asset_containers` array + `true` + missing-key-off-after-upgrade).
- `read_only` hides/blocks upload+update+delete; `deletes` gate on delete; stale-cache re-check inside `execute()`.
- `Storage::fake` containers throughout; `Http::fake` for URL uploads.
- SSRF: private-IP host, redirect-to-private, allowlist miss, >3 redirects, oversize aborts (lying Content-Length included), bad scheme.
- Upload: invalid base64, missing filename (base64), both/neither source params, extension-less filename, container `validationRules()` rejection, collision error, `AssetCreating` cancellation, folder normalization (`../` rejected), meta written, events dispatched.
- Update: blueprint validation (unknown key rejected, alt set), raw-not-augmented response.
- List/get: pagination bounds, folder filter, dimensions/alt presence, unexposed container indistinguishable from missing.
- `statamic_overview` flags and `blueprints_get` assets note.

## 7. Deliverables

5 tool classes, base `Tool` + `StatamicOverview` + `BlueprintsGet` touches, config delta, ~10 test files, README section (tools table, `uploads` config, SSRF paragraph), CHANGELOG entry. `composer format` + Pint + PHPStan + full Pest suite green before done.
