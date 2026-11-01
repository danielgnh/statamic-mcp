# Upgrading

## From 0.9 to 1.0

1.0.0 adds no config keys or migrations. Two things change around it: the
addon needs `laravel/mcp` 1.1.2 or newer, and augmented reads return related
entries and users in a shorter form. A site that published the OAuth consent
view has one more step.

### Update the packages

Your `composer.json` pins a 0.x release, so require the new major and let
Composer move `laravel/mcp` along with it:

```bash
composer require danielgnh/statamic-mcp:^1.0 --with-all-dependencies
```

If Composer stops with a conflict, something else holds `laravel/mcp` at 0.x,
and `composer why laravel/mcp` names it:

- **Your own `composer.json`**, if you installed `laravel/mcp` yourself, as
  Laravel's MCP docs do. Composer says the release `conflicts with your root
  composer.json require`. Require both at once:

  ```bash
  composer require danielgnh/statamic-mcp:^1.0 laravel/mcp:^1.1.2 --with-all-dependencies
  ```

- **`laravel/boost` older than 2.9.0**, the first Boost that allows
  `laravel/mcp` 1.x. Composer says `laravel/mcp[v1.1.2]` was not loaded
  because it `conflicts with another require`. Update Boost in the same step:

  ```bash
  composer require danielgnh/statamic-mcp:^1.0 --no-update
  composer update danielgnh/statamic-mcp laravel/boost --with-all-dependencies
  ```

- **Another package**: once it has a release that allows `laravel/mcp` 1.x,
  update it the same way as Boost.

If the app serves MCP servers of its own next to this addon, they move to
`laravel/mcp` 1.x too; its
[1.0.0 release notes](https://github.com/laravel/mcp/releases/tag/v1.0.0)
list what changed.

### Rebuild a cached route list

The MCP and OAuth routes now check `enabled` and `auth` on every request,
and client registration is throttled. A route cache built before the update
keeps serving the old routes. If the site caches routes and your deploy
doesn't rebuild that cache, run `php artisan route:cache` after updating.

### A published consent view

The consent page now shows where approving sends the editor, so a client
registered as "Claude" with someone else's redirect URI can't pass for
Claude. If you published the view with
`vendor:publish --tag=statamic-mcp-views`, your copy in
`resources/views/vendor/statamic-mcp/oauth/authorize.blade.php` doesn't show
it. Publish the view again with `--force` and reapply your changes. To patch
your copy by hand instead, take all three parts from the addon's view, or
the page fails: the two `$redirect…` lines at the end of the `@php` block at
the top, the `.ui-redirect` styles, and the `@if ($redirect)` block above
"Signed in as".

### What agents and integrations see

- With `format: augmented`, `entries_get` and `terms_get` return a related
  entry that Statamic adds itself, such as `parent`, in Statamic's short
  form (`id`, `title`, `url`, `permalink`, `api_url`) instead of the whole
  entry. Every user, `updated_by` included, is `{id, name, api_url}`, with
  `email` only for a caller who has `view users`. Augmented `terms_get` no
  longer has `entries` or `entries_count`. Raw reads, the default, are
  unchanged.
- When `resources` limits what MCP exposes, agents no longer see the rest
  anywhere: not in navigation menus, related items, blueprint field options,
  or asset paths. A new link to something outside the exposure is refused.
- Coming from `laravel/mcp` 0.x, a JSON-RPC error no longer comes back with
  HTTP 200: an unknown method gets 404, an internal error 500, and any other
  error, an unknown tool included, 400, each with the JSON-RPC error in the
  body. MCP clients handle
  this; check scripts that call the endpoint and expect 200.
- In OAuth mode, only tokens issued for Statamic users sign in. A token from
  a Passport client of another user provider, or a client credentials token,
  no longer does. Client registration allows 60 requests a minute per
  address, a budget it shares with `/oauth/token` and the MCP route's
  default throttle.

The [CHANGELOG](CHANGELOG.md) has the full list, with the fixes that need no
action.

## From an older 0.x release

Follow the steps above, then go through the CHANGELOG entries marked
**Breaking** for each release you skip. From 0.6 or older, move the
guidelines for agents with `php please mcp:guidelines`, as
[docs/guidelines.md](docs/guidelines.md#coming-from-060) describes. Then run
`php please mcp:doctor`: it names any step still missing, such as
`php artisan migrate` for an OAuth setup from before the key table.
