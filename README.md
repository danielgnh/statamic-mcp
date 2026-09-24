# Statamic MCP

[![Latest Version](https://img.shields.io/packagist/v/danielgnh/statamic-mcp)](https://packagist.org/packages/danielgnh/statamic-mcp)
[![Tests](https://github.com/danielgnh/statamic-mcp/actions/workflows/tests.yml/badge.svg)](https://github.com/danielgnh/statamic-mcp/actions/workflows/tests.yml)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE.md)

Statamic MCP lets AI clients like Claude Code, Cursor, claude.ai, and ChatGPT read and write your Statamic 6 content: entries, taxonomy terms, globals, assets, and navigation menus. Every request runs as a real Statamic user, so the roles you already manage in the Control Panel decide what an agent can do.

It's built on Laravel's [`laravel/mcp`](https://laravel.com/docs/mcp) package. Until 1.0, a minor release can contain breaking changes, and [CHANGELOG.md](CHANGELOG.md) lists every one.

## Requirements

- PHP 8.3 or newer
- Statamic 6 on Laravel 12 or 13
- `laravel/passport` and a database for Passport's tables, for OAuth mode only

## Installation

```bash
composer require danielgnh/statamic-mcp
```

The endpoint is now live at `/mcp/statamic`. It rejects any request without a valid token, so nothing is reachable until you issue one.

## Connecting a client

Clients that let you set an `Authorization` header use token mode, the default. That includes Claude Code, Cursor, and the MCP Inspector. Connector clients that only take a URL, like claude.ai, Claude Desktop, and ChatGPT, need OAuth mode.

### Token mode

Issue a token for the Statamic user the agent will act as:

```bash
php please mcp:token you@example.com --name="Claude Code"
```

The command prints the token once, then a ready-to-paste command for Claude Code and a config block for Cursor:

```bash
claude mcp add --transport http statamic https://example.com/mcp/statamic \
  --header "Authorization: Bearer mcp_..."
```

The user also needs the Access MCP permission. Grant it on their role in the Control Panel. Super admins already have it, and `mcp:token` warns you when it's missing.

Start Claude Code and ask which collections your site has.

### OAuth mode

```bash
php please mcp:setup --oauth
```

The wizard installs Laravel Passport, sets `STATAMIC_MCP_AUTH=oauth`, runs the migrations, and creates Passport's keys. It asks before each step and finishes by running `mcp:doctor`. Then add `https://example.com/mcp/statamic` as a connector in your client. The client registers itself and sends you through a Statamic login and a consent screen.

Your users stay where they are. File-based users work, and the wizard never touches your user model or `config/auth.php`. Passport only needs a database for its own tables, and SQLite is fine. Connector clients reach your site from the internet, so it needs a public HTTPS URL.

To deploy, set `STATAMIC_MCP_AUTH=oauth` in each environment and run `php artisan migrate --force` as usual. The keys live in the database, so there is no key step. [docs/oauth.md](docs/oauth.md) covers manual setup, the consent screen, and disconnecting clients.

`mcp:setup` handles token mode too, and `--yes` runs it unattended. Laravel Boost users get guidelines on `boost:install` that teach coding agents to run it that way.

### Managing tokens

```bash
php please mcp:token you@example.com --expires-days=90   # issue
php please mcp:tokens                                     # list
php please mcp:token:revoke {id}                          # revoke
```

The package stores only a SHA-256 hash of each token, in `storage/statamic/mcp/tokens.yaml`, so token mode needs no database. A deleted user's tokens stop working.

Users can manage their own tokens in the Control Panel under Tools → Utilities → MCP Access. Their role needs the MCP Access permission from the Utilities group.

## Permissions

The token is the user. There are no API scopes and no second access list. Every tool call checks the acting user's Statamic permissions.

To restrict an agent, give it its own user and role. A drafting agent for the blog looks like this:

1. Create a role with Access MCP, View blog entries, Edit blog entries, and Create blog entries.
2. Create the user `claude@example.com` with that role.
3. Run `php please mcp:token claude@example.com --name="Blog agent"`.

That agent can create blog drafts and edit blog entries. It can't publish, delete, or see any other collection. If the blog blueprint has an author field, it can only edit entries it's an author of, which includes the ones it creates. That's the same rule the Control Panel applies.

Three config options restrict every user at once. `read_only` hides all write tools. `resources` limits which collections, taxonomies, global sets, asset containers, and navigations MCP can reach. The delete tools don't exist until you set `deletes` to `true`, and the user still needs the matching delete permission.

[docs/permissions.md](docs/permissions.md) has more recipes, including read-only, publishing, and multi-site agents.

## Drafts and publishing

`entries_create` always saves a draft, and `entries_update` never changes an entry's published status. What an edit does to a live entry depends on the collection:

- With revisions enabled, the edit becomes a working copy, the same one the Control Panel creates. Visitors keep seeing the live version until someone publishes it. Moving an entry with `parent` is the exception. Working copies don't store an entry's place in the tree, so the move is live at once, as in the Control Panel's tree view.
- Without revisions, the edit saves straight to the entry. If the entry is live, visitors see the change right away, same as saving it in the Control Panel.

Publishing is its own tool, `entries_publish`, and it needs the collection's publish permission. Because it's a separate tool, your MCP client asks you about it separately. You can let an agent call `entries_update` all session and still approve each publish yourself.

To see how a draft or working copy looks, an agent calls `entries_preview`. It returns a Live Preview URL that renders the entry through your templates. Anyone with the URL can open it for an hour, so the agent can fetch the page itself and check its work before anyone publishes.

Terms, globals, assets, and navigations have no draft state. Writes to them go live immediately.

## Tools

| Area | Tools |
|---|---|
| Discovery | `statamic_overview`, `blueprints_get` |
| Entries | `entries_list`, `entries_get`, `entries_create`, `entries_update`, `entries_preview`, `entries_publish`, `entries_unpublish`, `entries_delete` |
| Taxonomy terms | `terms_list`, `terms_get`, `terms_create`, `terms_update`, `terms_delete` |
| Globals | `globals_get`, `globals_update` |
| Navigation | `navigations_get`, `navigations_update` |
| Assets | `assets_list`, `assets_get`, `assets_upload`, `assets_update`, `assets_delete` |

The server tells agents to call `statamic_overview` first. It lists the sites and resources the user can reach and what they may do in each. `blueprints_get` returns a blueprint's fields, the blocks of each page builder field, and a valid example payload. The three delete tools only exist when `deletes` is on.

[docs/tools.md](docs/tools.md) documents every tool, the upload limits, and how URL uploads block private network addresses.

## Guidelines for agents

`blueprints_get` tells an agent which fields a blueprint has. To teach it how your site uses them, write instructions on your page builder blocks and, if you want, a few markdown files:

- Each set in a Replicator or Bard field has an `instructions` key. `blueprints_get` lists it with the set's name, and editors see the same text when they add a block.
- `resources/mcp/guidelines/site.md` holds voice and tone. `statamic_overview` returns it.
- `resources/mcp/guidelines/collections/pages.md` describes how a page is put together. `blueprints_get` returns it with the collection's blueprints.

```bash
php please mcp:guidelines
```

The command creates those files without overwriting any, then lists the blocks that still have no instructions. [docs/guidelines.md](docs/guidelines.md) covers the details.

## Adding your own tools

The server is a `laravel/mcp` server class. Extend it, override `tools()`, and add, replace, or remove tools on the registry it receives:

```php
namespace App\Mcp;

use App\Mcp\Tools\EntriesList as SlimEntriesList;
use App\Mcp\Tools\NewsletterSend;
use Danielgnh\StatamicMcp\Server;
use Danielgnh\StatamicMcp\ToolRegistry;
use Danielgnh\StatamicMcp\Tools\AssetsUpload;
use Danielgnh\StatamicMcp\Tools\EntriesList;

class StatamicServer extends Server
{
    protected function tools(ToolRegistry $tools): void
    {
        $tools->add(NewsletterSend::class);
        $tools->replace(EntriesList::class, SlimEntriesList::class);
        $tools->remove(AssetsUpload::class);
    }
}
```

Then point the config at it:

```php
// config/statamic/mcp.php
'server' => App\Mcp\StatamicServer::class,
```

Your tools run behind the same authentication and Access MCP check as the built-in ones. `replace()` swaps a built-in tool for yours, usually a subclass that overrides `execute()` or `schema()` and keeps the name. `remove()` drops one. Extend `Danielgnh\StatamicMcp\Tools\Tool` to get the acting user and the permission helpers. [docs/tools.md](docs/tools.md#your-own-tools) has a complete tool, the helper reference, and a test.

## Configuration

```bash
php artisan vendor:publish --tag=statamic-mcp-config
```

This creates `config/statamic/mcp.php`.

| Key | Default | What it does |
|---|---|---|
| `enabled` | `true` | Kill switch. When `false`, the route is never registered. Set with `STATAMIC_MCP_ENABLED`. |
| `route` | `mcp/statamic` | The endpoint path. |
| `auth` | `token` | `token` or `oauth`. Set with `STATAMIC_MCP_AUTH`. |
| `server` | `Danielgnh\StatamicMcp\Server` | The server class to mount. |
| `middleware` | `['throttle:60,1']` | Runs before authentication on the MCP route. |
| `read_only` | `false` | Hides every write and delete tool. Set with `STATAMIC_MCP_READ_ONLY`. |
| `deletes` | `false` | Registers the delete tools. Set with `STATAMIC_MCP_DELETES`. |
| `resources` | `true` for each type | One key each for `collections`, `taxonomies`, `globals`, `asset_containers`, and `navigations`. `true` exposes every handle. A list like `['blog', 'pages']` exposes only those. A type missing from a published config exposes nothing. |
| `per_page` | `25` | Default page size for list tools, capped at 100. |
| `uploads.max_size` | `10240` | Upload size limit in KB. |
| `uploads.source_allowlist` | `null` | Hosts `assets_upload` may download from. `null` allows any public host. Private addresses are always blocked. |
| `guidelines_path` | `resource_path('mcp/guidelines')` | Where the guideline files for agents live. |

## Troubleshooting

```bash
php please mcp:doctor
```

The doctor runs every check, even after one fails, and prints the fix for each problem. It exits non-zero on failure, so it also works as a deploy step. [docs/troubleshooting.md](docs/troubleshooting.md) explains each check and what a 401, 403, 404, or 503 from the endpoint means.

## Development

```bash
composer test     # Rector, Pint, PHPStan, and Pest
composer format   # Pint
```

## License

MIT. See [LICENSE.md](LICENSE.md).
