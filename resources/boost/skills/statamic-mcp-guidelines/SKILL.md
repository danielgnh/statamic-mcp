---
name: statamic-mcp-guidelines
description: Teach AI agents that edit a Statamic site through the Statamic MCP addon how the site builds its pages — instructions on blueprint fields, sections, and page builder blocks (Replicator and Bard sets), and the guidelines global set for the site's voice and how entries are put together.
---

# Statamic MCP Guidelines

## When to use this skill

Use this skill when the developer wants agents working through MCP to follow the
site's conventions: which blocks go where, how a page is structured, the site's
voice. Also use it when `php please mcp:guidelines` lists blocks without
instructions.

## Where each kind of guidance lives

Agents get everything through `statamic_overview` and `blueprints_get`, so
guidance goes where those tools read it. Each rule lives in exactly one place:

| Guidance | Where it goes | Who edits it |
| --- | --- | --- |
| What one field holds, its format and limits | the field's `instructions` in the blueprint or fieldset | the Control Panel's blueprint editor, or the YAML |
| When to use a block and where it goes on a page | the set's `instructions` | the same |
| How one blueprint's entry is put together, which entry to follow | the `instructions` of the blueprint's first section | the same |
| How a collection's entries are put together, page shapes, block order | a row in the `guidelines` global set | site admins in the Control Panel, agents through `globals_update` |
| Voice and tone for the whole site | the `site` field of the `guidelines` global set | the same |

Never restate a field, set, or section in the global set. `blueprints_get`
already returns their instructions, and a copy goes stale the first time the
blueprint changes.

## Start with the command

```shell
php please mcp:guidelines
```

It creates the `guidelines` global set with its blueprint, never a second
time. Then it lists the blocks that have no instructions, grouped under the
blueprints that share them, one line per field with the names of its sets.

## Write block instructions

For each block the command lists:

1. Find where the set is defined. Search `resources/fieldsets/` and
   `resources/blueprints/` for the set handle under a `sets:` key. Page
   builders are usually a fieldset imported into several blueprints, so edit
   the fieldset.
2. Find the block's template to learn what it renders. Page builders usually
   render each set from a partial named after its handle, for example
   `resources/views/page_builder/_hero.antlers.html` or a Blade view. Search
   the views for the handle.
3. Add `instructions:` to the set, next to `display:`. Write one or two
   sentences: when to use the block, where it goes on a page, what it should
   not sit next to. Editors see this text in the Control Panel too, so write it
   for people as well.
4. Put a rule about a single field in that field's `instructions`, not the
   set's.

Never add a custom key such as `mcp:` or `ai_instructions:` to a set. When
someone saves a Replicator's settings in the Control Panel, Statamic rebuilds
every set from a fixed list of keys and drops the rest. `instructions` is on
that list.

## Write field and section instructions

Walk the blueprints of the exposed collections and taxonomies:

- Every field a writer fills gets `instructions` when its name doesn't say
  enough: format, length, what a good value looks like, where the assets for it
  live. Fields agents must not touch say so.
- A note about the whole blueprint goes in the `instructions` of its first
  section: what one entry is, which existing entry to follow, what its page
  builder usually holds. The Control Panel shows it above the fields and keeps
  it when the blueprint is saved there. A tab's `instructions` works the same
  way but only survives in a blueprint edited as YAML, so prefer sections.

## Fill the guidelines global set

Open the set in the Control Panel under Globals, or write its YAML under
`content/globals`:

- `site`: voice and tone, words to use or avoid, how formal to be, and rules
  that hold for every collection. `statamic_overview` returns it, so every
  agent reads it first.
- `resources`: one row per group of collections and taxonomies that share
  rules. Pick them in the row, then write how their entries are put together:
  which blocks come first, which never repeat, how many a page usually has,
  page shapes to copy with a named entry for each. `blueprints_get` returns
  the rows naming the requested collection or taxonomy, in their order.

Base the content on the templates and the site's existing entries. Ask the
developer about anything you can't infer, like tone or brand rules. Keep every
text short, because agents receive it on every call.

## Check the result

Run `php please mcp:guidelines` again. When every block is covered it prints
"All N blocks have instructions." Then call `blueprints_get` for one
collection and read it as the agent would: everything it needs should be there
once.
