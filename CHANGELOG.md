# Changelog

All notable changes to `danielgnh/statamic-mcp` are documented here. The format
follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project
adheres to [Semantic Versioning](https://semver.org). Before `v1.0.0`,
breaking changes landed in minor (`0.x`) releases and are called out here
explicitly. [UPGRADE.md](UPGRADE.md) covers the steps an upgrade needs.

## [Unreleased]

### Added

- **The Control Panel pages speak the user's language.** Tools → MCP, the
  OAuth consent page, and the Access MCP permission follow each user's
  Control Panel language, with translations for German (`de`, `de_CH`),
  Spanish, French, Italian, Dutch, Norwegian, Portuguese (Brazil), and
  Russian. Other languages keep English, and an app overrides any wording
  from its own `lang/<locale>.json`. Dates on the Connections page follow
  Carbon's locale, as the relative times beside them already did.

### Changed

- **Rebuild a cached route list after updating.** The Connections page moved to
  another controller method, and the MCP route's middleware order changed. With
  a route cache from an older version, Connections answers 500, and GET and
  DELETE still skip the kill switch and the throttle. Run
  `php artisan route:cache` or `php artisan optimize`.
- **Tools → MCP opens on a page for choosing Connections or Guidelines.** Super
  admins used to land on Guidelines. Users who can only open Connections still
  go straight to it. Connections now comes first in the sidebar too.
- **Only bad config keeps MCP off at boot.** A `route`, `server`, or
  `middleware` value of the wrong shape still leaves the site up with MCP
  unmounted and reports the failure. Any other exception while registering the
  routes now surfaces, as it would from any other package, instead of quietly
  turning MCP off.
- **One user can hold at most 100 tokens through the Control Panel.** Every MCP
  request parses the whole token file, so one user issuing tokens without end
  slowed everybody down. `mcp:token` on the console has no limit.

### Fixed

- **The docs now say what the code does.**
  - `enabled: false` doesn't remove Passport's own routes, such as
    `/oauth/authorize` and `/oauth/token`, though the README, UPGRADE, and the
    `mcp:doctor` warning said all OAuth routes went off.
    [docs/oauth.md](docs/oauth.md#turning-mcp-off) now covers what they do with
    MCP off and how to remove them.
  - A missing or invalid tool argument answers HTTP 200 with `isError: true`,
    not the 400 `docs/troubleshooting.md` gave.
  - `resources` doesn't hide stored values everywhere, as UPGRADE said. A raw
    read returns an entry id, term slug, or asset path outside the exposure,
    `navigations_get` returns a linked entry's id and any title set on the
    branch, and `entries`, `terms`, and `link` fields can store a value outside
    the exposure.
  - `uploads.max_size` doesn't always stop a download early. A body without a
    `Content-Length` is refused when the transfer ends, which can take the full
    15 seconds.
  - The edit chart added up to 1,868 tokens because it left out the 100-token
    result of `entries_update`. The 1,968 total was right, and the chart and
    README now show the 100.
- **Code snippets on the Connections page follow the Control Panel's theme.**
  The Claude Code and Cursor snippets, in How to connect and on a newly created
  token, stayed dark in light mode.
- **`mcp:doctor` no longer suggests deleting `tokens.yaml` when the user store
  fails.** A failed user lookup was reported as a corrupt file. It now says the
  file is fine and names the failure.
- **An outage or a bug no longer reads as the agent's mistake.** A database
  outage inside a fieldtype told the agent its value was bad, and an unexpected
  error during a `source_url` download read as a bad URL. Neither was reported.
  With `APP_DEBUG` off, both now reach your exception handler and the agent gets
  `An internal server error occurred.`; with it on, `laravel/mcp` gives the
  agent the raw message instead. A PHP error inside a fieldtype still names the
  field for the agent and is now reported too, since it can be a bug as easily
  as a bad value. A third-party fieldtype that throws a plain `Exception` on a
  bad value now gives the agent the generic error; throw
  `InvalidArgumentException` to keep a message that names the field.
- **`mcp:doctor` names a `route`, `server`, or `middleware` config that can't
  mount.** A `route` that wasn't a string crashed the command, and so did a
  `server` class that couldn't load. A `middleware` entry that wasn't a string
  passed as fine.
- **`assets_upload` no longer reports a file it did not store.** When the disk
  refuses the write, for example a read-only disk, the tool answers with an
  error and leaves nothing behind. It used to answer "uploaded — live". A
  filename over 250 bytes is refused up front: its `.yaml` sidecar wouldn't fit
  the 255 bytes filesystems allow, and on the file cache store the upload ended
  in an internal error.
- **`assets_upload` names a stored file Statamic can't read as an image.** On a
  sync queue, an HTML error page from a `source_url`, say, was stored and live
  while the tool answered with an internal error. The result now names the file
  and says what failed under `not_stored`.
- **`assets_upload` refuses a `.meta` folder and control characters in
  `folder`.** A file stored under `.meta` could not be read or deleted by any
  later call.
- **A `source_url` redirect to an unparseable URL is a tool error,** not an
  internal error.
- **`search` on `entries_list`, `terms_list`, and `submissions_list` matches
  plain text on the default Stache driver.** `%` and `_` used to act as
  wildcards, so a search for `50%` also found `500`, and a search full of `%`
  could cost hundreds of times a normal one. SQL-backed repositories keep their
  wildcards, because SQLite and SQL Server have no default escape character.
- **`terms_update` refuses a localized slug another term has in that site,** as
  the Control Panel does.
- **New sets of a Replicator or Grid inside a Group get fresh ids** when their
  id is blank, `0`, or sent twice, like everywhere else.
- **A route cache built without the MCP routes no longer breaks the Control
  Panel.** Caching routes while MCP was off, or before installing the addon,
  and then turning it on made every Control Panel page answer 500. The Tools →
  MCP item now stays away until the cache is rebuilt.
- **The OAuth consent page shows a redirect URI with a user part as it is.** It
  used to print the part after the host wrong.
- **`mcp:setup` changes every line of a key that appears twice in `.env`.** It
  rewrote the first and reported "applied" while the last one kept winning.

### Security

- **`assets_upload` connects to the address the guard checked.** The guard read
  the host with `parse_url` and pinned it with `CURLOPT_RESOLVE`, which applies
  only when curl reads the same host. It doesn't always:
  `http://x{a}.example.com/` expands to `x.example.com`, and a leading `-` turns
  the pin into a removal. A caller with upload permission and a DNS name they
  control could reach loopback, private, and cloud metadata addresses and store
  the answer as an asset. Numeric hosts such as `2130706433` and `127.1` did the
  same where the site's resolver answers for them. The connection now uses
  `CURLOPT_CONNECT_TO`, which applies to whatever host curl reads, and always
  runs on curl. Guzzle's stream handler ignored the pin, for example where
  `curl_exec` and `curl_multi_exec` are disabled, so such a server now refuses URL
  uploads. Through an HTTP proxy, a download now sends `CONNECT` to the validated
  IP instead of the hostname, so a proxy that filters `CONNECT` by hostname or
  port can refuse it.
- **`assets_upload` refuses the address ranges its docs said it did.** The
  `source_url` guard relied on PHP's IP filter flags, and those pass
  `192.0.0.0/24`, `192.0.2.0/24`, `192.88.99.0/24`, `198.18.0.0/15`,
  `198.51.100.0/24`, `203.0.113.0/24`, and `224.0.0.0/4`, plus IPv6 ranges such
  as `100::/64`, `2001:2::/48`, `2001:db8::/32`, `3fff::/20`, `5f00::/16`,
  `fec0::/10`, and `ff00::/8`. A caller with upload permission could make the
  server request those addresses and store the answer as an asset. The guard
  now refuses them, whether they come as a literal address, a DNS result, or a
  redirect. For IPv6 it accepts only global unicast (`2000::/3`) and the NAT64
  form of an address it would accept. It also refuses `168.63.129.16`, the
  public address of Azure's wire server. A site that downloads from one of
  these ranges on its own network now gets a refusal, and
  `uploads.source_allowlist` doesn't override it.
- **A malformed `middleware` config no longer leaves the endpoint open.**
  `'middleware' => [['throttle:60,1']]` failed after the route was registered,
  so the endpoint stayed up without authentication: an anonymous caller could
  initialize and list the tools. Tool calls still refused without a user. Any
  entry that isn't a string now keeps the route unmounted.
- **Computed values no longer bypass exposure in `format=augmented`.** A
  computed value that returned a collection, paginator, or array of entries,
  terms, assets, or users came back in full: another collection's content, and
  other users' emails and super flags. It now gets the same treatment as a
  relation field: items outside the exposure are dropped, and the rest are
  reduced to stubs. Global variables and form submissions are handled the same
  way. A computed value that returns plain data, such as a string or an
  entry's `toArray()`, still comes back as written, because nothing marks where
  it came from: keep such values out of collections an agent may read.
- **A Bard image in an unexposed container no longer reveals whether the file
  exists.** `format=augmented` rendered an existing and a missing file
  differently, so writing guessed paths and reading them back told them apart.
- **The tree URL check inside the lock no longer names an entry the caller
  can't view.** When another call took a URL between the check and the save,
  the message named that entry's id. It now says the URL is taken.
- **The kill switch and the throttle now cover the whole endpoint.** GET and
  DELETE skipped both, and a POST that laravel/mcp refused for its headers was
  answered before them. With `enabled` off and routes cached, every verb is now
  a 404. Without a route cache the route isn't mounted at all, so Statamic's
  frontend route answers, with a 419 for a POST or DELETE. Every request counts
  against `statamic.mcp.middleware`.

## [1.0.0] - 2026-11-01

### Changed

- **Breaking: requires `laravel/mcp` 1.1.2 or newer.** 0.8, 0.9, and 1.x
  before 1.1.2 are no longer allowed. 1.1.2 is the first release that sends
  `X-Frame-Options: DENY` and `frame-ancestors 'none'` with the OAuth
  consent page, so another site can't load it in a hidden frame and trick a
  signed-in editor into clicking Approve. It also limits a client
  registration to ten redirect URIs of up to 1,024 characters and refuses a
  redirect URI with a user part, so a site that limits
  `mcp.redirect_domains` to `http://localhost` no longer registers
  `http://localhost:80@attacker.example/`. 0.9.7 has those two fixes as
  well. Token mode was not affected. [UPGRADE.md](UPGRADE.md) has
  the Composer commands.

### Fixed

- **Assets with braces in their filename can be uploaded, updated, and read.**
  Statamic keeps `{` and `}` in an uploaded filename, but Laravel can't put
  them in the Control Panel link, so building `cp_edit_url` threw. An upload
  or alt text change was stored and then answered "An internal server error
  occurred.", and `assets_get` couldn't read the asset at all. The tools now
  answer as usual, with `cp_edit_url` set to `null` for such an asset.
- **An asset path with `../` gets a tool error instead of an internal
  error.** `assets_get`, `assets_update`, and `assets_delete` given such a
  path, and a write whose assets field held one, answered "An internal
  server error occurred." and logged a stack trace on the host for every
  call. Statamic refuses the path before it touches the disk, so nothing
  outside the container was read or changed. Every tool, host tools
  included, now answers that the path may not contain `../`, and logs
  nothing.
- **`blueprints_get` without `blueprint` describes the blueprint
  `entries_create` uses.** On a collection whose first blueprint is hidden,
  the way the Control Panel retires one, it described the hidden blueprint,
  while `entries_create` and the CP build new entries on the first visible
  one. An agent then sent fields the new entry's blueprint doesn't have, or
  missed fields it requires. Collections now default to the first visible
  blueprint, as Statamic does. Taxonomies keep their first blueprint, which
  is what `terms_create` uses, and `available_blueprints` still lists hidden
  ones, which stay readable by handle. Collection and taxonomy blueprints
  now also include the fields an addon adds through `EntryBlueprintFound`
  or `TermBlueprintFound`, which `entries_create` and `terms_create`
  already accepted.
- **`entries_create` and `entries_localize` say when part of the save was
  not stored.** A host listener can cancel the draft's initial revision
  (`RevisionSaving`) or its place in the tree (`CollectionTreeSaving`)
  after the entry itself is saved. The tools reported a plain success then,
  and named a `parent` the tree never stored. The entry is still saved, as
  before, and the response now lists what is missing under `not_stored`.
  When the tree placement is missing, `parent` is left out, and the note
  says where Statamic lists the entry and, for a nested entry, how to move
  it. A tree another call kept locked is reported the same way. Before, it
  was an error after the entry was already saved, and the rest of the save
  never ran: no revision, no `EntrySaved` event, no localizations for
  collections that propagate.
- **Two conflicting moves no longer drop both entries from the tree.** When
  one `entries_update` call moved an entry under a second one while another
  call moved the second under the first, both calls reported "moved", and
  the saved tree lost both entries and their children. Those pages fell
  back to the top level, and on a `{parent_uri}/{slug}` route their URLs
  changed. Each call checked the move against the tree it read before it
  took the tree's lock. The move is now checked again on the tree read
  inside the lock: the parent, max_depth, a parent that became the root
  page, and the URL among the pages of its new level, as the Control
  Panel's tree save checks it. A move that no longer fits is refused, and
  the error says the entry was not moved and the rest of the update was
  saved. On a collection with revisions, a move is also checked under the
  entry's live slug, which the tree takes at once while a new slug waits
  in the working copy. `entries_create` and `entries_localize` check the
  parent and the URL again inside the lock too. The entry is saved by
  then, so a placement that no longer fits leaves it outside the tree,
  with a `not_stored` note that says why, and the reported URL is the one
  it has there.
- **New page builder sets get an id the tools can address.** The write
  tools stored the ids a caller gave new Replicator sets and Grid rows as
  they were sent, `0`, blank ids, and one id on two sets included. Every
  later by-id call then refused them, in `entries_get` with `fields`, in a
  keyed patch, and in a list naming the id. Now a new set whose id is
  blank, `0`, or shared with another new set of its list gets a fresh id
  from Statamic, as a set sent without an id does. This covers Replicator
  and Grid fields of the blueprint and the ones nested in their sets,
  written through `entries_create`, `entries_localize`, `terms_create`,
  `entries_update`, `terms_update`, and `globals_update`. `entries_update`
  lists the fresh ids under `added`, and the get tools show them. A unique
  id the caller picked is kept, stored sets keep theirs, and the write is
  accepted as before, as the Control Panel accepts it.

### Security

- **The OAuth consent page shows where approving sends you.** It named the
  client only by the name the client registered, which anyone who calls
  `POST /oauth/register` picks. A client registered as "Claude" with an
  attacker's redirect URI looked like Claude, and approving sent the
  authorization code to the attacker, who could exchange it for an MCP
  token as the editor who approved. The page now shows the redirect URI the
  code goes to, with its host in dark text and the rest in gray: the URI in
  the request, its port included for a loopback address, or the client's
  registered URI when the request names none. It reads the way the browser
  reaches it, in lowercase and with a non-Latin host in punycode, so
  `cIaude.ai` shows as `ciaude.ai` and a Cyrillic "а" as `xn--`. A URI over
  64 characters folds after its host into one line that expands on click;
  the host always shows in full. A consent view you published with
  `vendor:publish --tag=statamic-mcp-views` keeps the old markup; see
  [UPGRADE.md](UPGRADE.md).
- **OAuth mode accepts only tokens issued for Statamic users.** When the
  site's own API also runs on Passport with a second user provider, tokens
  from that provider's Passport clients signed in to MCP as the Statamic
  user with the same id. A client credentials token signed in as the
  Statamic user whose id equals the client id. The guard now refuses a
  token whose client belongs to another user provider. A client without a
  provider counts as bound to the provider Passport finds its users in:
  the `api` guard's for password and personal access clients, and the
  `passport.guard` consent guard's for the others. A client credentials
  token never resolves to a user. Clients from dynamic client registration
  and clients bound to the provider Statamic users come from work as before. The Connections
  page now lists and disconnects only connections of those clients, so a
  CP user no longer sees or revokes another provider's tokens. It also
  leaves out client credentials tokens, which belong to no user. Once the
  site held one, the page failed with a server error for super admins.
- **Client registration in OAuth mode is throttled.** Anyone could call
  `POST /oauth/register` without limit, and each call stored a new Passport
  client. The registration and discovery routes now carry their own
  `throttle:60,1`, as Passport's `/oauth/token` does, so one address
  registers at most 60 clients a minute. The limit counts per address
  whatever `middleware` says, so a site that throttles the MCP route by
  bearer token does not put every anonymous caller in one bucket. It shares
  its budget with `/oauth/token` and with the MCP route's default throttle.
- **The kill switch and the auth mode hold with cached routes.** Both were
  read only while routes registered, and a cached route list skips that.
  With routes cached, setting `STATAMIC_MCP_ENABLED=false` left the
  endpoint answering existing tokens, the Tools → MCP pages issuing new
  ones, and anonymous client registration open in OAuth mode. Switching
  `STATAMIC_MCP_AUTH` from `token` to `oauth` kept accepting static tokens.
  The endpoint and the OAuth routes now check both settings on every
  request, the Tools → MCP pages check `enabled`, and each answers 404 when
  the setting no longer matches what the route was built for. On a site
  that caches routes, run `php artisan optimize` after changing either
  setting to serve the new value. `mcp:doctor` now reports a route cache
  built for another auth mode, or while MCP was off, instead of calling the
  route mounted or blaming a boot failure.
- **The requests of one `source_url` download share 15 seconds, redirects
  included.** Each redirect hop had its own 15-second timeout and nothing
  limited the whole download, so a server that answered slowly and
  redirected three times held a PHP worker for about a minute per
  `assets_upload` call. Each hop now gets only the time left of one
  15-second budget, and once it runs out the upload stops with
  `source_url took longer than 15 seconds`, also when curl cuts off a hop at
  the deadline. The DNS lookup before each hop is not cut off by the budget.
- **`source_url` uploads no longer treat NAT64, 6to4, and Teredo addresses as
  public.** PHP's private and reserved range checks let these IPv6 forms of an
  IPv4 address through, so on a server with a NAT64 gateway or 6to4 relay a
  download could be routed to loopback or the cloud metadata endpoint. A NAT64
  address in the well-known prefix (`64:ff9b::/96`) is now judged by the IPv4
  address it carries, so servers on DNS64 networks that use it still download
  from public hosts. The local-use NAT64 prefix (`64:ff9b:1::/48`), 6to4
  (`2002::/16`), and Teredo (`2001::/32`) are refused. On a network whose
  DNS64 uses the local-use prefix, every IPv4-only host is now refused.
- **`blueprints_get` names only the collections, taxonomies, and asset
  containers MCP exposes.** An entries, terms, or assets field showed every
  handle its config names under `collections`, `taxonomies`, or `container`,
  and the assets example note named the container, even when
  `resources` leaves that resource out and every other tool answers its
  handle with "not found". Those lists now hold only the handles `resources`
  allows. A field that links nothing MCP exposes has no such key, a `null`
  example, and a note in `example_notes` saying MCP cannot fill it.
- **Writes no longer look up files in asset containers MCP does not
  expose.** Every write that validates an assets field checked a new path
  against the field's container and named the container in the error, so
  `entries_create` or `entries_update` told an existing file from a missing
  one in a container that `assets_list` answers with "not found". A new
  path for a field whose container `resources` leaves out is now refused
  before any lookup, with an error that names no container. A path the
  field already stores still goes back unchanged, also in a set or row
  without an id, matched by its position, and in a navigation branch's
  data, and `null` still clears the field.
- **`navigations_get` and `navigations_update` no longer reveal entries of
  collections MCP does not expose.** With `resources.collections` limited,
  `navigations_get` showed the title, URL, and status of every linked entry,
  drafts included, and listed every collection the navigation links.
  `navigations_update` accepted a new link to an entry of an unexposed
  collection and named the collection of any entry id it refused. Such a
  branch now reads `"resolved": {"status": "missing"}`, the same as a deleted
  entry, `collections` lists only exposed collections, and a new link to an
  entry outside the exposure gets the error a missing entry gets. An entry
  id the stored tree already links is kept as it is when its entry is
  unexposed or gone, as the Control Panel keeps it, so writing a tree back
  never drops a menu item the agent cannot see.
- **The unique-URL refusal names the entry that holds the URL only to a
  caller who can read it.** `entries_create`, `entries_update`, and
  `entries_localize` refuse a URL another entry of the site already has, in
  any collection, as the Control Panel does. The error named that entry's id
  and collection even when the collection is not exposed to MCP or the
  caller lacks `view {collection} entries`, while `entries_get` answers the
  same id with "not found" or a permission error. Such a caller now reads
  `URL '/blog/hello' is already taken`, as the Control Panel says it.
- **Rewriting a long set list costs time in proportion to its length.**
  `entries_update`, `terms_update`, and `globals_update` matched every set
  they were sent against the stored list by scanning that list, once to
  merge and once to validate, and a Bard value's sets were matched the
  same way. The cost grew with the square of the list. Each stored list is
  now read once into an index by id, so the cost grows with its length, as
  it does when the Control Panel saves the field. A stored set whose id is
  a list or another value with no string form no longer makes every later
  write of the field fail. Errors and results are otherwise unchanged.
- **Augmented `terms_get` no longer returns the entries tagged with the
  term.** Its `entries` key held every published entry carrying the term,
  from any collection and with all of its fields, and `entries_count`
  counted them. A role with only `view tags terms` could read entries of
  collections it has no permission on, or that `resources.collections`
  leaves out. No term screen in the Control Panel lists or counts a term's
  entries, so both keys are gone rather than filtered. **Breaking:**
  augmented `terms_get` has no `entries` or `entries_count` key.
- **Augmented `entries_get` and `terms_get` leave out related items outside
  the exposure.** With `resources` limited, relation fields still came back
  with the title, URL, and permalink of entries in unexposed collections and
  terms of unexposed taxonomies, and with the URL and alt text of assets in
  unexposed containers. That held inside Replicator, Grid, Group, and Bard
  sets, for link fields, and for links and images in Bard and Markdown
  text, while `entries_get` answers the same ids with "not found". Fields
  that point at a collection, taxonomy, navigation, form, or asset
  container, and an entry's `mount`, returned unexposed ones with their
  titles. A list now drops those items, a single relation reads `null`, a
  link field `{"url": null}`, and a link or image in text loses its URL, as
  for a deleted item. An exposed related item keeps its stub even when the
  caller can't view it, because the Control Panel's entry listing shows its
  title. **Breaking:** entries reached through keys Statamic adds itself,
  such as `parent`, come back as the same stub instead of the whole entry.
- **Augmented `entries_get` and `terms_get` show other users by id and
  name.** `updated_by` came back as the whole user, with email, super flag,
  roles, and last login, and an `author` field or any other users field
  carried the user's email. A role with only `view blog entries` or
  `view tags terms` read all of it, while the Control Panel shows a related
  user by name and never shows the email without `view users`. Every user
  in augmented output, inside sets too, is now `{id, name, api_url}`, with
  `email` added for a caller who has `view users`. **Breaking:**
  `updated_by` is that stub instead of the whole user.

## [0.9.1] - 2026-10-15

### Added

- **`blueprints_get` reads navigation blueprints.** `type: navigation` with a
  navigation handle returns the fields a branch's `data` takes in
  `navigations_update`, an example of one branch's data, and, on a
  Replicator or Bard field, its sets; `set` returns a set's fields and an
  example row, as it does for a page builder. A mega menu whose branches
  hold cards no longer leaves an agent guessing the card's field names from
  an existing branch: `navigations_get` listed the field as a replicator and
  nothing more, and `blueprints_get` refused the type. Reading takes
  `view {handle} nav`, like `navigations_get`. When the blueprint defines
  `title` or `url`, `example_notes` says to pass them as branch keys, not
  inside `data`.

## [0.9.0] - 2026-10-15

### Added

- **Find and replace in long text.** `entries_update`, `terms_update`, and
  `globals_update` take `{"find": "...", "replace": "..."}` for a Bard,
  Markdown, text, or textarea field, at the top level or inside a set
  addressed by id, and change only that piece of the stored text. Fixing a sentence no
  longer means sending the whole article back. `find` must match exactly
  once; in Bard it must sit inside one formatting run, so a link or bold
  mark is never lost, and the replacement keeps the run's marks. No match,
  several matches, or a match across formatting is an error that quotes the
  nearby text, so the agent can fix `find` without reading the field again.
  A text someone changed in the meantime no longer matches, so nothing is
  overwritten. Sent anywhere it can't reach, inside a Group or a new set, or
  to a global set without a blueprint, the object is refused instead of
  being stored.

### Changed

- **`entries_update`, `terms_update`, and `globals_update` are no longer
  marked idempotent.** Retrying a call that adds a set or inserts text
  applies it twice, so clients shouldn't retry them blindly.
- **Adding, removing, and reordering page builder blocks no longer costs the
  whole page.** A list sent for a Replicator or Grid field now merges by id:
  an item carrying a stored id merges into that set as the keyed object
  does, so `{"id": "wKJQXf2s"}` keeps it as it is, an item in another type
  replaces its set, any other item is a new set, and the sets left out are
  removed. Adding a block is the list of ids with the new block where it
  goes, about 1 KB on a page that took 29 KB to read and 29 KB to send back.
  `entries_update` returns the ids of new sets under `added`, so the next
  edit can address them without reading the page again. **Breaking:** a
  list item with a stored id no longer replaces that set whole, so a key
  left out of it stays; send `null` to clear one. A bare `{"id": ...}` the
  field doesn't store is refused, so a block someone removed in the
  meantime never comes back empty, and a list that names one set twice is
  refused.
- **`entries_get` returns the staged working copy by default**, as the
  Control Panel's edit form does. On a revision-enabled entry with staged
  changes, it used to return the live entry while `entries_update` built on
  the working copy, so an agent edited content it had never seen, and ids
  read from a live page builder could miss sets staged since.
  `working_copy: false` returns the live entry. `source` now comes back on
  every revision-enabled entry, saying which one you got. **Breaking:**
  agents that relied on the live entry by default now pass
  `working_copy: false`.
- **`entries_get` and `terms_get` preview any large value, not only Bard,
  Markdown, and page builders.** Any other value over 4 KB comes back as
  `{__preview, truncated, note}` unless it's requested via `fields`: an
  assoc array as its key count and first keys, a list as its item count, a
  string as its first 300 characters. That covers keys the blueprint no
  longer defines too, and `fields` can now name them in raw format. A 70 KB
  synced `array` field now reads as its key count and first keys. A Grid
  stays inline whatever its size. **Breaking:** a
  large `array`, `yaml`, `code`, `textarea`, or custom fieldtype value now
  takes a second call with `fields` to read in full.
- **Write results no longer echo what was just written.**
  - `navigations_update` returned the whole tree after a save, also for one
    added link. It now returns the result, the edit URL, and the ids of new
    branches under `added`.
  - `globals_update` and `terms_update` no longer return the saved data; read
    it with `globals_get` or `terms_get` when needed.
- **`assets_list`, `assets_get`, and `assets_upload` drop `id`, `basename`, and
  `folder`**, which repeat `path` and the container you passed. A page of 25
  assets is about 38% smaller. The asset tools take a container and this path.

### Fixed

- **Writes take back whatever a field already stores, as the Control Panel
  does.** A key counts as unknown only when the blueprint doesn't define it
  and the stored value doesn't hold it, at every level: page builder sets,
  Grid rows, Bard sets, and Groups. Stored keys a blueprint no longer
  defines, sets of removed types, and asset paths whose file is gone no
  longer block a write that leaves them as they are, and a set's stale key
  can now be changed or cleared by id.
- **Every unknown key is reported in one error**, instead of one set per
  attempt.

## [0.8.0] - 2026-10-13

### Added

- **Set-scoped writes for page builders.** Editing one block of a long page
  no longer costs the whole page. `entries_update`, `terms_update`, and
  `globals_update` take a Replicator or Grid field as an object keyed by set
  id, the id Statamic stores on every set and row, and merge into just those
  sets: `{"page_builder": {"wKJQXf2s": {"variant": "centered"}}}` changes
  one field of one block, and the page is then validated and saved the way
  the Control Panel saves it, so the other blocks keep their content, blocks
  someone edited in the Control Panel in between included. Send only the
  fields you change; a set fetched by id can go back whole, a key its
  blueprint no longer defines included, as long as that key is unchanged.
  Inside a set the merge is shallow too: each named field is replaced
  whole, a Group included. `null` removes a set, a Replicator or Grid nested
  in a set merges by id the same way, and an unknown id is an error that
  lists the existing sets with their types. A list still replaces the field
  wholesale, which is how sets are added, reordered, or given another type.
  Only what the agent sends is checked for unknown keys; a block that no
  longer passes its blueprint's rules still blocks the write, as it would in
  the Control Panel, and the error names it by id. Validation errors name a
  set by its id, `page_builder.wKJQXf2s.heading`, on every write that sends
  sets, and `enabled` must be a boolean. `null` clears a page builder; an
  empty list or object is refused, since PHP cannot tell `{}` from `[]` and
  `{}` would wipe the page. Bard sets keep their place inside the Bard value
  and are not addressable this way, and a global set without a blueprint
  refuses the object form, since nothing says which variable holds sets.
- **One set at a time from `entries_get` and `terms_get`.** `fields` takes
  `handle.<id>` and returns that set of a Replicator or Grid field as an
  object keyed by its id, the shape the update tools merge by id, so a round
  trip can never drop a sibling block. A blank id, the id `0`, which PHP
  reads as a list index, or an id that several sets share is an error that
  points at the whole field.

### Changed

- **Large page builders are read as an index.** `entries_get` and `terms_get`
  return a Replicator field over 4 KB of JSON as an index of its sets, each
  with `id`, `type`, `enabled` when it is off, and a short `preview` taken
  from the set's first text field, under the same `__preview` marker as a
  long Bard value. Sending the index back is refused with the by-id patch as
  the remedy. `fields: ["page_builder"]` fetches the whole field as before.
  Grid fields stay inline whatever their size: their rows read like a table,
  where an index of ids saves little. Agents that fetched a long page builder
  and sent it back whole get the index instead and one error that names the
  cheaper path.

## [0.7.2] - 2026-09-26

### Added

- **Form submissions.** `submissions_list` returns a form's submissions newest
  first, each with its full data, so a week of contact requests is one call.
  `search` is the Control Panel's search, a substring match over the text,
  textarea, and integer fields where `%` and `_` are wildcards, and `since`
  and `before` bound the date. `submissions_get` returns one submission by form and id, and
  `submissions_delete` removes one; like the other delete tools it exists
  only when `deletes` is on. Nothing creates or edits a submission, as in the
  Control Panel. Reading takes `view {form} form submissions` and deleting
  takes `delete {form} form submissions`; `configure forms` grants both, the
  way Statamic's form policies do. `statamic_overview` lists the forms you may
  read with `stores_submissions` and their submission count, and
  `blueprints_get` takes `type: form`. The new `resources.forms` config key
  exposes forms and defaults to `true`. A config you published before this
  release has no such key, so it exposes no forms until you add
  `'forms' => true`.

## [0.7.1] - 2026-09-25

### Changed

- The User column on Tools → MCP → Connections shows each user the way the
  Users listing does: their avatar, or their initials when they have none,
  next to their email, which links to the user. Rows of a deleted user still
  show its ID.

### Fixed

- `entries_create`, `entries_update`, and `entries_localize` accept a slug
  that another entry of the collection already has in that site, as the
  Control Panel does. Only the URL has to be unique, so a structured
  collection can hold `/products/overview` and `/services/overview`, and a
  collection without a route can hold two entries with the same slug. The
  tools refused the second one before, so an agent had to pick another slug,
  and that slug ended up in the page's URL. A URL another entry of the site
  already has is still refused, and a blueprint whose slug field sets
  `unique_entry_value` still keeps slugs unique.
- `entries_get` by collection + slug is an error when several entries of the
  site share the slug, and the error names each one with its id and URL so the
  agent can pass the id. Before, it returned one of them, which could be the
  wrong page. The lookup now checks the collection's view permission first.
- `entries_localize` no longer fails with an internal server error on a
  collection whose slugs are turned off. Like the Control Panel's Localize
  action, it gives the localization the origin's slug, and such an origin has
  none.
- `entries_create` gives an entry of a collection with slugs turned off no
  slug, as the Control Panel does, so Statamic names its file by its id. It
  used to make a slug from the title. `entries_create`, `entries_update`, and
  `entries_localize` refuse a `slug` for such an entry, since the Control
  Panel has no field to set one. A blueprint that adds a slug field of its own
  keeps slugs, as in the Control Panel.

## [0.7.0] - 2026-09-25

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
- `entries_get`, `entries_create`, and `entries_localize` return
  `localizations` on a collection in more than one site: for each site you can
  access, the id and status of the entry there, or null where it has none.
- `statamic_overview` lists each collection's `sites`, `propagate`, and
  `origin_behavior` on multisite, so an agent knows where `entries_create`
  already makes a localization in every site.
- `blueprints_get` reports `localizable` on every field of a multisite install:
  only such a field can hold a value of its own in a localization, so an agent
  knows what it can translate before it writes.
- **Tools → MCP** in the Control Panel, with its pages listed under it in the
  sidebar the way Utilities lists its own. **Guidelines** holds what 0.6.0
  kept in a global set: the site's voice and tone, and rows on how a
  collection's entries are put together. Only super admins can open it.
  **Connections** is the page that was the MCP Access utility.

### Changed

- **Breaking:** guidelines for agents moved out of the `guidelines` global set
  into Statamic's addon settings, which the Guidelines page edits:
  `resources/addons/statamic-mcp.yaml`, or the database when the site keeps
  addon settings there. The set sat in Globals next to the site's content, so
  editors took it for website content, and any agent acting as a super admin
  could rewrite what every other agent reads through `globals_update`. Agents
  still read the guidelines through `statamic_overview` and `blueprints_get`,
  in the same shape, but no tool writes them now. Rows switched off in the
  page's replicator no longer reach agents. Statamic runs addon settings
  through Antlers when it loads them, so the page rejects `{{`, Antlers and
  Blade component tags, and `@props`.

  To upgrade, run `php please mcp:guidelines` once where the content lives:
  locally on a flat-file site, then commit `resources/addons/statamic-mcp.yaml`
  and the deleted set files, or in production when globals live in a
  database. It copies the set to the Guidelines page and deletes the set and
  its blueprint. Until then, agents keep reading the set and the page shows a
  notice. The command keeps the set and says why when the page already has
  guidelines, when the set's text holds template code, or when its other
  sites hold text, which 0.6.0 never read. Then delete `guidelines` from a
  published `config/statamic/mcp.php`. See `docs/guidelines.md`.
- **Breaking:** the MCP Access utility is gone. Its page is Tools → MCP →
  Connections, which needs the Access MCP permission instead of the utility's
  own. Roles with Access MCP alone can now issue their own tokens there, the
  way OAuth mode already let them connect. Published copies of
  `utilities/mcp-tokens.blade.php` no longer apply.
- `entries_update` refuses a value of its own on a localization for a field the
  blueprint does not mark localizable, as the Control Panel does: it shows such
  a field read-only with the origin's value. The error names the origin entry
  to change instead and the blueprint to mark the field localizable in.
- `mcp:guidelines` no longer creates a global set. It moves a 0.6.0 set as
  described above, prints the Guidelines page's URL, and lists the blocks
  without instructions as before.

### Removed

- The `guidelines` config key, which named the global set.

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
  actually contained it).

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

[Unreleased]: https://github.com/danielgnh/statamic-mcp/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/danielgnh/statamic-mcp/compare/v0.9.1...v1.0.0
[0.9.1]: https://github.com/danielgnh/statamic-mcp/compare/v0.9.0...v0.9.1
[0.9.0]: https://github.com/danielgnh/statamic-mcp/compare/v0.8.0...v0.9.0
[0.8.0]: https://github.com/danielgnh/statamic-mcp/compare/v0.7.2...v0.8.0
[0.7.2]: https://github.com/danielgnh/statamic-mcp/compare/v0.7.1...v0.7.2
[0.7.1]: https://github.com/danielgnh/statamic-mcp/compare/v0.7.0...v0.7.1
[0.7.0]: https://github.com/danielgnh/statamic-mcp/compare/v0.6.0...v0.7.0
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
