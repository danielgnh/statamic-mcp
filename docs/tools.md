# Tool reference

All 23 tools, in the order an agent typically meets them. Every agent session
should start with `statamic_overview`.

## Discovery

| Tool | What it does |
|---|---|
| `statamic_overview` | Call this first. Sites; the collections, taxonomies, global sets, asset containers, and navigations exposed to MCP and visible to you; your capability flags per resource (`can_create`, `can_edit`, `can_publish`, `can_upload`, `can_delete` — delete flags appear only when deletes are enabled; collections whose blueprint has an `author` field add `can_edit_other_authors`, `can_publish_other_authors`, and `can_delete_other_authors`); `date_behavior` on dated collections (`future` and `past`: `public`, `unlisted`, or `private`, as in the collection settings); the acting user, including their `id`; the server block (`read_only`, `deletes`, and `timezone`, the zone a date without an offset is read in); the site's `guidelines` when `site.md` has any. |
| `blueprints_get` | A blueprint's fields (handle, type, rules, required, options, instructions, and `time_enabled` on date fields) plus a valid example payload for writes. Works for collections, taxonomies, and globals. On collection and taxonomy blueprints, `slug` (and `date` on dated collections) is listed in the fields but left out of the example, because the entry and term write tools take it as a top-level parameter; `example_notes` says so. Replicator and Bard fields list their `sets`, the blocks of a page builder, each with its display name, group, and instructions. Pass `set` with a set's handle to get that set's fields and an example row. A handle is found anywhere in the blueprint, sets inside other sets included; when it has different fields in different places, the error lists their paths, such as `page_builder.section_combined.sections.section_hero`, to pass instead. Grid and group fields list their fields, and asset, entry, term, and user fields report `container`, `collections`, `taxonomies`, `max_files`, and `max_items` when configured. `example_notes` keys notes on nested values by their path in the example. The collection's or blueprint's guideline files come back as `guidelines`. See [guidelines.md](guidelines.md). |

## Entries

