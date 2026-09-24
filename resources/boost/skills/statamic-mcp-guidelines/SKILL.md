---
name: statamic-mcp-guidelines
description: Teach AI agents that edit a Statamic site through the Statamic MCP addon how the site builds its pages — instructions on page builder blocks (Replicator and Bard sets) and markdown guideline files under resources/mcp/guidelines.
---

# Statamic MCP Guidelines

## When to use this skill

Use this skill when the developer wants agents working through MCP to follow the
site's conventions: which blocks go where, how a page is structured, the site's
voice. Also use it when `php please mcp:guidelines` lists blocks without
instructions.

## Start with the command

```shell
php please mcp:guidelines
```

It creates `resources/mcp/guidelines/site.md` and one file per exposed
collection, never overwriting one. Then it prints a table of blocks that have
no instructions: the set handle, the field path, and the blueprints using it.

## Write block instructions

For each block in the table:

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

## Write the guideline files

- `site.md`: voice and tone, words to use or avoid, how formal to be.
- `collections/{handle}.md`: how an entry is put together. Typical block order,
  blocks that are required or never repeat, an existing entry worth copying.
- `collections/{handle}/{blueprint}.md`: the same for one blueprint only.
- `taxonomies/{handle}.md` and `globals/{handle}.md` work the same way.

Agents never see HTML comments, so write outside the stub's comment block or
replace it. Base the content on the templates and the site's existing entries.
Ask the developer about anything you can't infer, like tone or brand rules.
Keep every file short, because agents receive it on every call.

## Check the result

Run `php please mcp:guidelines` again. When every block is covered it prints
"All N blocks have instructions."
