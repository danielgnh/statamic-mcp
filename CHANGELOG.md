# Changelog

All notable changes to `danielgnh/statamic-mcp` are documented here. The format
follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project
adheres to [Semantic Versioning](https://semver.org). The package is pre-1.0:
until `v1.0.0`, breaking changes may land in minor (`0.x`) releases and are
called out here explicitly.

## [Unreleased]

### Added

- `entries_localize` adds an entry to another site of its collection, the way
  the Control Panel's Localize action does. The new entry is a draft
  localization that inherits every field it does not override, `data` holds
  its own values such as a translated title and content, and `slug` defaults
  to the origin's. On a structured collection it joins the target site's tree
  under the localization of the origin's parent, or at the top level. The
  origin is the entry passed, or the root entry when the collection's origin
  behavior is root, as in the Control Panel. It needs the edit permission for
  the origin entry and access to the target site, and it is registered on
  multisite installs only. Until now an agent had to send
  people to the Control Panel to add a page to a second language.

## [0.6.0] - 2026-09-25

### Added

- `blueprints_get` returns `tabs`: the tabs whose own or whose sections'
  `instructions` say how their fields go together, with the handles of the
  fields under them. That is where a note about one blueprint goes, such as
  which existing entry to follow; the Control Panel shows the same text above
  the fields.

### Changed

- **Breaking:** guidelines for agents moved from markdown files under
  `resources/mcp/guidelines` into a global set, so the people who run the site
  edit them in the Control Panel and an agent whose user may edit the set can
  through `globals_update`. `mcp:guidelines` creates the set, handle
  `guidelines`, with a `site` field that `statamic_overview` returns and a
  `resources` replicator whose rows name collections and taxonomies and say
  how their entries are put together; `blueprints_get` returns the rows naming
  the requested resource, in their order. The `guidelines_path` config key is
  gone, and the new `guidelines` key names the set. Guidelines for one
  blueprint or for a global set have no home in the set; a section's
  instructions carry them now (see below). To migrate, run
  `php please mcp:guidelines`, paste `site.md` into the set's Site field,
  paste each collection file into a row naming its collection, and delete
  `resources/mcp/guidelines`. Move anything that describes one field into
  that field's `instructions` instead.
- `mcp:guidelines` lists the blocks without instructions as text that fits the
  terminal instead of a table: one heading per set of blueprints that share
  them, then one line per field with the names of its sets. A page builder
  fieldset that six blueprints import used to repeat all six on every row,
  which made the table wider than any terminal.

## [0.5.0] - 2026-09-24

### Added

- `blueprints_get` lists the sets of Replicator and Bard fields, the blocks of a
  page builder, with each set's display name, group, and instructions. Sets
  hidden from the CP's set picker come back with `"hidden": true`. Pass a
  set's handle as the new `set` parameter to get that set's fields and an
  example row, with fieldset imports resolved, nested sets listed by name, and
  `example_notes` keyed by their path in the row. A handle is found anywhere in
  the blueprint, sets inside other sets included. When it has different fields
  in different places, the error lists their paths to pass instead. Grid and
  group fields list their fields. Until now these fields had no description
  beyond their type. A set's `instructions` is now where you tell agents when
  to use a block; editors already see the same text in the CP.
- `blueprints_get` reports where asset, entry, term, and user fields point
  (`container`, `collections`, `taxonomies`) and how many items they take
  (`max_files`, `max_items`), when the field configures it.
- **Guidelines for agents.** Markdown files under `resources/mcp/guidelines`
  (the new `guidelines_path` config key). `statamic_overview` returns
  `site.md`, and `blueprints_get` returns `collections/{handle}.md` plus
  `collections/{handle}/{blueprint}.md`, with taxonomies and globals laid out
  the same way. HTML comments are stripped, so notes to yourself and untouched
  stubs never reach an agent. See `docs/guidelines.md`.
- `mcp:guidelines` creates `site.md` and a file per exposed collection, never
  overwriting one, and lists the page builder blocks that have no instructions.
- A `statamic-mcp-guidelines` Boost skill that teaches coding agents to write
  block instructions from each block's template.
- **Navigation tools.** `navigations_get` reads a navigation's tree for one site
  in the shape Statamic stores it, and `navigations_update` replaces that tree,
  as saving the tree in the CP does. An agent that creates pages can now build
  their menu too. `navigations_update` checks that entry branches link existing
  entries of the navigation's collections in the same site, enforces
  `max_depth` and the root page rule, and runs branch `data` through the
  navigation's blueprint. Reading takes `view {nav} nav` and writing takes
  `edit {nav} nav`. `statamic_overview` lists the navigations you can view.
  The new `resources.navigations` config key exposes them and defaults to
  `true`. A config you published before this release has no such key, so it
  exposes no navigations until you add `'navigations' => true`.
- `entries_create` takes `parent` on a structured collection, the id of an entry
  to nest the new one under. The parent has to be in the same collection and
  site, and the nesting has to fit the collection's `max_depth`. A root page
  as parent means the top level, as in the CP.
- `entries_update` takes `parent` to move an entry, with its children, in a
  structured collection's tree. The id of an entry in the same collection and
  site makes it that entry's last child, and `""` moves it to the top level. An
  omitted or null `parent` never moves anything, so a client that sends null for
  every unset parameter can't move an entry by accident. Moving needs
  `reorder {collection} entries`, the permission the CP's tree view checks, on
  top of `edit` and its author rule, so moving someone else's entry on a
  blueprint with an `author` field also needs
  `edit other authors {collection} entries`. The move saves the live tree at
  once, also on revision-enabled collections, where the data change still waits
  in the working copy. An entry can't move under itself or its descendants, the
  root page doesn't move, and `max_depth` counts the entry's deepest descendant.
- `entries_publish` and `entries_unpublish`. Publishing is its own pair of tools
  now, the same split Statamic's CP makes with `PublishedEntriesController`.
  Both need the collection's publish permission. On revision-enabled collections
  they promote or apply the staged working copy and record an attributed
  revision, which until now only the CP could do. Because each is a separate
  tool, MCP clients prompt for it separately: allowing `entries_update` no
  longer lets an agent publish. On a dated collection without revisions,
  changing the date of an entry that is already published still decides
  whether it is live, as in the CP.
- `entries_preview` returns a short-lived URL that renders an entry through the
  site's templates using Statamic's Live Preview, so an agent can check a draft
  without a human opening the browser. On revision-enabled entries the page
  shows the staged working copy. The tool needs the collection's edit
  permission, the same check as Live Preview in the CP, author rule included:
  previewing someone else's entry on a blueprint with an `author` field needs
  `edit other authors {collection} entries`. Anyone with the URL can open it
  until it expires an hour after the call. It changes no content, so `read_only`
  keeps it.
- **Your own tools.** The new `server` config key names the laravel/mcp server
  class to mount. Extend `Danielgnh\StatamicMcp\Server`, override `tools()`, and
  add, replace, or remove tools on the `ToolRegistry` it receives; they run
  behind the addon's auth middleware and `Access MCP` gate. `Danielgnh\StatamicMcp\Tools\Tool` is now
  the documented base for host-app tools. A `server` value that is not a
  laravel/mcp server fails closed at boot, and `mcp:doctor` names it with the
  remedy. See "Adding your own tools" in the README and `docs/tools.md`.
- `statamic_overview` reports the acting user's `id`, and collections whose
  blueprint has an `author` field add `can_edit_other_authors`,
  `can_publish_other_authors`, and `can_delete_other_authors`.
- **Scheduling is visible to agents.** `statamic_overview` reports each dated
  collection's `date_behavior` (future/past: public, unlisted, private) and the
  server `timezone` that dates without an offset are read in. With it, an agent
  can tell before publishing whether a future date schedules the entry or puts it
  live right away. `blueprints_get` reports `time_enabled` on date fields.
- `entries_publish` reports what Statamic actually did: "scheduled — published,
  but not live until its date" or "expired — published, but its date has passed,
  not live" when the entry's date keeps it hidden, and it returns the date on
  dated collections. Its no-op result names the state ("already scheduled").
- `entries_get` takes `working_copy: true` and returns the staged working copy,
  the version `entries_publish` would promote. An agent can show a person exactly
  what goes live before they approve the publish.
- `entries_list` filters by `status: expired`.

### Changed

- **Breaking:** `entries_create` and `entries_update` no longer accept
  `published`. Creates always save a draft and updates never touch publish
  state. The parameter is rejected with a pointer to `entries_publish`, so a
  client with a stale tool cache cannot save a draft it believes is live.
- A date without an offset is now parsed in `app.timezone` explicitly, instead
  of relying on PHP's default timezone. Laravel sets the two to the same value,
  so behavior is unchanged. Date examples in tool descriptions and errors now
  show the offset form (`2026-07-09T15:30:00+02:00`).
- **Breaking:** `entries_create` makes the acting user the author when the
  blueprint has an `author` field and `data` names none, as the Control Panel
  does. Naming anyone else, and changing an entry's author with
  `entries_update`, needs `edit other authors {collection} entries`.
- `laravel/mcp` 0.9 and 1.x are supported alongside 0.8, and nothing changes
  on 0.8. On 1.x, clients on the 2026-07-28 protocol revision connect through
  `server/discover`, clients that open with `initialize` keep working on
  protocol 2025-06-18 or newer (1.x answers an older version with 2025-11-25,
  which such a client may refuse), and JSON-RPC errors come back as HTTP 400,
  404, or 500 instead of 200. laravel/mcp 0.9.6 and 1.0.1 fix the loopback
  redirect URI check in OAuth client registration, and 0.8 won't get that fix.
  Upgrade if you run OAuth mode and have narrowed `mcp.redirect_domains`.
- **Breaking:** requires `statamic/cms` 6.31 or newer, the oldest release CI
  tests. Composer refuses every earlier 6.x release by default, because of a
  security advisory.
- `entries_get` names the entry's blueprint at the top level and leaves it out
  of raw `data`. In a collection with more than one blueprint, Statamic stores
  the blueprint in each entry's data, so writing back what `entries_get`
  returned failed with "field blueprint is reserved". `entries_update` ignores
  the entry's own blueprint in `data` and still refuses another one.

### Fixed

- `entries_create` puts a new entry of a structured collection into the
  collection's tree, as the CP does. Its response used to say `url: null` for
  such an entry, and the entry only appeared at the end of the top level when
  the tree was next read. The tree file also gains any entries it was missing,
  in the order Statamic already listed them. Tree edits take a lock per
  collection and site and read the tree afresh inside it, so parallel creates
  and moves no longer drop each other's placements.
- Writes store what the Control Panel stores. `entries_create`,
  `entries_update`, `terms_create`, `terms_update`, `globals_update`, and
  `assets_update` now take each value through the fieldtype's `preProcess()`,
  validate it, and save the result of `process()`, the same steps as a CP save.
  A single-file asset or single-item relationship is saved as a plain string,
  sets and grid rows get ids, dates use the field's save format, and HTML sent
  to a Bard field becomes ProseMirror. Values are accepted in the shape the get
  tools return, so what `entries_get` or `globals_get` returned can be written
  back unchanged. Before, a single-file asset had to be sent as a list and was
  saved as one, which themes reading the raw value do not expect.
- Updating an entry, term, or global set no longer fails because the CP saved
  a single-file asset, a single-item relationship, or a date on it. Validation
  used to run against the stored value, so even an unrelated title change was
  refused.
- Replicator and Bard sets are checked like top-level fields. A set type the
  field does not define is rejected with the valid types and a did-you-mean
  hint, and so are unknown keys inside sets, grid rows, and groups. A set
  without a type returns an error naming its path instead of "An internal
  server error occurred."
- An asset reference has to exist in the field's container. A missing path, a
  URL, or another container's id used to be saved and render as nothing.
- `blueprints_get` examples use the stored shape: a plain id for single-item
  relationship fields, and each date field's save format.
- `blueprints_get` examples for option fields used the whole first option when
  the options were saved as key/value pairs, the format the CP writes. They
  use its key now, and button group fields get an example too.
- `blueprints_get` put `slug`, and `date` on dated collections, into the example
  payload, but the write tools reject both inside `data`, so an agent that
  copied the example got an error. They stay in `fields` and are left out of
  the example, with an `example_notes` entry pointing at the top-level
  parameter. The same applies to `slug` on taxonomy blueprints, and
  `terms_create` now says to pass `slug` as a top-level parameter instead of
  calling it an unknown field.
- `entries_update` reported "published" after re-dating a published entry into
  Statamic's scheduled or expired state, while its URL returned a 404. The result
  now follows the entry's status, as `entries_publish` does.
- `entries_update` refuses a date for a localization whose date field is not
  localizable. The localization inherits its origin's date, the CP shows the
  field read-only there, and publishing a working copy silently dropped the
  staged date.
- `entries_create` and `entries_update` refuse a URL another entry of the site
  already has, as the CP does. A page nested under `/blog` could take a blog
  post's `/blog/hello` and hide it.
- `entries_update` validates a localization with the values it inherits from
  its origin, as the CP's form does. A patch that left out an inherited
  required field, like the title, failed with "The Title field is required."
- `entries_update` reported the live entry's URL next to the staged slug and
  date when it staged a working copy. It now reports the staged URL, as
  `entries_get` does with `working_copy: true`.
- OAuth mode: a fresh install kept Passport's integer `user_id` columns, and
  the first consent failed on MySQL and Postgres. The addon's conversion
  migration was dated 2026-07-14, but `vendor:publish` stamps Passport's
  migrations with the moment they are published, so the conversion ran before
  their tables existed and was recorded as run. It is now dated to run after
  them, and on sites it missed, the next `php artisan migrate` converts the
  columns, as `mcp:doctor` says.
- `mcp:keys` prints only the keys on stdout. Its notices, warnings, and errors
  go to stderr, so `mcp:keys --json | jq` and `mcp:keys >> .env` work on the
  first run too, when it generates or adopts a pair. With only one
  `PASSPORT_*` key set, it reads each half from its env key or its key file,
  as Passport does, and fails with the remedy when a half is missing, instead
  of exporting the database pair the runtime then ignores.
- `blueprints_get` says a Bard field without sets takes HTML, as it already
  did for Bard fields with sets, instead of telling agents to copy a value
  from existing content.

### Security

- **Breaking:** the entry write tools skipped Statamic's author rules. On a
  blueprint with an `author` field, a role with `edit blog entries` could edit
  anyone's entry through MCP, while the Control Panel limits it to entries the
  user is an author of. `entries_update`, `entries_publish`,
  `entries_unpublish`, and `entries_delete` now check what Statamic's
  `EntryPolicy` checks: an entry the user is not an author of, including one
  with no author, needs `edit other authors {collection} entries`,
  `publish other authors {collection} entries`, or
  `delete other authors {collection} entries`. The denial names the
  permission.
- **Breaking:** on multi-site installs the default site now needs
  `access {site} site` like every other site, as Statamic's `SitePolicy`
  requires. MCP used to exempt it, so a role with only `access fr site` could
  still read and write default-site content. Grant the default site's
  permission to roles that should keep that access.
- `mcp:setup` printed the private signing key. Its key step streamed
  `php please mcp:keys`, whose output is the key pair, also in the unattended
  runs that end up in CI logs and agent transcripts. It now shows only what
  `mcp:keys` did.

### Known caveats

- `entries_create` always uses the collection's default blueprint. The tool has
  no `blueprint` parameter, one sent anyway is ignored, and `blueprint` inside
  `data` is refused as reserved. In a collection with several blueprints, an
  entry of another blueprint has to be created in the CP.
- `blueprints_get` reports the name of a set's group, but not the group's
  `instructions`. Put the instructions agents need on the sets themselves.
- Writes accept a set that is hidden from the CP's set picker. `blueprints_get`
  marks such a set `"hidden": true`, so an agent can leave it alone.

## [0.4.2] - 2026-07-20

### Fixed

- `mcp:setup --oauth` no longer crashes at the key step after installing
  Laravel Passport itself. The wizard's own process can't load Passport's
  classes until it restarts, and the key check used them anyway.

## [0.4.1] - 2026-07-16

### Changed

- The MCP Access utility shows only the active auth mode. Token mode shows
  tokens and how to connect with one, and OAuth mode shows connections and how
  to connect a connector. Tokens issued before a site switched to OAuth stay
  listed under "Leftover tokens" until they're revoked, and issuing a new token
  in OAuth mode is refused.

## [0.4.0] - 2026-07-15

### Added

- **Database-managed Passport keys** — the signing pair now lives where the
  rest of the OAuth state already does: a new `statamic_mcp_oauth_keys` table
  (private key only — the public half is derived — encrypted at rest with
  `APP_KEY`), injected into Passport just in time as its servers resolve.
  Deploys need no key step anymore: once `php artisan migrate` has run, the
  first OAuth request provisions a pair automatically (race-safe across a
  fleet), every server reads the same copy, and releases and read-only
  filesystems are a non-issue. Existing setups keep working untouched —
  `PASSPORT_*` env keys take precedence, and `storage/oauth-*.key` files are
  adopted into the database on first use.
- `mcp:doctor` names the key source (environment config / database / key
  files / pending provision) and fails with a dedicated remedy when the stored
  key can't be decrypted after an `APP_KEY` change — deliberately never
  regenerating over it, which would silently disconnect every client.

### Changed

- `mcp:keys` mirrors the runtime precedence exactly (config → database → key
  files), generates into the database when its table exists (key files remain
  the pre-migrate fallback), adopts existing key files into the store, and
  refuses to touch an undecryptable stored key.
- `mcp:setup` provisions keys **after** the migrate step so they land in the
  database, and declining the key step is no longer fatal — the first OAuth
  request self-provisions.

## [0.3.2] - 2026-07-15

### Fixed

- `mcp:keys` crashed on host apps with *"Configuration value for key
  [passport.private_key] must be a string, NULL given"* — Passport's config
  file defines the keys as `env(...)`, so they exist as `null` when the env
  vars are unset, and the strict config read threw instead of falling through
  to the key files.

## [0.3.1] - 2026-07-15

### Changed

- Documentation only: the changelog history was rebuilt against the real
  release tags (the package never shipped a `v1.0.0`; the old section under
  that name is now `[0.1.0]`, and later work is attributed to the tag that
  actually contained it), and `RELEASING.md` became a version-agnostic,
  repeatable checklist with current tool counts and CI shape.

## [0.3.0] - 2026-07-15

### Added

- `php please mcp:keys` — one-command key provisioning for production. Ensures a
  stable Passport key pair exists (generating one only when none exists — never
  overwriting, since regeneration silently invalidates every connected client) and
  prints it as paste-ready `PASSPORT_PRIVATE_KEY` / `PASSPORT_PUBLIC_KEY` env
  variables, escaping done. `--json` pipes into secret-store CLIs, `--write` fills
  the local `.env`. Source precedence mirrors Passport's runtime: configured env
  keys first (warning when stale key files differ), then key files, then generate.
  `mcp:setup`, `mcp:doctor`, and the endpoint's 503 remedy all point at it.

## [0.2.1] - 2026-07-15

### Changed

- The OAuth consent screen and the MCP Tokens utility are rebuilt on shared
  addon Blade components (`auth-card`, `avatar`, `button`, `heading`,
  `description`), with a deterministic per-user avatar gradient. Visual only —
  no behavior change.
- `config/mcp.php` inline comments trimmed; no keys changed.

## [0.2.0] - 2026-07-14

### Added

- **OAuth mode now works with file users — no user migration, ever.** The addon
  registers its own auth guard: bearers are validated by Passport's ResourceServer
  (signature, expiry, revocation — identical checks to Passport's stock guard) and
  the token's user resolves through the Statamic repository. No Eloquent users, no
  `HasApiTokens` trait, no `api` guard in `config/auth.php`. Passport still needs a
  database for its *own* tables; the addon ships a migration converting their
  bigint `user_id` columns to string(36) so Statamic's UUID ids fit (loaded only in
  OAuth mode; safe for integer ids — this also fixes the latent insert crash for
  UUID-keyed Eloquent users on the old path). Keys can come from
  `PASSPORT_PRIVATE_KEY` / `PASSPORT_PUBLIC_KEY` env vars — the deploy-friendly
  path `mcp:doctor` now recognizes.

### Changed

- `laravel/passport` is now a dev dependency at `^13.0` (previously
  suggest-only, with a dedicated CI leg installing it) — the separate Passport
  CI leg is gone; every matrix leg runs the full OAuth coverage, including
  real signed tokens. For host apps Passport remains an opt-in `suggest`
  dependency, required only for OAuth mode.

## [0.1.1] - 2026-07-14

### Fixed

- OAuth bearers must carry the `mcp:use` scope `laravel/mcp` advertises — a
  Passport token minted for the host app's own API can no longer double as an
  MCP entry point (403 `insufficient_scope` instead).
- `blueprints_get` is gated on the same per-resource view/edit permission the
  content read tools use, so schema no longer leaks to users who can't view the
  content.
- `globals_get` no longer 500s on a set configured for a site but never saved
  there (null localization guarded on both the single and listing paths).
- Out-of-range dates in `entries_create` / `entries_update` surface as a clean
  tool error instead of a 500.

### Changed

- Internal refactors: rich-text preview logic extracted into a shared concern;
  comments that restated the code trimmed.

## [0.1.0] - 2026-07-13

Initial release.

### Added

- Remote (streamable-HTTP) MCP server for Statamic v6, built on `laravel/mcp`,
  mounted at `mcp/statamic` (configurable).
- 19 tools: `statamic_overview`, `blueprints_get`,
  `entries_list` / `entries_get` / `entries_create` / `entries_update` / `entries_delete`,
  `terms_list` / `terms_get` / `terms_create` / `terms_update` / `terms_delete`,
  `globals_get`, `globals_update`, and the assets tools below. Delete tools are
  only registered under `'deletes' => true`; under `read_only` only the read
  tools appear. `tools/list` returns the full tool set in one page (the default
  15-per-page cut-off would hide tools from clients that never paginate).
- Assets tools: `assets_list`, `assets_get`, `assets_upload`, `assets_update`,
  and `assets_delete`. Uploads accept a `source_url` — downloaded server-side
  behind a fail-closed SSRF guard (public hosts only, DNS pinning, per-hop
  redirect revalidation, streaming size cap) — or inline `content_base64`;
  collisions are refused, never overwritten. `assets_update` merges
  blueprint-validated metadata and passes the CP's `focus` key through for
  lossless round-trips. Exposure via the `resources.asset_containers` config
  key and an `uploads` config block (`max_size` in KB, `source_allowlist`);
  `statamic_overview` lists exposed containers with `can_upload` / `can_edit` /
  `can_delete` flags.
- Token auth mode (default): `mcp_{tokenId}_{secret}` tokens hashed (SHA-256)
  into `storage/statamic/mcp/tokens.yaml` — works on file-based and Eloquent
  user installs. Commands: `mcp:token`, `mcp:tokens`, `mcp:token:revoke`.
  Every authentication failure answers one indistinguishable 401 — including
  hand-edited records that lost their hash key (which always reject, never
  degrading to a guessable password). Token-store writes are serialized behind
  an atomic lock, making concurrent CLI + CP issuance/revocation safe.
- OAuth auth mode (opt-in): delegates entirely to `laravel/mcp` + Laravel
  Passport; misconfiguration answers 503 with the exact remedy on the MCP
  route only — token mode never touches Passport.
- `php please mcp:setup` — interactive onboarding wizard for both auth modes. The
  OAuth path checks, confirms, and applies every prerequisite (Passport install,
  encryption keys, `.env` flip, migrations) and verifies with `mcp:doctor`. File
  edits are anchor-based with a printed manual fallback; the wizard is idempotent.
- `php please mcp:doctor` configuration health check with remedies, covering
  both auth modes and the "enabled but failed to mount" state.
- MCP Tokens utility in the Control Panel (Tools → Utilities, retitled **MCP
  Access**) — issue and revoke your own tokens, gated by the "Access MCP Tokens
  utility" permission; super admins see all tokens. Built on Statamic v6's UI
  component kit (native CP look including dark mode, copy-to-clipboard on the
  one-time token reveal, toast notifications). User-controlled strings render
  inertly (`v-pre`) so token names can't execute as Vue expressions. Includes
  an OAuth connections panel: one row per connected user + client pair from
  Passport's tables, with an Active/Expired status that honestly counts live
  refresh tokens and a Disconnect action revoking the pair's access *and*
  refresh tokens.
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
- CI runs the suite twice: the main leg and a leg with `laravel/passport`
  installed, activating the OAuth-connection tests that skip in the main leg.

### Known caveats

- `laravel/mcp` is required at `^0.8` — a pre-1.0 release line. Its API may
  change in 0.x minors; this addon pins `^0.8` and will track upstream in
  its own minor releases.
- Under multisite, the default site is never gated by `access {site} site`
  (documented in the README's security model; CP-parity review is a future
  candidate).
- Deleting an entry from a revision-enabled collection leaves its revision
  and working-copy files on disk as orphans — the Control Panel behaves the
  same way.

[Unreleased]: https://github.com/danielgnh/statamic-mcp/compare/v0.6.0...HEAD
[0.6.0]: https://github.com/danielgnh/statamic-mcp/compare/v0.5.0...v0.6.0
[0.5.0]: https://github.com/danielgnh/statamic-mcp/compare/v0.4.2...v0.5.0
[0.4.2]: https://github.com/danielgnh/statamic-mcp/compare/v0.4.1...v0.4.2
[0.4.1]: https://github.com/danielgnh/statamic-mcp/compare/v0.4.0...v0.4.1
[0.4.0]: https://github.com/danielgnh/statamic-mcp/compare/v0.3.2...v0.4.0
[0.3.2]: https://github.com/danielgnh/statamic-mcp/compare/v0.3.1...v0.3.2
[0.3.1]: https://github.com/danielgnh/statamic-mcp/compare/v0.3.0...v0.3.1
[0.3.0]: https://github.com/danielgnh/statamic-mcp/compare/v0.2.1...v0.3.0
[0.2.1]: https://github.com/danielgnh/statamic-mcp/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/danielgnh/statamic-mcp/compare/v0.1.1...v0.2.0
[0.1.1]: https://github.com/danielgnh/statamic-mcp/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/danielgnh/statamic-mcp/releases/tag/v0.1.0
