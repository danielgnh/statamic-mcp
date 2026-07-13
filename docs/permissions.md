# Permissions cookbook

A restricted agent = **a dedicated Statamic user + a restricted role**. Manage it all
in the CP roles UI — nothing MCP-specific beyond the single `Access MCP` permission.

## How authorization works

Every MCP request is authenticated as a real Statamic user, and authorization is
always Statamic's native permission system. Four gates, in order:

1. **Read-only switch** — `read_only` hides all write/delete tools; handlers re-check
   on every call in case a client cached the old tool list.
2. **Exposure allowlist** — `resources` decides what exists as far as MCP is concerned.
3. **Native permissions on every call** — `view/edit/create/delete {handle} entries`
   (and term/global equivalents) via the user's roles. Changing publish state — in
   either direction — additionally requires `publish {handle} entries`, exactly like
   the CP. Non-default-site writes require `access {site} site` (the default site
   is never gated by a site permission). Denials name the missing permission and
   the remedy.
4. **Deletes off by default** — delete tools aren't registered unless you opt in.

Entry creates and updates save **drafts by default**: agents draft, humans publish
(unless you explicitly pass `published: true` and the user holds the publish
permission). On revision-enabled collections publish state is CP-owned entirely:
explicit `published` values are rejected, edits become working copies, and the live
entry is never touched. Terms and globals have no draft state — writes to them are
live immediately.

## Recipes

**A drafting agent for the blog (no publishing, no deleting):**

1. CP → Users → Roles → create role `content-agent` with permissions:
   `Access MCP`, `View blog entries`, `Edit blog entries`, `Create blog entries`.
2. CP → Users → create `claude@your-site.com` with role `content-agent`.
3. `php please mcp:token claude@your-site.com --name="Blog agent"`.

Every write this agent makes lands as a draft; it cannot publish, delete, or even see
other collections in `statamic_overview`.

**A read-only analyst:** either set `'read_only' => true` server-wide, or give the
agent's role only `Access MCP` + `View … entries` permissions — both work, use the
role when other agents on the same server still need write access.

**A publishing agent:** add `Publish blog entries` to the role. Transitions to
`published: true` now succeed (on non-revision collections — revisions publish from
the CP).

**A cleanup agent that may delete:** set `'deletes' => true` in the config **and**
add `Delete blog entries` to the role. Both gates must open.

**Scoping to one site of a multi-site install:** grant `Access {site} site` for only
that site — writes to other non-default sites are denied with the exact missing
permission named. **Know the exemption:** the default site is never gated by a site
permission, so granting only `Access fr site` still leaves default-site content open
to the agent's content permissions. To truly confine an agent, scope its **content**
permissions instead, or make the agent's target site the default. (Site permissions
only exist on multi-site installs.)
