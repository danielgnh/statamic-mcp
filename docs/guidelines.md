# Guidelines for agents

`blueprints_get` tells an agent which fields a blueprint has. Guidelines tell it
how your site uses them: which block opens a page, which never repeats, how
formal the copy is. You write them in two places.

## Block instructions

Every set in a Replicator or Bard field already has an `instructions` key.
`blueprints_get` returns it along with the set's display name, group, and
fields, so a page builder block documents itself:

```yaml
page_builder:
  type: replicator
  sets:
    headers:
      display: Headers
      sets:
        hero:
          display: Hero
          instructions: 'First block on landing and service pages, never twice on one page. Follow it with Features or Text.'
          fields:
            -
              handle: heading
              field:
                type: text
                instructions: 'The page promise, under 8 words.'
```

The agent receives:

```json
{
  "handle": "page_builder",
  "type": "replicator",
  "sets": [
    {
      "handle": "hero",
      "display": "Hero",
      "group": "Headers",
      "instructions": "First block on landing and service pages, never twice on one page. Follow it with Features or Text.",
      "fields": [
        { "handle": "heading", "type": "text", "required": false, "rules": ["nullable"], "instructions": "The page promise, under 8 words." }
      ]
    }
  ]
}
```

Write instructions in the set's settings in the Control Panel, or in the
blueprint or fieldset YAML. Editors see the same text when they add a block, so
write it for both: a sentence or two on when to use the block and what goes in
it. A rule about one field belongs in that field's instructions.

A block without instructions still reaches the agent with its name, group, and
fields. Fieldset imports inside a set are resolved, and sets nested inside
other sets are listed under their field. A set marked hidden comes back with
`"hidden": true`. Agents leave it in existing content and never add a new one.

Don't give a set a custom key for agents. When someone saves a Replicator's
settings in the Control Panel, Statamic rebuilds every set from a fixed list of
keys and drops the rest. `instructions` is on that list.

## Guideline files

Anything that isn't about one block goes in markdown files under
`resources/mcp/guidelines`:

```
resources/mcp/guidelines/
  site.md                        statamic_overview returns this
  collections/pages.md           blueprints_get returns this for every pages blueprint
  collections/pages/landing.md   and this only for the landing blueprint
  taxonomies/tags.md
  globals/settings.md
```

`site.md` is for voice and tone. A collection's file is for how an entry is put
together: which blocks come first, how many a page usually has, which existing
entry is a good example. When a collection file and a blueprint file both
exist, the agent gets both, collection first.

HTML comments never reach an agent. Use them for notes to yourself. A file that
holds nothing but a comment, like a fresh stub, sends nothing.

The files follow the same permissions as the content. An agent only gets a
collection's guidelines if it may read that collection's blueprint. `site.md`
goes to every agent. Keep the files short, because agents receive them on every
call.

To keep the files somewhere else, set `guidelines_path` in
`config/statamic/mcp.php`.

## `mcp:guidelines`

```bash
php please mcp:guidelines
```

The command creates `site.md` and a file for each collection MCP exposes. It
never overwrites a file, so run it again whenever you add a collection. Then it
lists the blocks that have no instructions:

```
  Created  resources/mcp/guidelines/site.md
  Created  resources/mcp/guidelines/collections/pages.md

  11 of 14 blocks have instructions. Agents only see the fields of these, so add instructions to each set in its blueprint or fieldset:
+--------------+----------------------------+-----------------------------------------------------+
| Block        | Field                      | Blueprints                                          |
+--------------+----------------------------+-----------------------------------------------------+
| logo_wall    | page_builder               | collections.landing.landing, collections.pages.page |
| testimonials | page_builder               | collections.landing.landing, collections.pages.page |
| text         | page_builder.columns.items | collections.pages.page                              |
+--------------+----------------------------+-----------------------------------------------------+
```

A block that several blueprints share through a fieldset is listed once. Hidden
sets don't count.

With Laravel Boost, `boost:install` adds a `statamic-mcp-guidelines` skill. Ask
your coding agent to fill in the missing instructions, and it reads each
block's template to write them.