| Tool | What it does |
|---|---|
| `entries_list` | Paginated summaries (id, title, slug, status, url, date, updated_at) — never field data. Filter by `status`: `published`, `draft`, `scheduled`, or `expired`. Deterministic ordering: dated collections newest-first, others alphabetical, id as tiebreaker. |
| `entries_get` | Full entry by id or collection + slug. Raw (round-trippable) by default; `format=augmented` for display only. Long rich-text values are truncated to previews unless requested via `fields`. On revision-enabled entries, `has_working_copy` reports staged changes. The returned data is the live entry unless you pass `working_copy: true`, which returns the staged working copy, the version `entries_publish` would promote; `source` says which one you got. |
| `entries_create` | Raw-data create through the same validate-and-process steps as the CP's save, so stored values match what the CP writes. Always saves an unpublished **draft**; nothing goes live here. On revision-enabled collections the draft gets an initial revision attributed to you. On a structured collection the entry goes into the collection's tree, at the top level or under `parent`, the id of another entry in the same collection and site. |
| `entries_update` | Shallow top-level merge of raw data (nested structures replaced wholesale). Never changes publish state. On revision-enabled collections, edits to a published entry become a **working copy** — the live entry is never touched; an existing working copy is amended (created vs amended is stated in the result). Without revisions, edits save straight to the entry, so changes to a published entry are live at once. No-op updates save nothing. |
| `entries_publish` | Makes an entry live. Needs the collection's publish permission. On revision-enabled collections it promotes the staged working copy (or the draft itself) and records a publish revision attributed to you, the same flow as the CP's Publish button. An entry dated in the future on a collection whose future dates are private comes back `scheduled`, not live (see [Scheduling](#scheduling)). An already-published entry with nothing staged is a no-op. |
| `entries_unpublish` | Takes a live entry offline. Same permission as publish, since Statamic has no separate unpublish permission. On revision-enabled collections a staged working copy is applied to the entry and cleared, with an unpublish revision attributed to you. A draft is a no-op. |
| `entries_delete` | Only registered when `deletes` is enabled. Deleting an origin cascades to all localizations (requires site access to each); revision files stay on disk as orphans, same as the CP. |

On a blueprint with an `author` field, the write tools also apply Statamic's author
rules: an entry you are not an author of needs the "other authors" permission, and
`entries_create` makes you the author unless `data` names one. See
[How authorization works](permissions.md#how-authorization-works).

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

## Navigation

| Tool | What it does |
|---|---|
| `navigations_get` | A navigation's tree for one site, in the shape Statamic stores and `navigations_update` accepts. Each entry branch also carries `resolved` with the linked entry's current title, url, and status. That block is read-only, and `navigations_update` ignores it. The response also lists what a write has to respect: `max_depth`, `expects_root`, the collections entry branches may link, and the blueprint fields that branch `data` takes. The read never saves, so a branch stored without an id comes back without one. |
| `navigations_update` | Replaces a site's tree with the complete tree you send, the way saving the tree in the CP does. Branches keep the ids they come with, and a branch without one gets a new id. An entry branch has to link an existing entry of one of the navigation's collections in the same site. A branch without an entry needs a url or a title, and a title alone makes a text item. The tool enforces `max_depth` and the root page rule, and it runs branch `data` through the navigation's blueprint. A tree that equals the stored one saves nothing. Navigations have no draft state, so the saved tree is live at once. |

To build a menu for new pages, create them with `entries_create`, passing `parent` to nest them in a structured collection. Then send `navigations_update` a tree whose entry branches link their ids.

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
"published", "published — working copy is now live", "scheduled — published, but
not live until its date", "expired — published, but its date has passed, not live",
"unpublished — not live", "working copy created — live entry unchanged", "working
copy amended — live entry unchanged", "created — live", "updated — live") and
includes `cp_edit_url` linking the CP edit page (delete responses omit
`cp_edit_url` — the page would 404).
Collections with revisions enabled get working copies through the same mechanism
the CP uses, so an edit there never touches the live entry, and `entries_publish`
promotes the working copy the way the CP's Publish button does. Without revisions,
an edit to a published entry is live as soon as it saves.

## Scheduling

Scheduling is Statamic's own. On a dated collection whose future date behavior is
private, a published entry dated in the future is `scheduled`: it returns a 404
until its date, then goes live with no further call. So an agent schedules a post
by setting its date with `entries_create` or `entries_update` and calling
`entries_publish`. The result says `scheduled`, and the CP shows its Scheduled
badge.

`statamic_overview` reports each dated collection's `date_behavior`. Where
`future` is `public`, a future-dated entry is live as soon as it is published, and
the result says so. A collection created in the CP defaults to `future: private`,
while one defined in YAML without `date_behavior` defaults to public. Where `past`
is `private`, an entry whose date has passed is `expired` and hidden.

Dates accept an offset (`2026-10-06T09:00:00+02:00`). A time without one is read
in `server.timezone` from `statamic_overview`, which is `app.timezone`. An entry's
`date` comes back in UTC with its offset. `blueprints_get` reports `time_enabled`
on the date field. Without it, the CP shows only the day of a scheduled entry, not
the time. A localization inherits its origin's date unless the date field is
localizable, so schedule it on the origin entry.

The entry goes live on time even without cron, because Statamic checks the date
on every request. Statamic's scheduler (`schedule:run` plus a queue worker) is what
refreshes the static cache and search index at that minute, the same as for
entries scheduled in the CP.

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

## Your own tools

The README shows the shape: a server class that extends `Danielgnh\StatamicMcp\Server`,
overrides `tools()` to add, replace, or remove tool classes, and is named in the
`server` config key. This section starts with a complete tool, then covers what the
base tool class gives you, how to replace one of ours, what `read_only` expects from
you, and how to test.

### A complete tool

```php
namespace App\Mcp\Tools;

use Danielgnh\StatamicMcp\Tools\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('newsletter_send')]
#[Description('Send the newsletter draft to every subscriber.')]
class NewsletterSend extends Tool
{
    #[\Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'draft_id' => $schema->string()->description('Entry id of the newsletter draft.')->required(),
        ];
    }

    public function shouldRegister(Request $request): bool
    {
        return $this->writesEnabled();
    }

    protected function execute(Request $request): Response
    {
        $this->ensureWritesEnabled();
        $this->ensurePermission($this->user($request), 'edit newsletter entries');

        $validated = $request->validate(['draft_id' => 'required|string']);

        // ...

        return $this->json(['sent' => true]);
    }
}
```

### What the base class gives you

Extend `Danielgnh\StatamicMcp\Tools\Tool` and implement `execute(Request $request): Response`.
Throw `Danielgnh\StatamicMcp\Tools\ToolException` anywhere in it and the message becomes
a tool error response instead of a 500. The protected helpers:

| Helper | What it does |
|---|---|
| `user($request)` | The acting Statamic user, in token and OAuth mode alike. Throws a tool error when no user is authenticated. |
| `can($user, $permission)` / `ensurePermission($user, $permission)` | Statamic's native permission check, super users pass. The throwing form names the missing permission and the remedy. |
| `ensureExposed($type, $handle)` / `exposedHandles($type)` | Honors the `resources` allowlist for `collections`, `taxonomies`, `globals`, `asset_containers`, and `navigations`. A handle that exists but is not exposed reads as not found, on purpose. |
| `writesEnabled()` / `ensureWritesEnabled()` | The `read_only` switch. |
| `deletesEnabled()` / `ensureDeletesEnabled()` | The `deletes` switch, with `read_only` checked first. |
| `json($data)` | A compact JSON text response. |
| `notFound($what, $given, $available)` | The same not-found shape the built-in tools return. |

Declare parameters in `schema()` and validate them in `execute()`, as `NewsletterSend`
does. laravel/mcp does not enforce the declared schema server-side, so
`$request->validate()` is the real guard.

A plain `Laravel\Mcp\Server\Tool` works too. It just runs behind the addon's middleware
without the helpers above.

### Your tools and read_only

`read_only` hides the built-in write tools because each one implements `shouldRegister()`.
The switch knows nothing about your tools, so a tool that writes needs the same two lines
the built-in ones have, as `NewsletterSend` shows. `shouldRegister()` returning
`$this->writesEnabled()` hides the tool from `tools/list`, and `$this->ensureWritesEnabled()`
at the top of `execute()` refuses the call when a client still has the tool cached.

Read tools need neither. Mark them `#[IsReadOnly]` so clients can tell.

### Replacing and removing tools

`replace()` swaps one class for another in place. The usual replacement is a subclass of
our tool: override `execute()` or `schema()`, redeclare `#[Description]` if the wording
changes, and keep the name, which laravel/mcp reads from the parent class. Our tools keep
their internals private, so a change deeper than `execute()` means copying the class, and
owning a copy beats overriding a private helper. `remove()` drops a tool for good.
Removing a class that is not registered is a no-op; replacing one that is not registered
throws.

Tool names must be unique on the server. `add()` dedupes by class, not by name, so if two
classes carry the same `#[Name]`, `tools/call` reaches the first one and `tools/list`
shows both. That is what `replace()` is for.

The addon serves up to 50 tools on one page because some clients never send a cursor.
If your server grows past that, raise `$defaultPaginationLength` and
`$maxPaginationLength` on your class.

### Testing

laravel/mcp's test harness runs against your server class. Act as a Statamic user and
call the tool by class:

```php
use App\Mcp\StatamicServer;
use App\Mcp\Tools\NewsletterSend;
use Statamic\Facades\User;

$user = User::make()->email('editor@site.com')->makeSuper();

StatamicServer::actingAs($user)
    ->tool(NewsletterSend::class, ['draft_id' => $draft->id()])
    ->assertOk()
    ->assertSee('"sent":true');
```

The harness honors `shouldRegister()`, so a hidden tool answers "not found". To pin the
in-handler re-check, call `handle()` on a fresh instance directly.
