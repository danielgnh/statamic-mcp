# Tool reference

All 19 tools, in the order an agent typically meets them. Every agent session
should start with `statamic_overview`.

## Discovery

| Tool | What it does |
|---|---|
| `statamic_overview` | Call this first. Sites; the collections, taxonomies, global sets, and asset containers exposed to MCP and visible to you; your capability flags per resource (`can_create`, `can_edit`, `can_publish`, `can_upload`, `can_delete` — delete flags appear only when deletes are enabled); the acting user; server flags (`read_only`, `deletes`). |
| `blueprints_get` | A blueprint's fields (handle, type, rules, required, options, instructions) plus a valid example payload for writes. Works for collections, taxonomies, and globals. |

## Entries

| Tool | What it does |
|---|---|
| `entries_list` | Paginated summaries (id, title, slug, status, url, date, updated_at) — never field data. Deterministic ordering: dated collections newest-first, others alphabetical, id as tiebreaker. |
| `entries_get` | Full entry by id or collection + slug. Raw (round-trippable) by default; `format=augmented` for display only. Long rich-text values are truncated to previews unless requested via `fields`. On revision-enabled entries, `has_working_copy` reports staged changes; the returned data is always the live entry. |
| `entries_create` | Raw-data create through Statamic's own validation. Saves an unpublished **draft by default**; `published: true` requires the publish permission. On revision-enabled collections entries are always created as drafts with an attributed initial revision. |
| `entries_update` | Shallow top-level merge of raw data (nested structures replaced wholesale). On revision-enabled collections, edits to a published entry become a **working copy** — the live entry is never touched; an existing working copy is amended (created vs amended is stated in the result). No-op updates save nothing. |
| `entries_delete` | Only registered when `deletes` is enabled. Deleting an origin cascades to all localizations (requires site access to each); revision files stay on disk as orphans, same as the CP. |

## Taxonomy terms

| Tool | What it does |
|---|---|
| `terms_list` | Paginated term summaries — no publish state on terms, so no status filter. |
| `terms_get` | Term by id (`taxonomy::slug`) or taxonomy + slug, raw or augmented. With `site`, `data` holds local overrides and `inherited` what falls back from the term's origin site (the taxonomy's first configured site). |
| `terms_create` | Creates in the taxonomy's origin site; localize afterwards with `terms_update` + `site`. Terms have no draft state — created terms are live immediately. |
| `terms_update` | Same merge contract as entries. `slug` renames the term: on the origin site (the taxonomy's first configured site) this changes the term id and moves the file; on other sites it stores a localized slug override. |
| `terms_delete` | Only registered when `deletes` is enabled. Removes the term from every site at once; Statamic's reference updater then strips references from entries (runs on the queue; skipped when `statamic.system.update_references` is false). |

## Globals

| Tool | What it does |
|---|---|
| `globals_get` | Raw global variables — one set by handle, or every set you can access. With `site`, includes values inherited from the origin site. |
| `globals_update` | Merge-patch a set's variables per site (localizations created transparently on first write). Globals have no draft state: saved values are live immediately. |

## Assets

| Tool | What it does |
|---|---|
| `assets_list` | Paginated asset summaries per container (id, path, basename, folder, url, is_image, size, dimensions, alt), ordered by path. Optional `folder` filters to a subtree. |
| `assets_get` | One asset's full detail: the summary columns plus raw blueprint data (alt text, custom fields — the shape `assets_update` accepts), mime type, last modified, CP edit link. |
| `assets_upload` | Upload from a `source_url` (server-side download with fail-closed SSRF guards — see below) or inline `content_base64` for small files. Optional `folder` created on demand. Never overwrites — collisions are errors. Uploads are live immediately. |
| `assets_update` | Merge-patch an asset's metadata (alt text + custom blueprint fields) — the file itself is untouched. `focus` (the CP's focal point) passes through for lossless round-trips. |
| `assets_delete` | Only registered when `deletes` is enabled. Removes the file and its metadata; Statamic's reference updater then strips references from entries (queued; skipped when `statamic.system.update_references` is false). |

## Write responses

Every write response states the resulting liveness ("saved as draft — not live",
"published", "working copy created — live entry unchanged", "working copy amended —
live entry unchanged", "created — live", "updated — live") and includes `cp_edit_url`
linking the CP edit page (delete responses omit `cp_edit_url` — the page would 404).
Collections with revisions enabled get working copies through the same mechanism
the CP uses — the live entry is never mutated, publishing stays in the Control Panel.

## Asset uploads and the SSRF policy

`assets_upload` accepts a `source_url` (the server downloads the file) or inline
`content_base64` (small files). URL fetching is **fail-closed**: only `http`/`https`;
the server resolves DNS itself and refuses any host with a private, loopback,
link-local, carrier-grade-NAT, or otherwise reserved address (IPv4 and IPv6,
cloud metadata endpoints included); the connection is pinned to the validated IP
(no DNS-rebinding window); every redirect hop (max 3) is re-validated; and
downloads abort past `uploads.max_size` — `Content-Length` is never trusted.
Set `uploads.source_allowlist` to pin uploads to known hosts. Container-level
validation rules (e.g. `mimes:jpg,png`) and Statamic's global file guards apply
on top, exactly as in the Control Panel.
