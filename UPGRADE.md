# Upgrading

## From 0.9 to 1.0

1.0.0 adds no config keys, migrations, or views. Two things change around it:
the addon needs `laravel/mcp` 1.1.2 or newer, and augmented reads return
related entries and users in a shorter form.

### Update the packages

Your `composer.json` pins a 0.x release, so require the new major and let
Composer move `laravel/mcp` along with it:

```bash
composer require danielgnh/statamic-mcp:^1.0 --with-all-dependencies
```

If Composer stops with `found laravel/mcp[v1.1.2] but these were not loaded,
likely because it conflicts with another require`, another package holds
`laravel/mcp` at 0.x, and `composer why laravel/mcp` names it. It is usually
`laravel/boost` older than 2.9.0, the first Boost that allows `laravel/mcp`
1.x. Update both at once:

```bash
composer require danielgnh/statamic-mcp:^1.0 --no-update
composer update danielgnh/statamic-mcp laravel/boost --with-all-dependencies
```

For another package, put its name in place of `laravel/boost` once it has a
release that allows `laravel/mcp` 1.x. If the app serves MCP servers of its own
next to this addon, they move to `laravel/mcp` 1.x too; its
[1.0.0 release notes](https://github.com/laravel/mcp/releases/tag/v1.0.0)
list what changed.

### Rebuild the route cache

The MCP and OAuth routes now check `enabled` and `auth` on every request,
and client registration has its own throttle. A route cache built before
the update keeps serving the old routes, so if your deploy doesn't rebuild
it already, run:

```bash
php artisan optimize
```

### What agents and integrations see

- With `format: augmented`, `entries_get` and `terms_get` return a related
  entry that Statamic adds itself, such as `parent`, in Statamic's short
  form (`id`, `title`, `url`, `permalink`, `api_url`) instead of the whole
  entry. Every user, `updated_by` included, is `{id, name, api_url}`, with
  `email` only for a caller who has `view users`. Augmented `terms_get` no longer has `entries`
  or `entries_count`. Raw reads, the default, are unchanged.
- When `resources` limits what MCP exposes, agents no longer see the rest
  anywhere: not in navigation menus, related items, blueprint field options,
  or asset paths. A new link to something outside the exposure is refused.
- In OAuth mode, only tokens issued for Statamic users sign in. A token from
  a Passport client of another user provider, or a client credentials token,
  no longer does. Client registration allows 60 calls a minute per address.

The [CHANGELOG](CHANGELOG.md) has the full list, with the fixes that need no
action.

## From an older 0.x release

Follow the steps above, then go through the CHANGELOG entries marked
**Breaking** for each release you skip. From 0.6 or older, move the
guidelines for agents with `php please mcp:guidelines`, as
[docs/guidelines.md](docs/guidelines.md#coming-from-060) describes. Then run
`php please mcp:doctor`: it names any step still missing, such as
`php artisan migrate` for an OAuth setup from before the key table.
