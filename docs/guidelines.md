# Guidelines for agents

`blueprints_get` tells an agent which fields a blueprint has. Guidelines tell it
how your site uses them: which block opens a page, which never repeats, how
formal the copy is. Each rule lives in one place: on the block, field, or
section it is about, or in a global set for everything wider than a blueprint.

## Block instructions

Every set in a Replicator or Bard field already has an `instructions` key.
`blueprints_get` lists it with the set's display name and group, so an agent
can pick the right block:

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
  "required": false,
  "rules": ["array", "nullable"],
  "sets": [
    {
      "handle": "hero",
      "display": "Hero",
      "group": "Headers",
      "instructions": "First block on landing and service pages, never twice on one page. Follow it with Features or Text."
    }
  ]
}
```

To fill a block, the agent calls `blueprints_get` again with `"set": "hero"`
and gets the set's fields and an example row:

```json
{
  "type": "collection",
  "handle": "pages",
  "blueprint": "page",
  "set": {
    "handle": "hero",
    "display": "Hero",
    "group": "Headers",
    "instructions": "First block on landing and service pages, never twice on one page. Follow it with Features or Text.",
    "fields": [
      { "handle": "heading", "type": "text", "required": false, "rules": ["nullable"], "instructions": "The page promise, under 8 words." }
    ]
  },
  "example": { "type": "hero", "heading": "Example text" }
}
```

Write instructions in the set's settings in the Control Panel, or in the
blueprint or fieldset YAML. Editors see the same text when they add a block, so
write it for both: a sentence or two on when to use the block and what goes in
it. A rule about one field belongs in that field's instructions.

A block without instructions still reaches the agent, but only by its name and
group until the agent looks it up. The lookup resolves fieldset imports inside
the set and lists the sets nested in it by name, which the agent looks up the
same way. A set marked hidden comes back with `"hidden": true`. Agents leave it
in existing content and never add a new one.

Don't give a set a custom key for agents. When someone saves a Replicator's
settings in the Control Panel, Statamic rebuilds every set from a fixed list of
keys and drops the rest. `instructions` is on that list.

## Tab and section instructions

A tab and a section of a blueprint take `instructions` too, like a set. The
Control Panel shows them above the fields, and `blueprints_get` returns the
tabs that carry any, with the handles of the fields under them:

```yaml
tabs:
  main:
    display: Page
    sections:
      -
        display: Basics
        instructions: 'One page per service. Follow bike-rental-nazare: an intro whose bold first sentence states the offer and price, then the blocks.'
        fields:
          -
            handle: title
            field:
              type: text
```

```json
"tabs": [
  {
    "handle": "main",
    "display": "Page",
    "fields": ["title", "intro", "page_builder"],
    "sections": [
      { "display": "Basics", "instructions": "One page per service. Follow bike-rental-nazare: an intro whose bold first sentence states the offer and price, then the blocks.", "fields": ["title", "intro"] }
    ]
  }
]
```

A note about one blueprint goes in its first section: what one entry is, which
existing entry to follow, what its page builder usually holds. The Control
Panel keeps section instructions when someone saves the blueprint there; a
tab's only survive in a blueprint you edit as YAML.

## The guidelines global set

Anything that isn't about one field, block, or blueprint goes in a global set
called `guidelines`, which `mcp:guidelines` creates. The people who run the
site edit it in the Control Panel under Globals, with no deploy, and an agent
whose user may edit the set can change it through `globals_update`: an admin
can ask an agent to rewrite the voice after a week of use. It has two fields:

- `site`: voice and tone, words to use or avoid, how formal to be, rules that
  hold for every collection. `statamic_overview` returns it, so every agent
  reads it first.
- `resources`: rows that name collections and taxonomies and say how their
  entries are put together: which blocks come first, which never repeat, how
  many a page usually has, page shapes to copy with a named entry for each.
  `blueprints_get` returns the rows naming the requested collection or
  taxonomy, in their order, with every blueprint of it.

Never restate a field, block, or section there. A rule about one field belongs
in that field's `instructions`, which agents already get and editors already
see, and a copy in the global set goes stale the first time the blueprint
changes.

The set is read from its first site. Agents get the rows for a collection only
when they may read that collection's blueprint; `site` goes to every agent.
Keep both short, because agents receive them each time they read the overview
or a whole blueprint.

To keep the guidelines in a set with another handle, set `guidelines` in
`config/statamic/mcp.php`.

## `mcp:guidelines`

```bash
php please mcp:guidelines
```

The command creates the `guidelines` global set with its blueprint, never a
second time, then lists the blocks that have no instructions:

```
  Created  the guidelines global set.
  Open it in the Control Panel under Globals to write the site's voice and how
  its entries are put together.

  11 of 14 blocks have instructions. Agents see only the name of the rest
  until they look one up, so add instructions to each set in its blueprint or
  fieldset:

  In collections.landing.landing, collections.pages.page
    page_builder: logo_wall, testimonials

  In collections.pages.page
    page_builder.columns.items: text
```

A block that several blueprints share through a fieldset is listed once. Hidden
sets don't count, and neither do the rows of the guidelines set itself.
