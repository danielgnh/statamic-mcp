# Permissions cookbook

A restricted agent = **a dedicated Statamic user + a restricted role**. Manage it all
in the CP roles UI — nothing MCP-specific beyond the single `Access MCP` permission.

## How authorization works

Every MCP request is authenticated as a real Statamic user, and authorization is
always Statamic's native permission system. Four gates, in order:

1. **Read-only switch** — `read_only` hides all write/delete tools; handlers re-check
   on every call in case a client cached the old tool list. `entries_preview` stays,
   because a preview link changes no content.
2. **Exposure allowlist** — `resources` decides what exists as far as MCP is concerned.
3. **Native permissions on every call** — `view/edit/create/delete {handle} entries`
   (and term/global equivalents) via the user's roles. Publish state changes only
   through `entries_publish` and `entries_unpublish`, both gated on
   `publish {handle} entries`, the same permission the CP checks. `entries_preview`
   needs `edit {handle} entries`, the permission the CP checks for Live Preview.
   Non-default-site writes require `access {site} site` (the default site is never
   gated by a site permission). Denials name the missing permission and the remedy.
4. **Deletes off by default** — delete tools aren't registered unless you opt in.

Entry creates and updates **never publish**. Creates save drafts. On revision-enabled
collections, edits to a live entry become working copies and the live entry is never
touched. Without revisions, an edit to a live entry saves straight to it, as it would
in the CP. Going live is a separate tool, `entries_publish`, and it needs the publish
permission. Two things follow from that split. A role without the publish permission
cannot publish through MCP at all, whatever the agent sends. And because publishing is
its own tool, MCP clients ask about it separately: you can allow `entries_update` for
a session and still approve each publish by hand. Terms and globals have no draft
state, so writes to them are live immediately.

Agents check how their drafts look with `entries_preview`. It returns a Live Preview
URL, the same kind the CP opens. Anyone who has the URL can view that entry until the
link expires an hour later, so treat it as you would the draft itself. The token opens
only that one entry, and other drafts still return 404 with it.

## Recipes

**A drafting agent for the blog (no publishing, no deleting):**

1. CP → Users → Roles → create role `content-agent` with permissions:
   `Access MCP`, `View blog entries`, `Edit blog entries`, `Create blog entries`.
2. CP → Users → create `claude@your-site.com` with role `content-agent`.
3. `php please mcp:token claude@your-site.com --name="Blog agent"`.

Entries this agent creates are drafts, and it cannot publish, delete, or even see other
collections in `statamic_overview`. It can preview its drafts, because previews need
only the edit permission. Its edits to entries that are already live depend on
the collection. With revisions enabled, an edit becomes a working copy and the live
entry stays unchanged until someone publishes it. Without revisions, the edit saves
straight to the live entry, as it would in the CP. Statamic has no permission for
editing live entries specifically, so if this agent must never change live content,
enable revisions on the collection. Revisions need Statamic Pro.

**A read-only analyst:** either set `'read_only' => true` server-wide, or give the
agent's role only `Access MCP` + `View … entries` permissions — both work, use the
role when other agents on the same server still need write access. With `read_only` on,
a role that keeps edit permissions can still create preview links. View permissions
alone rule that out.

**A publishing agent:** add `Publish blog entries` to the role. `entries_publish` and
`entries_unpublish` now work, on revision-enabled collections too, where they promote
or apply the working copy the same way the CP does.

**A cleanup agent that may delete:** set `'deletes' => true` in the config **and**
add `Delete blog entries` to the role. Both gates must open.

**Scoping to one site of a multi-site install:** grant `Access {site} site` for only
that site — writes to other non-default sites are denied with the exact missing
permission named. **Know the exemption:** the default site is never gated by a site
permission, so granting only `Access fr site` still leaves default-site content open
to the agent's content permissions. To truly confine an agent, scope its **content**
permissions instead, or make the agent's target site the default. (Site permissions
only exist on multi-site installs.)
